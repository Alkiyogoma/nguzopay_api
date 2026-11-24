<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Console\Command;

class MakePlatformAdminCommand extends Command
{
    protected $signature = 'nguzopay:admin {email : Email of an existing user} {--revoke : Remove admin rights}';

    protected $description = 'Grant or revoke NGUZO back-office (platform admin) access';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No user with that email. They must sign up first.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_platform_admin' => $grant])->save();
        $audit->record($grant ? 'platform.admin_granted' : 'platform.admin_revoked', $user, ['via' => 'console']);

        $this->info(($grant ? 'Granted' : 'Revoked')." platform admin for {$user->email}.");

        return self::SUCCESS;
    }
}
