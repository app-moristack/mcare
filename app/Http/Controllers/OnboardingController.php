<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->patientProfile, 403);

        return Inertia::render('onboarding', ['profile' => $request->user()->patientProfile]);
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = $request->user()->patientProfile;
        abort_unless($profile, 403);
        $phone = preg_replace('/[\s().-]+/', '', (string) $request->input('mobile_phone', ''));
        if (preg_match('/^[0-9]{8}$/', $phone)) {
            $phone = '+230'.$phone;
        }
        $request->merge(['mobile_phone' => $phone ?: null]);
        $data = $request->validate(['legal_name' => ['required', 'string', 'max:255'], 'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mobile_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'locality' => ['required', 'string', 'max:255']], [
                'mobile_phone.regex' => 'Enter an 8-digit Mauritius number or an international number beginning with +.',
            ]);
        $profile->update([...$data, 'onboarding_completed_at' => now()]);

        return to_route('dashboard');
    }
}
