<?php

namespace Tests\Feature;

use App\Models\ClinicNotification;
use App\Models\CommunicationConfiguration;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientEngagementMigrationTest extends TestCase
{
    public function test_upgrade_preserves_billing_outbox_encryption_keys_and_tenant_guards(): void
    {
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('SQLite table rebuild preservation check. MySQL uses an in-place column alteration.');
        }
        $original = DB::getDefaultConnection();
        config(['database.connections.engagement_upgrade' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
        DB::setDefaultConnection('engagement_upgrade');
        try {
            $paths = collect(glob(database_path('migrations/*.php')))->reject(fn ($path) => str_contains($path, '2026_10_31_000001'))
                ->map(fn ($path) => 'database/migrations/'.basename($path))->values()->all();
            $this->artisan('migrate', ['--database' => 'engagement_upgrade', '--path' => $paths, '--force' => true])->assertSuccessful();
            $configuration = CommunicationConfiguration::create(['enabled' => true, 'revision' => 1, 'settings' => ['encrypted' => 'secret']]);
            $invoice = Invoice::factory()->create();
            $notificationId = DB::table('clinic_notifications')->insertGetId(['tenant_id' => app(TenantContext::class)->id(), 'configuration_id' => $configuration->id, 'configuration_revision' => 1,
                'invoice_id' => $invoice->id, 'event' => 'payment.recorded', 'event_key' => 'payment.recorded:legacy',
                'user_triggered' => false, 'trigger' => 'payment_provider', 'variables' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['patient_name' => 'Encrypted Patient'])), 'selection_status' => 'queued']);
            $notification = ClinicNotification::findOrFail($notificationId);
            $delivery = $notification->deliveries()->create(['channel' => 'email', 'recipient_kind' => 'patient',
                'address' => 'private@example.test', 'recipient_hash' => str_repeat('a', 64), 'operation_key' => (string) Str::uuid(), 'available_at' => now()]);
            $encrypted = DB::table('clinic_notifications')->where('id', $notification->id)->value('variables');
            $triggerCount = count(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'"));
            (require database_path('migrations/2026_10_31_000001_add_patient_engagement.php'))->up();
            $this->assertSame($encrypted, DB::table('clinic_notifications')->where('id', $notification->id)->value('variables'));
            $this->assertSame(['patient_name' => 'Encrypted Patient'], $notification->refresh()->variables);
            $this->assertSame($invoice->patient_id, $notification->patient_id);
            $this->assertSame($delivery->operation_key, NotificationDelivery::firstOrFail()->operation_key);
            $this->assertSame('private@example.test', NotificationDelivery::firstOrFail()->address);
            $this->assertSame($triggerCount + 2, count(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'")));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
            $this->expectException(\Illuminate\Database\QueryException::class);
            DB::table('notification_deliveries')->where('id', $delivery->id)->update(['tenant_id' => app(TenantContext::class)->id() + 999]);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('engagement_upgrade');
        }
    }
}
