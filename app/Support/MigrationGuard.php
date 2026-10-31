<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** SQL-only migration support: keep independent of application models. */
final class MigrationGuard
{
    public static function create(string $name, string $table, string $event, string $condition, string $message): void
    {
        $event = strtoupper($event);
        $message = str_replace("'", "''", $message);

        switch (DB::getDriverName()) {
            case 'sqlite':
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW WHEN $condition BEGIN SELECT RAISE(ABORT, '$message'); END");
                break;
            case 'mysql':
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF; END");
                break;
            case 'pgsql':
                $function = self::functionName($name);
                $row = $event === 'DELETE' ? 'OLD' : 'NEW';
                DB::unprepared("CREATE OR REPLACE FUNCTION $function() RETURNS trigger LANGUAGE plpgsql AS \$guard\$ BEGIN IF $condition THEN RAISE EXCEPTION USING MESSAGE = '$message', ERRCODE = '23514'; END IF; RETURN $row; END; \$guard\$");
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW EXECUTE FUNCTION $function()");
                break;
            default:
                throw new RuntimeException('Unsupported migration guard driver: '.DB::getDriverName());
        }
    }

    public static function drop(string $name, string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DROP TRIGGER IF EXISTS $name ON $table");
            DB::unprepared('DROP FUNCTION IF EXISTS '.self::functionName($name).'()');
        } else {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }
    }

    private static function functionName(string $name): string
    {
        // PostgreSQL identifiers are limited to 63 bytes.
        return 'guard_'.sha1($name);
    }
}
