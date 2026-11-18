<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CareTask;
use App\Models\MissedVisitCase;
use App\Models\ProviderRosterEntry;
use App\Services\ProviderScheduling;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RosterController extends Controller
{
    private const CARE_ROLES = ['doctor', 'nurse', 'carer', 'physiotherapist'];

    private function providerOrganisation(Request $request): int
    {
        $organisationId = Workspace::selectedOrganisation($request);
        abort_unless(Workspace::memberships($request->user())->where('organisation_id', $organisationId)->whereIn('role', self::CARE_ROLES)->isNotEmpty(), 403, 'An active care-provider membership is required.');

        return $organisationId;
    }

    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('mauricare.time_zone'))->utc();
    }

    public function own(Request $request)
    {
        $organisationId = $this->providerOrganisation($request);
        $entries = ProviderRosterEntry::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)
            ->with('reviewer:id,name')->where('ends_at', '>=', now()->subMonth())->orderBy('starts_at')->get();
        $visits = Appointment::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)
            ->where('ends_at', '>=', now()->subDay())->with('patient:id,name,reference')->orderBy('starts_at')->get();
        Workspace::audit($request, 'roster.own.viewed', $organisationId);

        return Inertia::render('roster/index', ['mode' => 'provider', 'entries' => $entries, 'visits' => $visits, 'cases' => [], 'missableVisits' => [], 'providers' => [], 'staff' => []]);
    }

    public function workspace(Request $request)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $providerIds = DB::table('memberships')->where('organisation_id', $organisationId)->where('status', 'active')->whereIn('role', self::CARE_ROLES)->pluck('user_id');
        $entries = ProviderRosterEntry::where('organisation_id', $organisationId)->where('ends_at', '>=', now()->subMonth())
            ->with(['provider:id,name', 'reviewer:id,name'])->orderBy('starts_at')->get();
        $cases = MissedVisitCase::where('organisation_id', $organisationId)->with(['patient:id,name,reference', 'appointment:id,starts_at,service,provider_id', 'replacement:id,starts_at,status,offer_status', 'owner:id,name', 'backup:id,name'])->latest()->get();
        $missableVisits = Appointment::where('organisation_id', $organisationId)->where('status', 'confirmed')->where('ends_at', '<', now())
            ->whereDoesntHave('attendance')->with(['patient:id,name,reference', 'provider:id,name'])->orderByDesc('starts_at')->limit(100)->get();
        Workspace::audit($request, 'roster.workspace.viewed', $organisationId);

        return Inertia::render('roster/index', [
            'mode' => 'workspace', 'entries' => $entries, 'visits' => [], 'cases' => $cases, 'missableVisits' => $missableVisits,
            'providers' => Workspace::staff($organisationId)->whereIn('id', $providerIds)->orderBy('name')->get(['id', 'name']),
            'staff' => Workspace::staff($organisationId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $organisationId = $this->providerOrganisation($request);
        $data = $request->validate([
            'request_id' => 'required|uuid', 'kind' => ['required', Rule::in(['availability', 'leave', 'travel'])],
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'locality' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000', 'declaration' => 'accepted',
        ]);
        $start = $this->time($data['starts_at']);
        $end = $this->time($data['ends_at']);
        if ($end->isPast() || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['ends_at' => 'Roster periods must end in the future and cannot exceed 366 days.']);
        }
        if ($data['kind'] !== 'availability' && blank($data['notes'] ?? null)) {
            throw ValidationException::withMessages(['notes' => 'Record the minimum necessary leave or travel context.']);
        }
        DB::transaction(function () use ($request, $organisationId, $data, $start, $end) {
            if ($existing = ProviderRosterEntry::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->organisation_id === $organisationId && $existing->provider_id === $request->user()->id && $existing->kind === $data['kind'] && $existing->starts_at->equalTo($start) && $existing->ends_at->equalTo($end) && $existing->locality === ($data['locality'] ?? null) && $existing->notes === ($data['notes'] ?? null), 409, 'This request identifier was already used for different roster content.');

                return;
            }
            abort_if(ProviderRosterEntry::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)->where('kind', $data['kind'])->whereIn('status', ['pending', 'approved'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->exists(), 409, 'An active roster entry of this type overlaps this period.');
            $entry = ProviderRosterEntry::create([
                'organisation_id' => $organisationId, 'provider_id' => $request->user()->id, 'kind' => $data['kind'],
                'starts_at' => $start, 'ends_at' => $end, 'time_zone' => config('mauricare.time_zone'), 'locality' => $data['locality'] ?? null,
                'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->id, 'request_id' => $data['request_id'],
            ]);
            Workspace::audit($request, 'roster.entry.submitted', $organisationId, 'provider_roster_entries', $entry->id);
        });

        return back()->with('success', 'Roster period submitted for independent operational review.');
    }

    public function cancel(Request $request, int $entryId)
    {
        $organisationId = $this->providerOrganisation($request);
        $data = $request->validate(['version' => 'required|integer', 'reason' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $organisationId, $entryId, $data) {
            $entry = ProviderRosterEntry::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)->lockForUpdate()->findOrFail($entryId);
            abort_unless(in_array($entry->status, ['pending', 'approved']) && $entry->ends_at->isFuture() && $entry->version === (int) $data['version'], 409, 'This roster period can no longer be cancelled from its current state.');
            if ($entry->status === 'approved' && $entry->kind === 'availability') {
                abort_if(Appointment::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)->whereIn('status', ['booked', 'confirmed'])->where('starts_at', '<', $entry->ends_at)->where('ends_at', '>', $entry->starts_at)->exists(), 409, 'Approved availability with active visits requires operational reassignment before cancellation.');
            }
            $entry->update(['status' => 'cancelled', 'cancellation_reason' => $data['reason'], 'cancelled_at' => now(), 'version' => $entry->version + 1]);
            Workspace::audit($request, 'roster.entry.cancelled', $organisationId, 'provider_roster_entries', $entry->id);
        });

        return back()->with('success', 'Roster period cancelled. Existing visits still require explicit operational reassignment.');
    }

    public function review(Request $request, int $entryId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate([
            'version' => 'required|integer', 'decision' => ['required', Rule::in(['approve', 'reject'])], 'review_evidence' => 'required|string|max:3000',
            'backup_id' => 'nullable|integer', 'due_at' => 'nullable|date|after:now',
        ]);
        DB::transaction(function () use ($request, $organisationId, $entryId, $data) {
            $entry = ProviderRosterEntry::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($entryId);
            abort_unless($entry->status === 'pending' && $entry->version === (int) $data['version'], 409, 'The roster entry changed. Reload before reviewing.');
            abort_unless($entry->provider_id !== $request->user()->id, 403, 'Providers cannot approve their own roster entries.');
            $affected = collect();
            if ($data['decision'] === 'approve' && in_array($entry->kind, ['leave', 'travel'])) {
                $affected = Appointment::where('organisation_id', $organisationId)->where('provider_id', $entry->provider_id)->whereIn('status', ['booked', 'confirmed'])->where('starts_at', '<', $entry->ends_at)->where('ends_at', '>', $entry->starts_at)->lockForUpdate()->get();
                if ($affected->isNotEmpty()) {
                    if (empty($data['backup_id']) || empty($data['due_at'])) {
                        throw ValidationException::withMessages(['backup_id' => 'Choose a distinct operational backup and deadline for affected visits.']);
                    }
                    abort_unless(Workspace::staff($organisationId)->whereKey($data['backup_id'])->exists() && (int) $data['backup_id'] !== $request->user()->id, 422, 'Choose a distinct active operational backup.');
                }
            }
            $entry->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'review_evidence' => $data['review_evidence'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'version' => $entry->version + 1]);
            if ($affected->isNotEmpty()) {
                CareTask::create([
                    'organisation_id' => $organisationId, 'patient_id' => null, 'title' => 'Reassign '.$affected->count().' visit(s) affected by approved '.$entry->kind,
                    'description' => 'Review the roster and contact affected patients/providers. No visit is automatically cancelled or reassigned.', 'priority' => 'high', 'status' => 'new',
                    'owner_id' => $request->user()->id, 'backup_id' => $data['backup_id'], 'created_by' => $request->user()->id, 'due_at' => $this->time($data['due_at']), 'request_id' => (string) Str::uuid(),
                ]);
            }
            Workspace::audit($request, 'roster.entry.'.$entry->status, $organisationId, 'provider_roster_entries', $entry->id);
        });

        return back()->with('success', 'Roster review recorded. Affected visits were not changed automatically.');
    }

    public function respond(Request $request, int $appointmentId)
    {
        $organisationId = $this->providerOrganisation($request);
        $data = $request->validate(['version' => 'required|integer', 'decision' => ['required', Rule::in(['accept', 'decline'])], 'evidence' => 'required|string|max:2000', 'declaration' => 'accepted']);
        DB::transaction(function () use ($request, $organisationId, $appointmentId, $data) {
            $visit = Appointment::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)->lockForUpdate()->findOrFail($appointmentId);
            abort_unless($visit->status === 'booked' && $visit->offer_status === 'pending' && $visit->version === (int) $data['version'] && $visit->starts_at->isFuture(), 409, 'This visit offer is no longer awaiting your response.');
            if ($data['decision'] === 'accept' && ! ProviderScheduling::isAvailable($organisationId, $request->user()->id, $visit->starts_at, $visit->ends_at)) {
                throw ValidationException::withMessages(['decision' => 'This visit is outside your approved availability or overlaps approved leave/travel.']);
            }
            $visit->update(['offer_status' => $data['decision'] === 'accept' ? 'accepted' : 'declined', 'offer_responded_at' => now(), 'offer_evidence' => $data['evidence'], 'status' => $data['decision'] === 'decline' ? 'declined' : 'booked', 'version' => $visit->version + 1]);
            if ($data['decision'] === 'decline' && $visit->missed_visit_case_id) {
                $case = MissedVisitCase::where('organisation_id', $organisationId)->lockForUpdate()->find($visit->missed_visit_case_id);
                if ($case && $case->replacement_appointment_id === $visit->id && $case->status === 'open') {
                    $case->update(['replacement_status' => 'required', 'replacement_appointment_id' => null, 'replacement_evidence' => 'Replacement visit #'.$visit->id.' was declined; another replacement is required.', 'version' => $case->version + 1]);
                }
            }
            Workspace::audit($request, 'appointment.offer_'.$visit->offer_status, $organisationId, 'appointments', $visit->id);
        });

        return back()->with('success', $data['decision'] === 'accept' ? 'Visit accepted. Coordination must still confirm the patient and schedule.' : 'Visit declined. Coordination must arrange another provider.');
    }
}
