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

        return Inertia::render('care/visits', ['visits' => Appointment::where('provider_id', $r->user()->id)->whereIn('organisation_id', $orgs)->with(['patient:id,name,reference', 'attendance'])->orderByDesc('starts_at')->paginate(15)]);
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
