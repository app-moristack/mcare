<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    public function show(Request $request)
    {
        $enrolled = (bool) $request->user()->mfa_confirmed_at;

        return Inertia::render('security/mfa', ['enrolled' => $enrolled, 'secret' => $enrolled ? null : $request->session()->get('mfa_setup_secret')]);
    }

    public function begin(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        abort_if($request->user()->mfa_confirmed_at, 409, 'Already enrolled.');
        $request->session()->put('mfa_setup_secret', (new Google2FA)->generateSecretKey(32));

        return back();
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $secret = $user->mfa_confirmed_at ? $user->mfa_secret : $request->session()->get('mfa_setup_secret');
            $counter = $secret ? (new Google2FA)->verifyKeyNewer($secret, $data['code'], $user->mfa_last_counter ?? 0, 1) : false;
            if ($counter === false) {
                throw ValidationException::withMessages(['code' => 'Invalid or already used authenticator code.']);
            }
            $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => $user->mfa_confirmed_at ?? now(), 'mfa_last_counter' => $counter])->save();
            Workspace::audit($request, 'mfa.verified');
        });
        $request->session()->forget('mfa_setup_secret');
        $request->session()->regenerate();
        $request->session()->put(['mfa_user_id' => $request->user()->id, 'mfa_started_at' => now()->timestamp, 'workforce_last_seen' => now()->timestamp]);

        if ($request->user()->hasPermission('dashboard.view')) {
            return to_route('operations.index', ['module' => 'overview']);
        }

        return redirect($request->user()->account_kind === 'provider_application' ? '/application' : '/dashboard');
    }
}
