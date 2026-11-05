<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireWorkforceMfa
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('mauricare.workforce_mfa_required')) {
            return $next($request);
        }
        if (! $request->user()->mfa_confirmed_at || (int) $request->session()->get('mfa_user_id') !== $request->user()->id) {
            return redirect()->route('security.mfa');
        }
        $now = now()->timestamp;
        if ($now - (int) $request->session()->get('workforce_last_seen', $now) > 900 || $now - (int) $request->session()->get('mfa_started_at', $now) > 43200) {
            $request->session()->forget(['mfa_user_id', 'workforce_last_seen', 'mfa_started_at']);

            return redirect()->route('security.mfa');
        }
        $request->session()->put('workforce_last_seen', $now);

        return $next($request);
    }
}
