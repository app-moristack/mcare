<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use App\Models\Investigation;
use App\Models\InvestigationPublication;
use App\Models\InvestigationResult;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InvestigationController extends Controller
{
    private function patient(Request $request, int $id, string $scope): Patient
    {
        $patient = Patient::findOrFail($id);
        $organisations = Workspace::memberships($request->user())->pluck('organisation_id');
        abort_unless(Workspace::selectedOrganisation($request) === $patient->organisation_id && $organisations->contains($patient->organisation_id), 404);
        PatientAccess::enforce($request, $patient, $scope);

        return $patient;
    }

    private function time(string $value, string $field, bool $futureAllowed = false): CarbonImmutable
    {
        $time = CarbonImmutable::parse($value, config('mauricare.time_zone'))->utc();
        if (! $futureAllowed && $time->isFuture()) {
            throw ValidationException::withMessages([$field => 'The recorded time cannot be in the future.']);
        }

        return $time;
    }

    private function owners(Patient $patient)
    {
        $ids = PatientAccessRule::where('patient_id', $patient->id)->where('kind', 'assignment')->where('role', 'doctor')->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('user_id');

        return User::whereIn('id', $ids)->orderBy('name')->get()->filter(fn (User $user) => PatientAccess::allowed($user, $patient, 'results.acknowledge'))->values();
    }

    private function backups(Patient $patient)
    {
        return Workspace::staff($patient->organisation_id)->orderBy('name')->get()->filter(fn (User $user) => $user->hasPermission('tasks.manage', $patient->organisation_id))->values();
    }

    public function index(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'investigations.read');
        $canRequest = PatientAccess::allowed($request->user(), $patient, 'investigations.request');
        $canReadResults = PatientAccess::allowed($request->user(), $patient, 'results.read');
        $canReceive = PatientAccess::allowed($request->user(), $patient, 'results.receive');
        $canAcknowledge = PatientAccess::allowed($request->user(), $patient, 'results.acknowledge');
        $canPublish = PatientAccess::allowed($request->user(), $patient, 'results.publish');
        $investigations = Investigation::where('patient_id', $id)->with('requester:id,name')->latest('requested_at')->get()->map(fn (Investigation $row) => [...$row->only(['id', 'requested_at', 'category', 'details', 'content_hash']), 'requester' => $row->requester?->only(['id', 'name'])]);
        $results = $canReadResults ? InvestigationResult::where('patient_id', $id)->with(['investigation:id,category,details', 'receiver:id,name', 'owner:id,name', 'followUpOwner:id,name', 'backup:id,name', 'publication:id,investigation_result_id,published_at'])->latest('received_at')->get()->map(fn (InvestigationResult $row) => [...$row->only(['id', 'investigation_id', 'owner_id', 'backup_id', 'supersedes_id', 'task_id', 'received_at', 'source_reported_at', 'source_flag', 'details', 'correction_reason', 'status', 'acknowledgement', 'acknowledged_at', 'completion', 'completed_at', 'version', 'content_hash']), 'investigation' => $row->investigation ? ['id' => $row->investigation->id, 'category' => $row->investigation->category, 'name' => $row->investigation->details['name']] : null, 'receiver' => $row->receiver?->only(['id', 'name']), 'owner' => $row->owner?->only(['id', 'name']), 'effective_owner_id' => $row->effectiveOwnerId(), 'effective_owner' => ($row->followUpOwner ?? $row->owner)?->only(['id', 'name']), 'backup' => $row->backup?->only(['id', 'name']), 'published_at' => $row->publication?->published_at]) : collect();
        Workspace::audit($request, 'patient.investigations.viewed', $patient->organisation_id, 'patients', $patient->id);

        return Inertia::render('investigations/index', [
            'patient' => $patient->only(['id', 'name', 'reference']), 'investigations' => $investigations, 'results' => $results,
            'canRequest' => $canRequest, 'canReadResults' => $canReadResults, 'canReceive' => $canReceive, 'canAcknowledge' => $canAcknowledge, 'canPublish' => $canPublish,
            'owners' => $canReceive ? $this->owners($patient)->map->only(['id', 'name']) : [], 'backups' => $canReceive ? $this->backups($patient)->map->only(['id', 'name']) : [],
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function request(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'investigations.request');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'requested_at' => 'required|date', 'category' => ['required', Rule::in(['laboratory', 'imaging', 'pathology', 'other'])],
            'name' => 'required|string|max:255', 'clinical_question' => 'required|string|max:3000', 'destination' => 'required|string|max:255', 'source_reference' => 'required|string|max:500',
            'password' => 'required|current_password', 'declaration' => 'accepted',
        ]);
        $attributes = ['patient_id' => $id, 'requester_id' => $request->user()->id, 'requested_at' => $this->time($data['requested_at'], 'requested_at'), 'category' => $data['category'], 'details' => collect($data)->only(['name', 'clinical_question', 'destination', 'source_reference'])->all()];
        $hash = Investigation::digestFor($attributes);
        DB::transaction(function () use ($request, $patient, $data, $attributes, $hash) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'investigations.request');
            if ($existing = Investigation::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->patient_id === $patient->id && $existing->requester_id === $request->user()->id && hash_equals($existing->content_hash, $hash), 409, 'This request identifier was already used for different content.');

                return;
            }
            Investigation::create([...$attributes, 'content_hash' => $hash, 'request_id' => $data['request_id']]);
            Workspace::audit($request, 'patient.investigation.request_recorded', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'External investigation request recorded. Nothing was electronically transmitted.');
    }

    public function receive(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'results.receive');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'investigation_id' => 'required|integer', 'received_at' => 'required|date', 'source_reported_at' => 'nullable|date',
            'source_flag' => ['required', Rule::in(['routine', 'urgent', 'critical'])], 'result_type' => ['required', Rule::in(['preliminary', 'final', 'corrected'])],
            'source_reference' => 'required|string|max:500', 'summary' => 'required|string|max:5000', 'source_comment' => 'nullable|string|max:3000',
            'values' => 'present|array|max:50', 'values.*' => 'array:label,value,unit', 'values.*.label' => 'required|string|max:255', 'values.*.value' => 'required|string|max:500', 'values.*.unit' => 'nullable|string|max:100',
            'owner_id' => 'required|integer', 'backup_id' => 'required|integer|different:owner_id', 'due_at' => 'required|date|after:now',
            'escalated_at' => 'nullable|date', 'escalation_channel' => 'nullable|string|max:255', 'escalation_evidence' => 'nullable|string|max:3000',
            'supersedes_id' => 'nullable|integer', 'correction_reason' => 'nullable|required_with:supersedes_id|string|max:2000',
            'password' => 'required|current_password', 'declaration' => 'accepted',
        ]);
        if ($data['source_flag'] === 'critical' && (empty($data['escalated_at']) || empty($data['escalation_channel']) || empty($data['escalation_evidence']))) {
            throw ValidationException::withMessages(['escalation_evidence' => 'Before recording a source-flagged critical result, record the completed real-world escalation time, channel and evidence.']);
        }
        if (! empty($data['source_reported_at']) && $this->time($data['source_reported_at'], 'source_reported_at')->isAfter($this->time($data['received_at'], 'received_at'))) {
            throw ValidationException::withMessages(['source_reported_at' => 'The source report time cannot be after the result was received.']);
        }
        $investigation = Investigation::where('patient_id', $id)->findOrFail($data['investigation_id']);
        $owner = $this->owners($patient)->firstWhere('id', (int) $data['owner_id']);
        if (! $owner) {
            throw ValidationException::withMessages(['owner_id' => 'Choose a current assigned doctor with result-acknowledgement authority.']);
        }
        $backup = $this->backups($patient)->firstWhere('id', (int) $data['backup_id']);
        if (! $backup || $backup->id === $owner->id) {
            throw ValidationException::withMessages(['backup_id' => 'Choose a distinct active operational backup.']);
        }
        $superseded = empty($data['supersedes_id']) ? null : InvestigationResult::where('patient_id', $id)->where('investigation_id', $investigation->id)->findOrFail($data['supersedes_id']);
        $details = ['result_type' => $data['result_type'], 'source_reference' => $data['source_reference'], 'summary' => $data['summary'], 'source_comment' => $data['source_comment'] ?? null, 'values' => array_values($data['values']), 'escalated_at' => empty($data['escalated_at']) ? null : $this->time($data['escalated_at'], 'escalated_at')->toISOString(), 'escalation_channel' => $data['escalation_channel'] ?? null, 'escalation_evidence' => $data['escalation_evidence'] ?? null];
        $attributes = ['patient_id' => $id, 'investigation_id' => $investigation->id, 'received_by' => $request->user()->id, 'owner_id' => $owner->id, 'backup_id' => $backup->id, 'supersedes_id' => $superseded?->id, 'received_at' => $this->time($data['received_at'], 'received_at'), 'source_reported_at' => empty($data['source_reported_at']) ? null : $this->time($data['source_reported_at'], 'source_reported_at'), 'source_flag' => $data['source_flag'], 'details' => $details, 'correction_reason' => $data['correction_reason'] ?? null];
        $hash = InvestigationResult::digestFor($attributes);
        DB::transaction(function () use ($request, $patient, $data, $attributes, $hash, $superseded, $owner, $backup) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'results.receive');
            if ($existing = InvestigationResult::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->patient_id === $patient->id && $existing->received_by === $request->user()->id && hash_equals($existing->content_hash, $hash), 409, 'This request identifier was already used for different content.');

                return;
            }
            if ($superseded) {
                abort_if(InvestigationResult::where('supersedes_id', $superseded->id)->exists(), 409, 'This result already has a correction.');
                if ($superseded->task_id && ($oldTask = CareTask::lockForUpdate()->find($superseded->task_id)) && ! in_array($oldTask->status, ['completed', 'cancelled'])) {
                    $oldTask->update(['status' => 'cancelled', 'completion_evidence' => 'Superseded by a corrected investigation result.', 'version' => $oldTask->version + 1]);
                }
            }
            $result = InvestigationResult::create([...$attributes, 'content_hash' => $hash, 'request_id' => $data['request_id']]);
            $task = CareTask::create(['organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id, 'title' => 'Follow up investigation result #'.$result->id, 'description' => ($attributes['source_flag'] === 'critical' ? 'Source-flagged critical result with completed external escalation evidence. ' : '').'Tracking only: open the authorised investigation workspace; no result content is included here.', 'priority' => $attributes['source_flag'] === 'routine' ? 'routine' : 'high', 'status' => 'new', 'owner_id' => $owner->id, 'backup_id' => $backup->id, 'created_by' => $request->user()->id, 'due_at' => CarbonImmutable::parse($data['due_at'], config('mauricare.time_zone'))->utc(), 'request_id' => (string) Str::uuid()]);
            $result->update(['task_id' => $task->id]);
            foreach ([$owner->id, $backup->id] as $userId) {
                DB::table('operation_notifications')->updateOrInsert(['deduplication_key' => 'result-received:'.$result->id.':'.$userId], ['organisation_id' => $patient->organisation_id, 'user_id' => $userId, 'message' => ($attributes['source_flag'] === 'critical' ? 'Source-flagged critical result: external escalation recorded; acknowledge follow-up.' : 'Investigation result awaiting acknowledgement.'), 'url' => '/patient-records/'.$patient->id.'/investigations', 'created_at' => now(), 'updated_at' => now()]);
            }
            Workspace::audit($request, $superseded ? 'patient.result.corrected' : 'patient.result.received', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Result signed, assigned and retained. No clinical threshold was inferred.');
    }

    public function acknowledge(Request $request, int $id, int $resultId)
    {
        $patient = $this->patient($request, $id, 'results.acknowledge');
        $data = $request->validate(['version' => 'required|integer', 'review_summary' => 'required|string|max:4000', 'patient_communication' => ['required', Rule::in(['completed', 'pending', 'not_required'])], 'communication_evidence' => 'required|string|max:3000', 'follow_up_required' => ['required', Rule::in(['yes', 'no'])], 'follow_up_plan' => 'nullable|string|max:4000', 'follow_up_due' => 'nullable|date|after:now', 'password' => 'required|current_password', 'declaration' => 'accepted']);
        if ($data['follow_up_required'] === 'yes' && (empty($data['follow_up_plan']) || empty($data['follow_up_due']))) {
            throw ValidationException::withMessages(['follow_up_plan' => 'Record the follow-up plan and future deadline.']);
        }
        if ($data['patient_communication'] === 'pending' && $data['follow_up_required'] !== 'yes') {
            throw ValidationException::withMessages(['follow_up_required' => 'Pending patient communication must remain an accountable follow-up.']);
        }
        DB::transaction(function () use ($request, $patient, $data, $resultId) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'results.acknowledge');
            $result = InvestigationResult::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($resultId);
            abort_unless($result->effectiveOwnerId() === $request->user()->id, 403, 'Only the named result owner can acknowledge this result.');
            abort_if(InvestigationResult::where('supersedes_id', $result->id)->exists(), 409, 'A corrected result replaced this record.');
            abort_unless($result->status === 'pending' && $result->version === (int) $data['version'], 409, 'The result state changed. Reload before acknowledging it.');
            abort_unless(hash_equals($result->content_hash, $result->digest()), 409, 'Result integrity check failed.');
            $acknowledgement = collect($data)->only(['review_summary', 'patient_communication', 'communication_evidence', 'follow_up_required', 'follow_up_plan', 'follow_up_due'])->all();
            $result->update(['status' => 'acknowledged', 'acknowledgement' => $acknowledgement, 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now(), 'version' => $result->version + 1]);
            if ($result->task_id && ($task = CareTask::lockForUpdate()->find($result->task_id))) {
                $followUp = $data['follow_up_required'] === 'yes';
                $followUpDue = $followUp ? CarbonImmutable::parse($data['follow_up_due'], config('mauricare.time_zone'))->utc() : null;
                $task->update(['status' => $followUp ? 'waiting' : 'completed', 'waiting_reason' => $followUp ? 'Clinical owner acknowledged result; accountable follow-up remains due.' : null, 'due_at' => $followUpDue ?? $task->due_at, 'next_check_at' => $followUpDue, 'completion_evidence' => $followUp ? null : 'Named clinical owner acknowledged the result and recorded that no further follow-up is required.', 'version' => $task->version + 1]);
            }
            Workspace::audit($request, 'patient.result.acknowledged', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Result acknowledged by its named owner.');
    }

    public function complete(Request $request, int $id, int $resultId)
    {
        $patient = $this->patient($request, $id, 'results.acknowledge');
        $data = $request->validate(['version' => 'required|integer', 'outcome' => 'required|string|max:4000', 'evidence' => 'required|string|max:3000', 'password' => 'required|current_password', 'declaration' => 'accepted']);
        DB::transaction(function () use ($request, $patient, $data, $resultId) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'results.acknowledge');
            $result = InvestigationResult::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($resultId);
            abort_unless($result->effectiveOwnerId() === $request->user()->id && $result->status === 'acknowledged' && $result->version === (int) $data['version'], 409, 'Only the named owner can complete current acknowledged follow-up.');
            abort_unless(($result->acknowledgement['follow_up_required'] ?? null) === 'yes', 409, 'This result has no outstanding follow-up to complete.');
            abort_if(InvestigationResult::where('supersedes_id', $result->id)->exists(), 409, 'A corrected result replaced this record.');
            abort_unless(hash_equals($result->content_hash, $result->digest()), 409, 'Result integrity check failed.');
            $result->update(['status' => 'completed', 'completion' => collect($data)->only(['outcome', 'evidence'])->all(), 'completed_by' => $request->user()->id, 'completed_at' => now(), 'version' => $result->version + 1]);
            if ($result->task_id && ($task = CareTask::lockForUpdate()->find($result->task_id)) && ! in_array($task->status, ['completed', 'cancelled'])) {
                $task->update(['status' => 'completed', 'completion_evidence' => 'Named clinical owner recorded result follow-up completion.', 'waiting_reason' => null, 'next_check_at' => null, 'version' => $task->version + 1]);
            }
            Workspace::audit($request, 'patient.result.follow_up_completed', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Result follow-up completed and retained.');
    }

    public function publish(Request $request, int $id, int $resultId)
    {
        $patient = $this->patient($request, $id, 'results.publish');
        $data = $request->validate(['request_id' => 'required|uuid', 'summary' => 'required|string|max:3000', 'next_steps' => 'required|string|max:3000', 'password' => 'required|current_password', 'declaration' => 'accepted']);
        DB::transaction(function () use ($request, $patient, $data, $resultId) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'results.publish');
            $result = InvestigationResult::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($resultId);
            abort_unless(in_array($result->status, ['acknowledged', 'completed']) && ! InvestigationResult::where('supersedes_id', $result->id)->exists(), 409, 'Only a current acknowledged result can be published.');
            abort_unless(hash_equals($result->content_hash, $result->digest()), 409, 'Result integrity check failed.');
            if ($existing = InvestigationPublication::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->investigation_result_id === $result->id, 409);

                return;
            }
            if (InvestigationPublication::where('investigation_result_id', $result->id)->exists()) {
                throw ValidationException::withMessages(['summary' => 'This result already has a retained patient publication.']);
            }
            InvestigationPublication::create(['patient_id' => $patient->id, 'investigation_result_id' => $result->id, 'content' => collect($data)->only(['summary', 'next_steps'])->all(), 'source_hash' => $result->content_hash, 'published_by' => $request->user()->id, 'published_at' => now(), 'request_id' => $data['request_id']]);
            Workspace::audit($request, 'patient.result.published', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Reviewed plain-language result summary published to the patient.');
    }
}
