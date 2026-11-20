<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendanceReview;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceReviewController extends Controller
{
    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('mauricare.time_zone'))->utc();
    }

    public function index(Request $request)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $events = AttendanceEvent::where('capture_status', '!=', 'obtained')->whereHas('appointment', fn ($query) => $query->where('organisation_id', $organisationId))
            ->with(['appointment.patient:id,name,reference', 'appointment.provider:id,name', 'review.reviewer:id,name', 'corrections' => fn ($query) => $query->with(['requester:id,name', 'reviewer:id,name'])->latest()])
            ->orderByDesc('occurred_at')->limit(200)->get();
        Workspace::audit($request, 'attendance.review_queue.viewed', $organisationId);

        return Inertia::render('attendance/index', ['events' => $events]);
    }

    public function reviewEvent(Request $request, int $eventId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate(['request_id' => 'required|uuid', 'decision' => ['required', Rule::in(['confirmed_exception', 'correction_required'])], 'evidence' => 'required|string|max:3000']);
        DB::transaction(function () use ($request, $organisationId, $eventId, $data) {
            if ($existing = AttendanceReview::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->attendance_event_id === $eventId && $existing->decision === $data['decision'] && $existing->evidence === $data['evidence'], 409, 'This request identifier was already used for different review content.');

                return;
            }
            $event = AttendanceEvent::whereHas('appointment', fn ($query) => $query->where('organisation_id', $organisationId))->lockForUpdate()->findOrFail($eventId);
            abort_unless($event->capture_status !== 'obtained', 422, 'Only a location exception requires this review.');
            abort_unless($event->actor_id !== $request->user()->id, 403, 'The attendance actor cannot independently review their own exception.');
            abort_if(AttendanceReview::where('attendance_event_id', $event->id)->exists(), 409, 'This attendance exception was already reviewed.');
            $review = AttendanceReview::create(['attendance_event_id' => $event->id, 'decision' => $data['decision'], 'evidence' => $data['evidence'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'request_id' => $data['request_id']]);
            Workspace::audit($request, 'attendance.exception_'.$review->decision, $organisationId, 'attendance_events', $event->id);
        });

        return back()->with('success', 'Attendance exception review retained. The original event remains unchanged.');
    }

    public function requestCorrection(Request $request, int $appointmentId, int $eventId)
    {
        $organisationId = Workspace::selectedOrganisation($request);
        $data = $request->validate(['request_id' => 'required|uuid', 'proposed_occurred_at' => 'required|date', 'reason' => 'required|string|max:3000', 'evidence' => 'required|string|max:3000', 'declaration' => 'accepted']);
        DB::transaction(function () use ($request, $organisationId, $appointmentId, $eventId, $data) {
            if ($existing = AttendanceCorrection::where('request_id', $data['request_id'])->first()) {
                $proposed = $this->time($data['proposed_occurred_at']);
                abort_unless($existing->appointment_id === $appointmentId && $existing->attendance_event_id === $eventId && $existing->requested_by === $request->user()->id && $existing->proposed_occurred_at->equalTo($proposed) && $existing->reason === $data['reason'] && $existing->evidence === $data['evidence'], 409, 'This request identifier was already used for different correction content.');

                return;
            }
            $visit = Appointment::where('organisation_id', $organisationId)->where('provider_id', $request->user()->id)->lockForUpdate()->findOrFail($appointmentId);
            abort_unless(Workspace::memberships($request->user())->where('organisation_id', $organisationId)->whereIn('role', ['doctor', 'nurse', 'carer', 'physiotherapist'])->isNotEmpty(), 403);
            $event = AttendanceEvent::where('appointment_id', $visit->id)->findOrFail($eventId);
            abort_unless($event->capture_status !== 'obtained', 422, 'Only attendance exceptions use this correction workflow.');
            abort_unless($event->review()->where('decision', 'correction_required')->exists(), 422, 'An independent reviewer must require a correction before one can be requested.');
            abort_if(AttendanceCorrection::where('attendance_event_id', $event->id)->whereIn('status', ['pending', 'approved'])->exists(), 409, 'A current correction already exists for this event.');
            $proposed = $this->time($data['proposed_occurred_at']);
            if ($proposed->lt($visit->starts_at->copy()->subDay()) || $proposed->gt($visit->ends_at->copy()->addDay()) || $proposed->isFuture()) {
                throw ValidationException::withMessages(['proposed_occurred_at' => 'The proposed time must be plausible for this visit and cannot be in the future.']);
            }
            $correction = AttendanceCorrection::create([
                'attendance_event_id' => $event->id, 'appointment_id' => $visit->id, 'requested_by' => $request->user()->id,
                'proposed_occurred_at' => $proposed, 'reason' => $data['reason'], 'evidence' => $data['evidence'], 'request_id' => $data['request_id'],
            ]);
            Workspace::audit($request, 'attendance.correction_requested', $organisationId, 'attendance_corrections', $correction->id);
        });

        return back()->with('success', 'Attendance correction requested. The original event remains unchanged pending independent review.');
    }

    public function reviewCorrection(Request $request, int $correctionId)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate(['version' => 'required|integer', 'decision' => ['required', Rule::in(['approve', 'reject'])], 'review_evidence' => 'required|string|max:3000']);
        DB::transaction(function () use ($request, $organisationId, $correctionId, $data) {
            $correction = AttendanceCorrection::whereHas('event.appointment', fn ($query) => $query->where('organisation_id', $organisationId))->lockForUpdate()->findOrFail($correctionId);
            abort_unless($correction->status === 'pending' && $correction->version === (int) $data['version'], 409, 'The correction state changed.');
            abort_unless($correction->requested_by !== $request->user()->id, 403, 'The requester cannot review their own correction.');
            if ($data['decision'] === 'approve') {
                $other = AttendanceEvent::where('appointment_id', $correction->appointment_id)->where('kind', $correction->event->kind === 'arrival' ? 'departure' : 'arrival')->first();
                if ($other) {
                    $otherTime = AttendanceCorrection::where('attendance_event_id', $other->id)->where('status', 'approved')->latest()->value('proposed_occurred_at') ?? $other->occurred_at;
                    $valid = $correction->event->kind === 'arrival' ? $correction->proposed_occurred_at->lt($otherTime) : $correction->proposed_occurred_at->gt($otherTime);
                    if (! $valid) {
                        throw ValidationException::withMessages(['decision' => 'The approved effective arrival must remain before effective departure.']);
                    }
                }
            }
            $correction->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'review_evidence' => $data['review_evidence'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'version' => $correction->version + 1]);
            Workspace::audit($request, 'attendance.correction_'.$correction->status, $organisationId, 'attendance_corrections', $correction->id);
        });

        return back()->with('success', 'Correction review recorded. The source attendance event remains retained.');
    }
}
