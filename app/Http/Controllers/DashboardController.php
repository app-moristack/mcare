<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $application = $request->user()->application()->first();
        $adminAccess = $request->user()->hasPermission('dashboard.view') ? ['roles' => $request->user()->memberships()->where('status', 'active')->pluck('role')->unique()->values(), 'permissions' => config('permissions.abilities')] : null;
        $accountExperience = $adminAccess ? 'administration' : match ($application?->status) {
            'approved' => 'professional_approved',
            'rejected' => 'application_rejected',
            default => $application ? 'application_pending' : 'personal',
        };

        return Inertia::render('dashboard', [
            'patientProfile' => $request->user()->patientProfile,
            'application' => $application,
            'adminAccess' => $adminAccess,
            'accountExperience' => $accountExperience,
        ]);
    }
}
