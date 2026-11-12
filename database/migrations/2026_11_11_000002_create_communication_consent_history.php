<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('scope', 40);
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->string('source', 40);
            $table->string('capture_actor_type', 20)->default('staff');
            $table->foreignId('captured_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('captured_by_patient_id')->nullable()->constrained('patient')->restrictOnDelete();
            $table->timestamp('captured_at', 6);
            $table->unsignedInteger('version');
            $table->string('text_version', 100);
            $table->text('acknowledged_text')->nullable();
            $table->text('evidence');
            $table->foreignId('legacy_preference_id')->nullable()->constrained('patient_messaging_preferences')->restrictOnDelete();
            $table->unique(['tenant_id', 'patient_id', 'purpose', 'scope', 'channel', 'version'], 'consent_version_unique');
        });
        Schema::create('communication_consent_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained('communication_consents')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('scope', 40);
            $table->string('channel', 20);
            $table->boolean('allowed');
            $table->string('reason', 40);
            $table->json('snapshot');
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', 6);
        });
        Schema::create('operational_notice_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('basis', 30);
            $table->text('reason');
            $table->text('authority_reference');
            $table->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('captured_at', 6);
        });
        // Import only evidence that exists; do not invent an acknowledged policy version.
        DB::table('patient_messaging_preferences')->orderBy('id')->eachById(function ($preference) {
            DB::table('communication_consents')->insert([
                'tenant_id' => $preference->tenant_id, 'patient_id' => $preference->patient_id,
                'purpose' => $preference->use_case === 'care' ? 'clinical' : 'transactional',
                'scope' => $preference->use_case === 'care' ? 'follow_up' : 'billing',
                'channel' => $preference->channel, 'status' => $preference->allowed ? 'granted' : 'declined',
                'source' => 'legacy_import', 'captured_by' => $preference->recorded_by,
                'captured_at' => $preference->updated_at, 'version' => 1, 'text_version' => 'legacy_unversioned',
                'acknowledged_text' => null, 'evidence' => $preference->evidence,
                'legacy_preference_id' => $preference->id,
            ]);
        });
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('consent_decision_id')->nullable();
            // SQLite cannot remove an added foreign key independently; the guards enforce this link too.
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('consent_decision_id')->references('id')->on('communication_consent_decisions')->restrictOnDelete();
            }
        });
        $patient = 'NOT EXISTS (SELECT 1 FROM patient WHERE id = NEW.patient_id AND tenant_id = NEW.tenant_id)';
        $actor = 'NOT EXISTS (SELECT 1 FROM tenant_user WHERE user_id = NEW.captured_by AND tenant_id = NEW.tenant_id)';
        $consentActor = "NOT ((NEW.capture_actor_type = 'staff' AND NEW.captured_by IS NOT NULL AND NEW.captured_by_patient_id IS NULL AND NOT ($actor)) OR (NEW.capture_actor_type = 'patient' AND NEW.captured_by IS NULL AND NEW.captured_by_patient_id IS NOT NULL AND NEW.captured_by_patient_id = NEW.patient_id AND NEW.source = 'patient_portal'))";
        $legacy = 'NEW.legacy_preference_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM patient_messaging_preferences WHERE id = NEW.legacy_preference_id AND patient_id = NEW.patient_id AND tenant_id = NEW.tenant_id)';
        MigrationGuard::create('consent_insert_guard', 'communication_consents', 'insert', "$patient OR $consentActor OR ($legacy) OR NEW.status NOT IN ('granted','declined','withdrawn','revoked') OR NEW.channel NOT IN ('in_app','email','sms') OR NEW.version < 1 OR NOT ((NEW.purpose = 'transactional' AND NEW.scope IN ('appointments','billing','payment_receipts')) OR (NEW.purpose = 'clinical' AND NEW.scope = 'follow_up') OR (NEW.purpose = 'general' AND NEW.scope = 'non_essential'))", 'Invalid consent record');
        MigrationGuard::create('consent_decision_insert', 'communication_consent_decisions', 'insert', "$patient OR (NEW.consent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM communication_consents WHERE id = NEW.consent_id AND tenant_id = NEW.tenant_id AND patient_id = NEW.patient_id AND purpose = NEW.purpose AND scope = NEW.scope AND channel = NEW.channel))", 'Invalid consent decision');
        MigrationGuard::create('notice_basis_insert', 'operational_notice_bases', 'insert', "$patient OR $actor OR NEW.basis NOT IN ('emergency','legal_obligation') OR NEW.channel NOT IN ('in_app','email','sms')", 'Invalid operational notice basis');
        MigrationGuard::create('delivery_consent_guard', 'notification_deliveries', 'update', 'NEW.consent_decision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM communication_consent_decisions WHERE id = NEW.consent_decision_id AND tenant_id = NEW.tenant_id AND resource_type = \'notification_deliveries\' AND resource_id = NEW.id)', 'Invalid delivery consent decision');
        MigrationGuard::create('delivery_consent_insert_guard', 'notification_deliveries', 'insert', 'NEW.consent_decision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM communication_consent_decisions WHERE id = NEW.consent_decision_id AND tenant_id = NEW.tenant_id AND resource_type = \'notification_deliveries\' AND resource_id = NEW.id)', 'Invalid delivery consent decision');
        foreach (['communication_consents', 'communication_consent_decisions', 'operational_notice_bases'] as $table) {
            foreach (['update', 'delete'] as $event) {
                MigrationGuard::create($table.'_'.$event, $table, $event, '1 = 1', 'Consent evidence is append-only');
            }
        }
        foreach (['insert', 'update', 'delete'] as $event) {
            MigrationGuard::create('legacy_preferences_'.$event, 'patient_messaging_preferences', $event, '1 = 1', 'Use append-only communication consent history');
        }
    }

    public function down(): void
    {
        foreach (['communication_consents', 'communication_consent_decisions', 'operational_notice_bases'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Export and back up consent evidence before rollback.');
            }
        }
        foreach (['insert', 'update', 'delete'] as $event) {
            MigrationGuard::drop('legacy_preferences_'.$event, 'patient_messaging_preferences');
        }
        MigrationGuard::drop('delivery_consent_guard', 'notification_deliveries');
        MigrationGuard::drop('delivery_consent_insert_guard', 'notification_deliveries');
        Schema::table('notification_deliveries', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['consent_decision_id']);
            }
            $table->dropColumn('consent_decision_id');
        });
        foreach (['communication_consents' => 'consent_insert_guard', 'communication_consent_decisions' => 'consent_decision_insert', 'operational_notice_bases' => 'notice_basis_insert'] as $table => $guard) {
            MigrationGuard::drop($guard, $table);
            foreach (['update', 'delete'] as $event) {
                MigrationGuard::drop($table.'_'.$event, $table);
            }
        }
        Schema::drop('operational_notice_bases');
        Schema::drop('communication_consent_decisions');
        Schema::drop('communication_consents');
    }
};
