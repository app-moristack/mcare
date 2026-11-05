<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $organisation = Organisation::firstOrCreate(['name' => 'MauriCare']);
            $user = User::firstOrNew(['email' => 'admin@mauricare.mu']);
            $user->forceFill([
                'name' => 'MauriCare Administrator',
                'password' => Hash::make('P@ssw0rd'),
                'account_kind' => 'admin',
                'account_status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now(),
                'preferred_language' => 'en',
                'time_zone' => 'Indian/Mauritius',
            ])->save();

            foreach (config('permissions.roles') as $role) {
                $user->memberships()->updateOrCreate(
                    ['organisation_id' => $organisation->id, 'role' => $role],
                    ['status' => 'active', 'approved_by' => $user->id, 'starts_at' => now(), 'ends_at' => null],
                );
            }

            DB::table('audit_events')->insert([
                'actor_id' => $user->id,
                'event' => 'admin.seeded',
                'created_at' => now(),
            ]);
        });
    }
}
