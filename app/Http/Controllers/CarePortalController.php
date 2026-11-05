<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Patient;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CarePortalController extends Controller
{
    public function index(Request $r)
    {
        $patients = Patient::where('user_id', $r->user()->id)->get(['id', 'reference', 'organisation_id', 'status']);
        $ids = $patients->pluck('id');

        return Inertia::render('care/portal', [
            'organisations' => Organisation::orderBy('name')->get(['id', 'name']), 'patients' => $patients,
            'appointments' => Appointment::whereIn('patient_id', $ids)->select('id', 'provider_id', 'service', 'starts_at', 'ends_at', 'location', 'status')->with('provider:id,name')->orderByDesc('starts_at')->paginate(10),
            'invoices' => Invoice::whereIn('patient_id', $ids)->whereNotIn('status', ['draft', 'void'])->with(['items', 'payments' => fn ($q) => $q->select('id', 'invoice_id', 'amount_minor', 'received_at')])->latest()->get(['id', 'patient_id', 'number', 'status', 'currency', 'due_date', 'total_minor']),
        ]);
    }

    public function requestCare(Request $r)
    {
        $data = $r->validate(['organisation_id' => 'required|integer|exists:organisations,id', 'authority_acceptance' => 'accepted']);
        $profile = $r->user()->patientProfile;
        abort_unless($profile && $profile->onboarding_completed_at, 422, 'Complete your basic profile first.');
        DB::transaction(function () use ($r, $data, $profile) {
            $patient = Patient::firstOrCreate(['organisation_id' => $data['organisation_id'], 'user_id' => $r->user()->id], ['reference' => (string) Str::uuid(), 'name' => $profile->legal_name, 'date_of_birth' => $profile->date_of_birth, 'phone' => $profile->mobile_phone, 'email' => $r->user()->email, 'locality' => $profile->locality, 'authority_type' => 'patient_request', 'authority_evidence' => 'Patient requested care coordination through own verified account. Notice: '.config('mauricare.privacy_notice_version'), 'request_id' => (string) Str::uuid()]);
            Workspace::audit($r, 'patient.care_requested', $data['organisation_id'], 'patients', $patient->id);
        });

        return back()->with('success', 'Your care request is available to the selected organisation’s coordination team.');
    }

    public function application(Request $r)
    {
        abort_unless(in_array($r->user()->account_kind, ['provider_application', 'partner_application']), 403);

        return Inertia::render('care/application', ['application' => $r->user()->application, 'organisations' => Organisation::orderBy('name')->get(['id', 'name'])]);
    }

    public function submitApplication(Request $r)
    {
        abort_unless($r->user()->account_kind === 'provider_application', 403);
        $data = $r->validate(['organisation_id' => 'required|integer|exists:organisations,id', 'discipline' => 'required|in:doctor,nurse,carer,physiotherapist', 'credential_reference' => 'required|string|max:255', 'credential_expires_at' => 'required|date_format:Y-m-d|after_or_equal:today']);
        DB::transaction(function () use ($r, $data) {
            $app = $r->user()->application()->lockForUpdate()->firstOrFail();
            abort_unless($app->status === 'pending' && ! $app->reviewed_by, 409, 'This application has already been reviewed.');
            $app->update($data);
            Workspace::audit($r, 'application.submitted', $data['organisation_id'], 'applications', $app->id);
        });

        return back()->with('success', 'Application submitted for credential review.');
    }

    public function context(Request $r)
    {
        $data = $r->validate(['organisation_id' => 'required|integer']);
        abort_unless(Workspace::memberships($r->user())->contains('organisation_id', (int) $data['organisation_id']), 403);
        $r->session()->put('organisation_id', (int) $data['organisation_id']);
        Workspace::audit($r, 'context.selected', (int) $data['organisation_id']);

        return to_route('dashboard');
    }
}
