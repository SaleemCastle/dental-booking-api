<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PlatformOperator extends Command
{
    protected $signature = 'platform:operator {email : Existing operator identity} {--revoke : Remove platform access}';

    protected $description = 'Grant or revoke platform access using trusted deployment access only';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Create the operator identity before granting platform access.');

            return self::FAILURE;
        }
        $user->forceFill(['is_platform_operator' => ! $this->option('revoke')])->save();
        $this->info($this->option('revoke') ? 'Platform access revoked.' : 'Platform access granted.');

        return self::SUCCESS;
    }
}
