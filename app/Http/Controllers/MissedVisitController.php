<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CareTask;
use App\Models\MissedVisitCase;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MissedVisitController extends Controller
{
    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('mauricare.time_zone'))->utc();
    }

    private function member(int $organisationId, int $userId, string $field): void
    {
        if (! Workspace::staff($organisationId)->whereKey($userId)->exists()) {
            throw ValidationException::withMessages([$field => 'Choose an active member of this organisation.']);
        }
    }

    public function mark(Request $request, int $appointmentId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'version' => 'required|integer', 'missed_reason' => 'required|string|max:3000',
            'owner_id' => 'required|integer', 'backup_id' => 'required|integer|different:owner_id', 'due_at' => 'required|date|after:now',
        ]);
        $this->member($organisationId, (int) $data['owner_id'], 'owner_id');
        $this->member($organisationId, (int) $data['backup_id'], 'backup_id');
        DB::transaction(function () use ($request, $organisationId, $appointmentId, $data) {
            if ($existing = MissedVisitCase::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->organisation_id === $organisationId && $existing->appointment_id === $appointmentId && $existing->missed_reason === $data['missed_reason'] && $existing->owner_id === (int) $data['owner_id'] && $existing->backup_id === (int) $data['backup_id'] && $existing->due_at->equalTo($this->time($data['due_at'])), 409, 'This request identifier was already used for different missed-visit content.');

                return;
            }
            $visit = Appointment::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($appointmentId);
            abort_unless($visit->status === 'confirmed' && $visit->version === (int) $data['version'], 409, 'Only a current confirmed visit can be marked missed.');
            abort_unless($visit->ends_at->isPast() && ! $visit->attendance()->exists(), 422, 'Wait until the visit has ended and confirm that no attendance event exists.');
            abort_if(MissedVisitCase::where('appointment_id', $visit->id)->exists(), 409, 'A missed-visit case already exists.');
            $case = MissedVisitCase::create([
                'organisation_id' => $organisationId, 'patient_id' => $visit->patient_id, 'appointment_id' => $visit->id,
                'missed_reason' => $data['missed_reason'], 'reported_by' => $request->user()->id, 'reported_at' => now(),
                'owner_id' => $data['owner_id'], 'backup_id' => $data['backup_id'], 'due_at' => $this->time($data['due_at']), 'request_id' => $data['request_id'],
            ]);
            $task = CareTask::create([
                'organisation_id' => $organisationId, 'patient_id' => $visit->patient_id, 'title' => 'Welfare check and replacement for missed visit #'.$visit->id,
                'description' => 'Record the real-world welfare contact outcome and arrange a replacement or evidenced waiver. No contact is performed automatically.',
                'priority' => 'high', 'status' => 'new', 'owner_id' => $data['owner_id'], 'backup_id' => $data['backup_id'], 'created_by' => $request->user()->id,
                'due_at' => $case->due_at, 'request_id' => (string) Str::uuid(),
            ]);
            $case->update(['task_id' => $task->id]);
            $visit->update(['status' => 'missed', 'cancellation_reason' => 'Missed visit case #'.$case->id.' opened; see restricted operational evidence.', 'version' => $visit->version + 1]);
            foreach (array_unique([(int) $data['owner_id'], (int) $data['backup_id']]) as $userId) {
                DB::table('operation_notifications')->updateOrInsert(['deduplication_key' => 'missed-visit:'.$case->id.':'.$userId], ['organisation_id' => $organisationId, 'user_id' => $userId, 'message' => 'Missed visit requires an evidenced welfare check and replacement decision.', 'url' => '/workspace/roster', 'created_at' => now(), 'updated_at' => now()]);
            }
            Workspace::audit($request, 'appointment.missed_case_opened', $organisationId, 'missed_visit_cases', $case->id);
        });

        return back()->with('success', 'Missed visit recorded. Welfare and replacement work is now assigned; no contact was performed automatically.');
    }

    public function welfare(Request $request, int $caseId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate([
            'version' => 'required|integer', 'welfare_status' => ['required', Rule::in(['contacted', 'escalated', 'not_required'])],
            'welfare_evidence' => 'required|string|max:4000', 'checked_at' => 'required|date',
        ]);
        if ($this->time($data['checked_at'])->isFuture()) {
            throw ValidationException::withMessages(['checked_at' => 'The welfare action time cannot be in the future.']);
        }
        DB::transaction(function () use ($request, $organisationId, $caseId, $data) {
            $case = MissedVisitCase::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($caseId);
            abort_unless($case->status === 'open' && $case->version === (int) $data['version'], 409, 'The missed-visit case changed. Reload before recording welfare evidence.');
            abort_unless(in_array($request->user()->id, [$case->owner_id, $case->backup_id]), 403, 'Only the assigned owner or backup can record the welfare outcome.');
            $case->update(['welfare_status' => $data['welfare_status'], 'welfare_evidence' => $data['welfare_evidence'], 'welfare_checked_by' => $request->user()->id, 'welfare_checked_at' => $this->time($data['checked_at']), 'version' => $case->version + 1]);
            Workspace::audit($request, 'missed_visit.welfare_recorded', $organisationId, 'missed_visit_cases', $case->id);
        });

        return back()->with('success', 'Welfare outcome recorded. This record documents external action; it does not claim MauriCare contacted anyone.');
    }

    public function waiveReplacement(Request $request, int $caseId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate(['version' => 'required|integer', 'replacement_evidence' => 'required|string|max:4000']);
        DB::transaction(function () use ($request, $organisationId, $caseId, $data) {
            $case = MissedVisitCase::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($caseId);
            abort_unless($case->status === 'open' && $case->replacement_status === 'required' && $case->version === (int) $data['version'], 409, 'The replacement state changed.');
            abort_unless(in_array($request->user()->id, [$case->owner_id, $case->backup_id]), 403, 'Only the assigned owner or backup can waive replacement.');
            abort_unless($case->welfare_status !== 'pending', 422, 'Record the welfare outcome before waiving a replacement.');
            $case->update(['replacement_status' => 'waived', 'replacement_evidence' => $data['replacement_evidence'], 'version' => $case->version + 1]);
            Workspace::audit($request, 'missed_visit.replacement_waived', $organisationId, 'missed_visit_cases', $case->id);
        });

        return back()->with('success', 'Replacement waived with retained evidence.');
    }

    public function resolve(Request $request, int $caseId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate(['version' => 'required|integer', 'resolution_evidence' => 'required|string|max:4000']);
        DB::transaction(function () use ($request, $organisationId, $caseId, $data) {
            $case = MissedVisitCase::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($caseId);
            abort_unless($case->status === 'open' && $case->version === (int) $data['version'], 409, 'The missed-visit case changed.');
            abort_unless(in_array($request->user()->id, [$case->owner_id, $case->backup_id]), 403, 'Only the assigned owner or backup can resolve this case.');
            abort_unless($case->welfare_status !== 'pending', 422, 'A welfare outcome is required before resolution.');
            if ($case->replacement_status !== 'waived') {
                $replacement = $case->replacement;
                abort_unless($replacement && in_array($replacement->status, ['confirmed', 'arrived', 'completed']), 422, 'The replacement must be confirmed or completed before resolution.');
            }
            $case->update(['status' => 'resolved', 'replacement_status' => $case->replacement_status === 'waived' ? 'waived' : 'booked', 'resolution_evidence' => $data['resolution_evidence'], 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'version' => $case->version + 1]);
            if ($case->task_id && ($task = CareTask::lockForUpdate()->find($case->task_id)) && ! in_array($task->status, ['completed', 'cancelled'])) {
                $task->update(['status' => 'completed', 'completion_evidence' => 'Missed-visit welfare and replacement case #'.$case->id.' resolved with restricted evidence.', 'version' => $task->version + 1]);
            }
            DB::table('operation_notifications')->where('deduplication_key', 'like', 'missed-visit:'.$case->id.':%')->update(['read_at' => now()]);
            Workspace::audit($request, 'missed_visit.resolved', $organisationId, 'missed_visit_cases', $case->id);
        });

        return back()->with('success', 'Missed-visit case resolved with welfare and replacement evidence retained.');
    }
}
