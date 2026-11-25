<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProviderVisitController extends Controller
{
    private function allowed(Request $r, Appointment $visit): void
    {
        abort_unless($visit->provider_id === $r->user()->id, 403);
        abort_unless(Workspace::memberships($r->user())->where('organisation_id', $visit->organisation_id)->whereIn('role', ['doctor', 'nurse', 'carer', 'physiotherapist'])->isNotEmpty(), 403);
    }

    public function index(Request $r)
    {
        $orgs = Workspace::memberships($r->user())->whereIn('role', ['doctor', 'nurse', 'carer', 'physiotherapist'])->pluck('organisation_id');

        $visits = Appointment::where('provider_id', $r->user()->id)->whereIn('organisation_id', $orgs)
            ->with(['patient:id,name,reference', 'attendance.review', 'attendance.corrections' => fn ($query) => $query->latest(), 'checklist'])
            ->orderByDesc('starts_at')->paginate(15)->through(fn (Appointment $visit) => [
                ...$visit->toArray(),
                'checklist' => $visit->checklist?->forPage($visit->canSubmitChecklist()),
            ]);

        return Inertia::render('care/visits', ['visits' => $visits]);
    }

    public function submitChecklist(Request $r, int $id)
    {
        $data = $r->validate([
            'visit_version' => 'required|integer|min:1',
            'request_id' => 'required|uuid',
            'responses' => 'required|array|list|min:1|max:40',
            'responses.*' => 'required|array:status,reason',
            'responses.*.status' => 'required|in:completed,exception',
            'responses.*.reason' => 'nullable|required_if:responses.*.status,exception|prohibited_if:responses.*.status,completed|string|max:2000',
            'password' => 'required|current_password',
            'declaration' => 'accepted',
        ]);

        DB::transaction(function () use ($r, $id, $data) {
            $visit = Appointment::lockForUpdate()->findOrFail($id);
            $this->allowed($r, $visit);
            $checklist = $visit->checklist()->lockForUpdate()->firstOrFail();
            $responses = array_map(fn (array $step) => ['status' => $step['status'], 'reason' => $step['reason'] ?? null], $data['responses']);
            if ($checklist->submitted_at !== null) {
                abort_unless($checklist->submission_request_id === $data['request_id'] && $checklist->submitted_by === $r->user()->id
                    && $checklist->responses === $responses, 409, 'This visit checklist has already been signed.');

                return;
            }

            abort_unless($visit->version === (int) $data['visit_version'] && $visit->canSubmitChecklist(),
                409,
                'Reload the visit before signing. The accepted visit must have a recorded arrival.'
            );
            if (count($responses) !== count($checklist->steps)) {
                throw ValidationException::withMessages(['responses' => 'Record an outcome for every attached checklist step.']);
            }
            $source = $checklist->sourceVersion;
            abort_unless($source && $source->status === 'published' && $source->version === $checklist->version
                && $source->steps === $checklist->steps && hash_equals($checklist->source_hash, $source->digest()),
                409,
                'Checklist source integrity check failed.'
            );
            $signer = $r->user()->id;
            $checklist->update([
                'responses' => $responses,
                'submitted_by' => $signer,
                'submission_request_id' => $data['request_id'],
                'submitted_at' => now(),
                'submission_hash' => $checklist->digest($responses, $signer),
            ]);
            $visit->increment('version');
            Workspace::audit($r, 'visit.checklist.signed', $visit->organisation_id, 'appointments', $visit->id);
        }, 3);

        return back()->with('success', 'Visit checklist signed and retained.');
    }

    public function attendance(Request $r, int $id)
    {
        $data = $r->validate(['kind' => 'required|in:arrival,departure', 'request_id' => 'required|uuid', 'capture_status' => 'required|in:obtained,denied,unavailable,timeout', 'latitude' => 'nullable|required_if:capture_status,obtained|numeric|between:-90,90', 'longitude' => 'nullable|required_if:capture_status,obtained|numeric|between:-180,180', 'accuracy' => 'nullable|required_if:capture_status,obtained|numeric|min:0|max:100000', 'exception_reason' => 'nullable|required_unless:capture_status,obtained|string|max:2000']);
        DB::transaction(function () use ($r, $id, $data) {
            $visit = Appointment::lockForUpdate()->findOrFail($id);
            $this->allowed($r, $visit);
            if ($visit->attendance()->where('request_id', $data['request_id'])->exists()) {
                return;
            }
            if ($visit->attendance()->where('kind', $data['kind'])->exists()) {
                throw ValidationException::withMessages(['kind' => 'This attendance event has already been recorded.']);
            }
            if ($data['kind'] === 'arrival' && $visit->status !== 'confirmed') {
                throw ValidationException::withMessages(['kind' => 'Confirm the appointment before recording arrival.']);
            }
            if ($data['kind'] === 'departure' && ($visit->status !== 'arrived' || ! $visit->attendance()->where('kind', 'arrival')->exists())) {
                throw ValidationException::withMessages(['kind' => 'Record arrival before departure.']);
            }
            if ($data['capture_status'] !== 'obtained') {
                $data['latitude'] = null;
                $data['longitude'] = null;
                $data['accuracy'] = null;
            }
            $visit->attendance()->create([...$data, 'actor_id' => $r->user()->id, 'occurred_at' => now(), 'received_at' => now()]);
            if ($data['kind'] === 'arrival') {
                $visit->update(['status' => 'arrived', 'version' => $visit->version + 1]);
            }
            Workspace::audit($r, 'attendance.'.$data['kind'], $visit->organisation_id, 'appointments', $id);
        }, 3);

        return back()->with('success', 'Attendance recorded.');
    }
}
