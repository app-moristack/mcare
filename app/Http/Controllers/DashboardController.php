<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', ['patientProfile' => $request->user()->patientProfile, 'application' => $request->user()->application, 'adminAccess' => $request->user()->hasPermission('dashboard.view') ? ['roles' => $request->user()->memberships()->where('status', 'active')->pluck('role')->unique()->values(), 'permissions' => config('permissions.abilities')] : null]);
    }
}
