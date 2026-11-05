<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'account_kind' => ['required', Rule::in(['self_patient', 'records_only', 'proxy', 'provider_application', 'partner_application'])],
            'preferred_language' => ['required', Rule::in(['en', 'fr', 'mfe'])], 'terms_acceptance' => ['accepted'], 'privacy_acknowledgement' => ['accepted'],
        ]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $user->forceFill(['account_kind' => $data['account_kind'], 'preferred_language' => $data['preferred_language'],
                'terms_version' => config('mauricare.terms_version'), 'terms_accepted_at' => now(),
                'privacy_notice_version' => config('mauricare.privacy_notice_version'), 'privacy_acknowledged_at' => now()])->save();
            if (in_array($data['account_kind'], ['self_patient', 'records_only'])) {
                $user->patientProfile()->create(['public_id' => (string) Str::uuid()]);
            } elseif (in_array($data['account_kind'], ['provider_application', 'partner_application'])) {
                $user->application()->create(['kind' => $data['account_kind']]);
            }
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'event' => 'account.registered', 'created_at' => now()]);

            return $user;
        });
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
