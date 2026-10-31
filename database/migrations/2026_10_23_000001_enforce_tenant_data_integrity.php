<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned tables and the relationships that must remain
     * within the same tenant.
     */
    private array $relationships = [
        'roles' => [],
        'patient' => [],
        'dentist' => [],
        'treatments' => [],
        'rooms' => [],
        'chairs' => [
            'room_id' => 'rooms',
        ],
        'appointment' => [
            'patient_id' => 'patient',
            'dentist_id' => 'dentist',
            'treatment_id' => 'treatments',
            'room_id' => 'rooms',
            'chair_id' => 'chairs',
        ],
        'invoices' => [
            'patient_id' => 'patient',
            'appointment_id' => 'appointment',
        ],
        'payments' => [
            'invoice_id' => 'invoices',
        ],
        'messages' => [
            'sender_id' => 'patient',
            'receiver_id' => 'patient',
        ],
        'referral' => [
            'patient_id' => 'patient',
            'dentist_id' => 'dentist',
        ],
        'clinical_notes' => [
            'patient_id' => 'patient',
            'dentist_id' => 'dentist',
            'appointment_id' => 'appointment',
        ],
        'tasks' => [
            'patient_id' => 'patient',
            'appointment_id' => 'appointment',
            'assignee_id' => 'tenant_user',
        ],
        'audit_logs' => [],
    ];

    /**
     * Indexes used by tenant-scoped application queries.
     */
    private array $indexes = [
        'patient' => [
            ['tenant_id', 'archived_at', 'id'],
        ],
        'dentist' => [
            ['tenant_id', 'email'],
        ],
        'treatments' => [
            ['tenant_id', 'active', 'name'],
        ],
        'appointment' => [
            ['tenant_id', 'appointment_date_time', 'id'],
            ['tenant_id', 'dentist_id', 'appointment_date_time'],
            ['tenant_id', 'room_id', 'appointment_date_time'],
            ['tenant_id', 'chair_id', 'appointment_date_time'],
        ],
        'invoices' => [
            ['tenant_id', 'status'],
        ],
        'clinical_notes' => [
            ['tenant_id', 'patient_id', 'created_at'],
        ],
        'tasks' => [
            ['tenant_id', 'status', 'due_date'],
            ['tenant_id', 'assignee_id', 'due_date'],
            ['tenant_id', 'patient_id', 'due_date'],
        ],
        'audit_logs' => [
            ['tenant_id', 'occurred_at', 'id'],
            ['tenant_id', 'actor_id', 'occurred_at'],
            ['tenant_id', 'resource_type', 'resource_id', 'occurred_at'],
        ],
    ];

    /**
     * Phase 1 foreignIdFor() columns that did not create
     * the required foreign-key constraints.
     */
    private array $legacyForeignKeys = [
        'appointment' => [
            'patient_id' => 'patient',
            'dentist_id' => 'dentist',
        ],
        'referral' => [
            'patient_id' => 'patient',
            'dentist_id' => 'dentist',
        ],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Tenant integrity migration does not support database driver: {$driver}."
            );
        }

        /*
         * Validate existing data before changing the schema.
         *
         * We intentionally refuse to silently repair inconsistent
         * clinical or tenant relationships.
         */
        $this->validateExistingTenantRelationships();

        /*
         * Add indexes used by tenant-scoped queries.
         */
        $this->createTenantIndexes();

        /*
         * Protect tenant ownership and tenant-scoped relationships.
         */
        $this->createTenantIntegrityGuards();

        /*
         * MySQL and PostgreSQL can enforce these relationships using
         * actual foreign keys. SQLite requires trigger-based protection
         * because ALTER TABLE support is more limited.
         */
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->createRelationalForeignKeys();

            return;
        }

        $this->createSqliteRelationshipGuards();
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Tenant integrity rollback does not support database driver: {$driver}."
            );
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->dropRelationalForeignKeys();
        } else {
            $this->dropSqliteRelationshipGuards();
        }

        $this->dropTenantIntegrityGuards();

        $this->dropTenantIndexes();
    }

    private function validateExistingTenantRelationships(): void
    {
        foreach ($this->relationships as $tableName => $relationships) {
            $hasInvalidRelationship = DB::table($tableName.' as child')
                ->whereNotExists(
                    fn ($query) => $query
                        ->selectRaw('1')
                        ->from('tenants')
                        ->whereColumn('tenants.id', 'child.tenant_id')
                )
                ->exists();

            foreach ($relationships as $columnName => $parentTable) {
                $parentKey = $parentTable === 'tenant_user'
                    ? 'user_id'
                    : 'id';

                $hasInvalidRelationship = $hasInvalidRelationship
                    || DB::table($tableName.' as child')
                        ->whereNotNull('child.'.$columnName)
                        ->whereNotExists(
                            fn ($query) => $query
                                ->selectRaw('1')
                                ->from($parentTable.' as parent')
                                ->whereColumn(
                                    'parent.'.$parentKey,
                                    'child.'.$columnName
                                )
                                ->whereColumn(
                                    'parent.tenant_id',
                                    'child.tenant_id'
                                )
                        )
                        ->exists();
            }

            if ($hasInvalidRelationship) {
                throw new RuntimeException(
                    "Tenant integrity preflight failed for {$tableName}. "
                    .'Restore or reconcile the records before retrying; '
                    .'no data was changed.'
                );
            }
        }
    }

    private function createTenantIndexes(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table(
                    $tableName,
                    fn (Blueprint $table) => $table->index($columns)
                );
            }
        }
    }

    private function createTenantIntegrityGuards(): void
    {
        foreach ($this->relationships as $tableName => $relationships) {
            $checks = [
                'NEW.tenant_id IS NULL OR NOT EXISTS '
                .'(SELECT 1 FROM tenants '
                .'WHERE id = NEW.tenant_id)',
            ];

            foreach ($relationships as $columnName => $parentTable) {
                $parentKey = $parentTable === 'tenant_user'
                    ? 'user_id'
                    : 'id';

                $checks[] = sprintf(
                    'NEW.%1$s IS NOT NULL AND NOT EXISTS '
                    .'(SELECT 1 FROM %2$s '
                    .'WHERE %3$s = NEW.%1$s '
                    .'AND tenant_id = NEW.tenant_id)',
                    $columnName,
                    $parentTable,
                    $parentKey
                );
            }

            $insertChecks = $checks;

            $updateChecks = [
                'NEW.tenant_id <> OLD.tenant_id',
                ...$checks,
            ];

            $attributionColumns = match ($tableName) {
                'clinical_notes' => [
                    'author_user_id',
                    'updated_by_user_id',
                    'deleted_by_user_id',
                ],
                'patient' => [
                    'archived_by_user_id',
                ],
                default => [],
            };

            foreach ($attributionColumns as $columnName) {
                $invalidAttribution = sprintf(
                    'NEW.%1$s IS NOT NULL AND NOT EXISTS '
                    .'(SELECT 1 FROM tenant_user '
                    .'WHERE user_id = NEW.%1$s '
                    .'AND tenant_id = NEW.tenant_id)',
                    $columnName
                );

                $insertChecks[] = $invalidAttribution;

                /*
                 * Historical attribution survives membership removal.
                 * Only a newly assigned/changed attribution must point
                 * at a current member of the same tenant.
                 */
                $updateChecks[] = sprintf(
                    '(OLD.%1$s IS NULL OR NEW.%1$s <> OLD.%1$s) '
                    .'AND (%2$s)',
                    $columnName,
                    $invalidAttribution
                );
            }

            $this->createGuard(
                $tableName,
                'insert',
                $insertChecks
            );

            $this->createGuard(
                $tableName,
                'update',
                $updateChecks
            );
        }
    }

    private function createRelationalForeignKeys(): void
    {
        foreach ($this->legacyForeignKeys as $tableName => $relationships) {
            foreach ($relationships as $columnName => $parentTable) {
                Schema::table(
                    $tableName,
                    function (Blueprint $table) use (
                        $tableName,
                        $columnName,
                        $parentTable
                    ): void {
                        $table
                            ->foreign(
                                $columnName,
                                "{$tableName}_{$columnName}_integrity_foreign"
                            )
                            ->references('id')
                            ->on($parentTable)
                            ->restrictOnDelete();
                    }
                );
            }
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table
                ->foreign(
                    ['tenant_id', 'assignee_id'],
                    'tasks_tenant_assignee_foreign'
                )
                ->references(['tenant_id', 'user_id'])
                ->on('tenant_user')
                ->restrictOnDelete();
        });
    }

    private function createSqliteRelationshipGuards(): void
    {
        $assignedTasks = 'EXISTS ('
            .'SELECT 1 FROM tasks '
            .'WHERE tenant_id = OLD.tenant_id '
            .'AND assignee_id = OLD.user_id'
            .')';

        $this->createGuard(
            'tenant_user',
            'delete',
            [$assignedTasks]
        );

        $this->createGuard(
            'tenant_user',
            'update',
            [
                '(NEW.tenant_id <> OLD.tenant_id '
                .'OR NEW.user_id <> OLD.user_id) '
                ."AND {$assignedTasks}",
            ]
        );

        $ownedRelationships = array_map(
            fn (string $tableName) => sprintf(
                'EXISTS (SELECT 1 FROM %s WHERE tenant_id = OLD.id)',
                $tableName
            ),
            array_keys($this->relationships)
        );

        $this->createGuard(
            'tenants',
            'delete',
            $ownedRelationships
        );

        $this->createGuard(
            'tenants',
            'update',
            [
                'NEW.id <> OLD.id AND ('
                .implode(' OR ', $ownedRelationships)
                .')',
            ]
        );

        foreach (
            [
                'patient' => 'patient_id',
                'dentist' => 'dentist_id',
            ] as $parentTable => $columnName
        ) {
            $linkedRecords = sprintf(
                'EXISTS (SELECT 1 FROM appointment WHERE %1$s = OLD.id) '
                .'OR EXISTS (SELECT 1 FROM referral WHERE %1$s = OLD.id)',
                $columnName
            );

            $this->createGuard(
                $parentTable,
                'delete',
                [$linkedRecords]
            );

            $triggerName =
                "tenant_integrity_{$parentTable}_key_update";

            DB::unprepared(
                "CREATE TRIGGER \"$triggerName\"
                 BEFORE UPDATE ON \"$parentTable\"
                 FOR EACH ROW
                 WHEN NEW.id <> OLD.id AND ($linkedRecords)
                 BEGIN
                     SELECT RAISE(
                         ABORT,
                         'Tenant integrity violation'
                     );
                 END"
            );
        }
    }

    private function dropRelationalForeignKeys(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign('tasks_tenant_assignee_foreign');
        });

        foreach ($this->legacyForeignKeys as $tableName => $relationships) {
            foreach ($relationships as $columnName => $parentTable) {
                Schema::table(
                    $tableName,
                    fn (Blueprint $table) => $table->dropForeign(
                        "{$tableName}_{$columnName}_integrity_foreign"
                    )
                );
            }
        }
    }

    private function dropSqliteRelationshipGuards(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS "tenant_integrity_tenant_user_delete"'
        );

        DB::unprepared(
            'DROP TRIGGER IF EXISTS "tenant_integrity_tenant_user_update"'
        );

        DB::unprepared(
            'DROP TRIGGER IF EXISTS "tenant_integrity_tenants_delete"'
        );

        DB::unprepared(
            'DROP TRIGGER IF EXISTS "tenant_integrity_tenants_update"'
        );

        foreach (['patient', 'dentist'] as $parentTable) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                ."\"tenant_integrity_{$parentTable}_delete\""
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                ."\"tenant_integrity_{$parentTable}_key_update\""
            );
        }
    }

    private function dropTenantIntegrityGuards(): void
    {
        foreach ($this->relationships as $tableName => $relationships) {
            foreach (['insert', 'update'] as $event) {
                MigrationGuard::drop("tenant_integrity_{$tableName}_{$event}", $tableName);
            }
        }
    }

    private function dropTenantIndexes(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table(
                    $tableName,
                    fn (Blueprint $table) => $table->dropIndex($columns)
                );
            }
        }
    }

    private function createGuard(
        string $tableName,
        string $event,
        array $checks
    ): void {
        MigrationGuard::create(
            "tenant_integrity_{$tableName}_{$event}",
            $tableName,
            $event,
            implode(' OR ', array_map(fn (string $check) => "($check)", $checks)),
            'Tenant integrity violation'
        );
    }
};
