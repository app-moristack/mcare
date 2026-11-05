<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_verified_admin_with_all_roles_and_permissions(): void
    {
        $this->seed(AdminUserSeeder::class);
        $user = User::where('email', 'admin@mauricare.mu')->firstOrFail();
        $this->assertTrue(Hash::check('P@ssw0rd', $user->password));
        $this->assertNotSame('P@ssw0rd', $user->password);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('admin', $user->account_kind);
        $this->assertEqualsCanonicalizing(config('permissions.roles'), $user->memberships()->pluck('role')->all());
        $organisation = Organisation::where('name', 'MauriCare')->firstOrFail();
        foreach (config('permissions.abilities') as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $organisation->id));
            $this->assertFalse(Gate::forUser($user)->allows($ability, $organisation->id + 1));
        }
        $this->assertFalse($user->hasPermission('unknown.permission'));
        $this->post('/login', ['email' => $user->email, 'password' => 'P@ssw0rd'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard', false)->has('adminAccess.roles', count(config('permissions.roles'))));
    }

    public function test_reseeding_is_idempotent_and_restores_the_requested_password(): void
    {
        $this->seed(AdminUserSeeder::class);
        User::first()->forceFill(['password' => 'a different password'])->save();
        $this->seed(AdminUserSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organisations', 1);
        $this->assertDatabaseCount('memberships', count(config('permissions.roles')));
        $this->assertTrue(Hash::check('P@ssw0rd', User::first()->password));
    }

    public function test_unprivileged_and_inactive_accounts_are_denied(): void
    {
        $ordinary = User::factory()->create();
        $this->assertFalse($ordinary->hasPermission('dashboard.view'));
        $this->seed(AdminUserSeeder::class);
        $admin = User::where('email', 'admin@mauricare.mu')->firstOrFail();
        $admin->memberships()->update(['ends_at' => now()->subMinute()]);
        $this->assertFalse($admin->hasPermission('dashboard.view'));
        $admin->memberships()->update(['ends_at' => null, 'status' => 'suspended']);
        $this->assertFalse($admin->hasPermission('dashboard.view'));
        $admin->memberships()->update(['status' => 'active']);
        $admin->forceFill(['account_status' => 'disabled'])->save();
        $this->assertFalse($admin->hasPermission('dashboard.view'));
    }
}
