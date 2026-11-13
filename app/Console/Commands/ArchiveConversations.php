<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\CommunicationGovernanceService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ArchiveConversations extends Command
{
    protected $signature = 'communications:archive';

    protected $description = 'Archive inactive conversations under each active clinic policy without deleting history';

    public function handle(TenantContext $context, CommunicationGovernanceService $service): int
    {
        Tenant::where('active', true)->orderBy('id')->eachById(function ($tenant) use ($context, $service) {
            $context->run($tenant, fn () => $service->archiveDue());
        });

        return self::SUCCESS;
    }
}
