<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Services\NotificationDeliveryService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class DeliverCommunications extends Command
{
    protected $signature = 'communications:work {--limit=100 : Maximum deliveries per clinic}';

    protected $description = 'Deliver due clinic outbox messages with tenant isolation and bounded retries';

    public function handle(TenantContext $context, NotificationDeliveryService $worker): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        // Explicitly trusted orchestration; never take a clinic from an unauthenticated request.
        Tenant::where('active', true)->orderBy('id')->eachById(function ($tenant) use ($context, $worker, $limit) {
            $ids = $context->run($tenant, function () use ($worker, $limit) {
                $worker->recoverStale();

                return NotificationDelivery::whereIn('status', ['pending', 'retry'])->where('available_at', '<=', now('UTC'))
                    ->orderBy('id')->limit($limit)->pluck('id');
            });
            foreach ($ids as $id) {
                $worker->deliver($tenant->id, $id);
            }
            $statusIds = $context->run($tenant, fn () => NotificationDelivery::where('status', 'accepted')->where('channel', 'sms')
                ->where('available_at', '<=', now('UTC'))->orderBy('available_at')->limit($limit)->pluck('id'));
            foreach ($statusIds as $id) {
                $worker->refreshStatus($tenant->id, $id);
            }
        });

        return self::SUCCESS;
    }
}
