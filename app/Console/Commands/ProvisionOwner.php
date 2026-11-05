<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProvisionOwner extends Command
{
    protected $signature = 'mauricare:provision-owner {email : Existing verified account} {organisation : Organisation name}';

    protected $description = 'Provision a pending owner membership out of band; does not enable workforce access';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();
        if (! $user || ! $user->hasVerifiedEmail() || $user->account_status !== 'active') {
            $this->error('An active, verified account is required.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user) {
            $organisation = Organisation::firstOrCreate(['name' => $this->argument('organisation')]);
            $user->memberships()->firstOrCreate(['organisation_id' => $organisation->id, 'role' => 'owner'], ['status' => 'pending']);
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'event' => 'owner.provisioned_pending', 'created_at' => now()]);
        });
        $this->info('Pending owner membership provisioned. Workforce activation remains blocked until MFA and approval workflows are implemented.');

        return self::SUCCESS;
    }
}
