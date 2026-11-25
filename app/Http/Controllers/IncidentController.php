<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\IncidentReassignment;
use App\Models\OperationalIncident;
use App\Models\Organisation;
use App\Models\User;
use App\Services\IncidentReports;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $organisationId = Workspace::organisation($request, 'incidents.manage');
        $incidents = OperationalIncident::where('organisation_id', $organisationId)
            ->with([
                'reporter:id,name', 'reviewer:id,name', 'reassignments.previousReviewer:id,name',
                'reassignments.reviewer:id,name', 'reassignments.changer:id,name',
            ])->latest('id')->paginate(20)->through(fn (OperationalIncident $incident) => [
                ...$incident->only(['id', 'visit_id', 'category', 'severity', 'status', 'description', 'resolution', 'reported_at', 'resolved_at', 'version']),
                'reporter' => $incident->reporter->only(['id', 'name']),
                'reviewer' => $incident->reviewer?->only(['id', 'name']),
                'reassignments' => $incident->reassignments->map(fn (IncidentReassignment $change) => [
                    ...$change->only(['id', 'reason', 'changed_at']),
                    'previous_reviewer' => $change->previousReviewer->only(['id', 'name']),
                    'reviewer' => $change->reviewer->only(['id', 'name']),
                    'changed_by' => $change->changer->only(['id', 'name']),
                ]),
            ]);
        $reviewers = Workspace::staff($organisationId)->orderBy('name')->get()
            ->filter(fn (User $user) => $user->hasPermission('incidents.manage', $organisationId))
            ->map->only(['id', 'name'])->values();
        $visits = $request->user()->hasPermission('appointments.manage', $organisationId)
            ? Appointment::where('organisation_id', $organisationId)->latest('starts_at')->limit(200)->get(['id', 'service', 'starts_at'])
            : [];
        Workspace::audit($request, 'incidents.viewed', $organisationId, 'operational_incidents');

        return Inertia::render('incidents/index', [
            'incidents' => $incidents,
            'reviewers' => $reviewers,
            'visits' => $visits,
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function store(Request $request)
    {
        $organisationId = Workspace::organisation($request, 'incidents.manage');
        $data = $request->validate([...IncidentReports::RULES, 'visit_id' => 'nullable|integer|min:1', 'reviewer_id' => 'required|integer|min:1']);
        $visitId = isset($data['visit_id']) ? (int) $data['visit_id'] : null;
        if ($visitId !== null) {
            abort_unless($request->user()->hasPermission('appointments.manage', $organisationId), 403);
            if (! Appointment::where('organisation_id', $organisationId)->whereKey($visitId)->exists()) {
                throw ValidationException::withMessages(['visit_id' => 'Choose a visit in this organisation.']);
            }
        }

        DB::transaction(function () use ($request, $organisationId, $data, $visitId) {
            Organisation::lockForUpdate()->findOrFail($organisationId);
            IncidentReports::record($request, $organisationId, $data, $visitId, (int) $data['reviewer_id']);
        }, 3);

        return back()->with('success', 'Incident recorded and assigned for internal review.');
    }

    public function assign(Request $request, int $id)
    {
        $organisationId = Workspace::organisation($request, 'incidents.manage');
        $data = $request->validate(['reviewer_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $organisationId, $id, $data) {
            $incident = OperationalIncident::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($id);
            $reviewerId = (int) $data['reviewer_id'];
            $version = (int) $data['version'];
            if ($incident->status === 'assigned' && $incident->version === $version + 1
                && $incident->reviewer_id === $reviewerId && $incident->assigned_by === $request->user()->id
                && ! $incident->reassignments()->where('previous_version', $version)->exists()) {
                return;
            }

            abort_unless($incident->status === 'reported' && $incident->version === $version, 409, 'This incident changed. Reload before assigning the review.');
            IncidentReports::ensureReviewer($organisationId, $reviewerId, $incident->reporter_id);
            $incident->update([
                'reviewer_id' => $reviewerId,
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
                'status' => 'assigned',
                'version' => $version + 1,
            ]);
            Workspace::audit($request, 'incident.assigned', $organisationId, 'operational_incidents', $incident->id);
        }, 3);

        return back()->with('success', 'Reviewer assigned.');
    }

    public function reassign(Request $request, int $id)
    {
        $organisationId = Workspace::organisation($request, 'incidents.manage');
        $data = $request->validate([
            'reviewer_id' => 'required|integer|min:1',
            'version' => 'required|integer|min:1',
            'reason' => 'required|string|max:4000',
            'password' => 'required|current_password',
            'declaration' => 'accepted',
        ]);

        DB::transaction(function () use ($request, $organisationId, $id, $data) {
            $incident = OperationalIncident::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($id);
            $actor = $request->user();
            abort_unless($actor->hasPermission('incidents.manage', $organisationId)
                && Workspace::staff($organisationId)->whereKey($actor->id)->exists(), 403);

            $version = (int) $data['version'];
            $reviewerId = (int) $data['reviewer_id'];
            $existing = $incident->reassignments()->where('previous_version', $version)->first();
            if ($existing) {
                abort_unless($existing->changed_by === $actor->id && $existing->reviewer_id === $reviewerId
                    && $existing->reason === $data['reason'], 409, 'This incident already changed. Reload before making another reassignment.');

                return;
            }

            abort_unless($incident->status === 'assigned' && $incident->reviewer_id !== null && $incident->version === $version,
                409,
                'Only a current unresolved assignment can be changed. Reload this incident.'
            );
            if ($incident->reviewer_id === $reviewerId) {
                throw ValidationException::withMessages(['reviewer_id' => 'Choose a different reviewer from the current reviewer.']);
            }
            IncidentReports::ensureReviewer($organisationId, $reviewerId, $incident->reporter_id);

            $changedAt = now();
            $incident->reassignments()->create([
                'previous_reviewer_id' => $incident->reviewer_id,
                'previous_assigned_by' => $incident->assigned_by,
                'previous_assigned_at' => $incident->assigned_at,
                'reviewer_id' => $reviewerId,
                'changed_by' => $actor->id,
                'reason' => $data['reason'],
                'changed_at' => $changedAt,
                'previous_version' => $version,
            ]);
            $incident->update([
                'reviewer_id' => $reviewerId,
                'assigned_by' => $actor->id,
                'assigned_at' => $changedAt,
                'version' => $version + 1,
            ]);
            Workspace::audit($request, 'incident.reassigned', $organisationId, 'operational_incidents', $incident->id);
        }, 3);

        return back()->with('success', 'Reviewer reassigned with retained evidence.');
    }

    public function resolve(Request $request, int $id)
    {
        $organisationId = Workspace::organisation($request, 'incidents.manage');
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'resolution' => 'required|string|max:4000',
            'password' => 'required|current_password',
            'declaration' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $organisationId, $id, $data) {
            $incident = OperationalIncident::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($id);
            abort_unless($incident->reviewer_id === $request->user()->id && $incident->reviewer_id !== $incident->reporter_id
                && $request->user()->hasPermission('incidents.manage', $organisationId)
                && Workspace::staff($organisationId)->whereKey($request->user()->id)->exists(), 403, 'Only the current named reviewer can resolve this incident.');
            $version = (int) $data['version'];
            if ($incident->status === 'resolved' && $incident->version === $version + 1 && $incident->resolution === $data['resolution']) {
                return;
            }

            abort_unless($incident->status === 'assigned' && $incident->version === $version, 409, 'This incident changed. Reload before recording resolution.');
            $incident->update([
                'resolution' => $data['resolution'],
                'resolved_at' => now(),
                'status' => 'resolved',
                'version' => $version + 1,
            ]);
            Workspace::audit($request, 'incident.resolved', $organisationId, 'operational_incidents', $incident->id);
        }, 3);

        return back()->with('success', 'Incident resolution signed and recorded.');
    }
}
