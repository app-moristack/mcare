<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustProxies::at(config('app.trusted_proxies'));
        foreach (config('permissions.abilities') as $ability) {
            Gate::define($ability, fn (User $user, ?int $organisationId = null) => $user->hasPermission($ability, $organisationId));
        }
        Password::defaults(fn () => Password::min(15)->max(128));
    }
}
