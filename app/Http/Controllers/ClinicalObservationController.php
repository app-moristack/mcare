<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClinicalObservation;
use App\Models\Patient;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ClinicalObservationController extends Controller
{
    public const TYPES = [
        'temperature' => ['label' => 'Temperature', 'unit' => '°C'],
        'pulse' => ['label' => 'Pulse', 'unit' => 'beats/min'],
        'respiratory_rate' => ['label' => 'Respiratory rate', 'unit' => 'breaths/min'],
        'oxygen_saturation' => ['label' => 'Oxygen saturation', 'unit' => '%'],
        'blood_pressure_systolic' => ['label' => 'Blood pressure — systolic', 'unit' => 'mmHg'],
        'blood_pressure_diastolic' => ['label' => 'Blood pressure — diastolic', 'unit' => 'mmHg'],
        'weight' => ['label' => 'Weight', 'unit' => 'kg'],
    ];

    private function patient(Request $request, int $id, string $scope): Patient
    {
        $patient = Patient::findOrFail($id);
        $organisations = Workspace::memberships($request->user())->pluck('organisation_id');
        abort_unless(Workspace::selectedOrganisation($request) === $patient->organisation_id && $organisations->contains($patient->organisation_id), 404);
        PatientAccess::enforce($request, $patient, $scope);

        return $patient;
    }

    public function index(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'observations.read');
        $canWrite = PatientAccess::allowed($request->user(), $patient, 'observations.write');
        $rows = ClinicalObservation::where('patient_id', $patient->id)
            ->with(['author:id,name', 'appointment:id,service'])
            ->orderByDesc('observed_at')->get()->map(fn (ClinicalObservation $row) => [
                ...$row->only(['id', 'appointment_id', 'supersedes_id', 'observed_at', 'measurements', 'context', 'correction_reason', 'content_hash']),
                'author' => $row->author?->only(['id', 'name']),
                'appointment' => $row->appointment?->only(['id', 'service']),
            ]);
        Workspace::audit($request, 'patient.observations.viewed', $patient->organisation_id, 'patients', $patient->id);

        return Inertia::render('observations/index', [
            'patient' => $patient->only(['id', 'name', 'reference']),
            'rows' => $rows,
            'types' => self::TYPES,
            'canWrite' => $canWrite,
            'visits' => $canWrite ? Appointment::where('patient_id', $patient->id)
                ->where('provider_id', $request->user()->id)
                ->whereIn('status', ['confirmed', 'arrived', 'completed'])
                ->orderByDesc('starts_at')->get(['id', 'service', 'starts_at', 'status']) : [],
        ]);
    }

    public function store(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'observations.write');
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'appointment_id' => 'required|integer',
            'observed_at' => 'required|date',
            'measurements' => 'required|array|min:1|max:7',
            'measurements.*' => 'required|array:type,value,method',
            'measurements.*.type' => ['required', Rule::in(array_keys(self::TYPES))],
            'measurements.*.value' => ['required', 'numeric', 'regex:/^-?[0-9]{1,4}(\.[0-9]{1,2})?$/'],
            'measurements.*.method' => 'nullable|string|max:255',
            'context' => 'nullable|string|max:2000',
            'supersedes_id' => 'nullable|integer',
            'correction_reason' => 'nullable|required_with:supersedes_id|string|max:2000',
            'password' => 'required|string',
            'declaration' => 'accepted',
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        if (empty($data['supersedes_id']) && ! empty($data['correction_reason'])) {
            throw ValidationException::withMessages(['correction_reason' => 'Select the observation set that this correction replaces.']);
        }
        $observedAt = CarbonImmutable::parse($data['observed_at'], config('mauricare.time_zone'))->utc();
        if ($observedAt->isFuture()) {
            throw ValidationException::withMessages(['observed_at' => 'Observation time cannot be in the future.']);
        }
        $types = collect($data['measurements'])->pluck('type');
        if ($types->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['measurements' => 'Record each observation type once per set.']);
        }
        if ($types->contains('blood_pressure_systolic') xor $types->contains('blood_pressure_diastolic')) {
            throw ValidationException::withMessages(['measurements' => 'Record systolic and diastolic blood pressure together.']);
        }
        $visit = Appointment::where('patient_id', $patient->id)->where('organisation_id', $patient->organisation_id)->where('provider_id', $request->user()->id)
            ->whereIn('status', ['confirmed', 'arrived', 'completed'])->findOrFail($data['appointment_id']);
        $superseded = null;
        if (! empty($data['supersedes_id'])) {
            $superseded = ClinicalObservation::where('patient_id', $patient->id)->findOrFail($data['supersedes_id']);
        }
        $measurements = collect($data['measurements'])->map(fn ($measurement) => [
            'type' => $measurement['type'],
            'value' => (string) $measurement['value'],
            'unit' => self::TYPES[$measurement['type']]['unit'],
            'method' => $measurement['method'] ?? '',
        ])->values()->all();

        $attributes = [
            'patient_id' => $patient->id,
            'appointment_id' => $visit->id,
            'author_id' => $request->user()->id,
            'supersedes_id' => $superseded?->id,
            'observed_at' => $observedAt,
            'measurements' => $measurements,
            'context' => $data['context'] ?? null,
            'correction_reason' => $data['correction_reason'] ?? null,
        ];
        $contentHash = ClinicalObservation::digestFor($attributes);

        DB::transaction(function () use ($request, $patient, $data, $attributes, $contentHash, $superseded) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'observations.write');
            $existing = ClinicalObservation::where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->patient_id === $patient->id && $existing->author_id === $request->user()->id && hash_equals($existing->content_hash, $contentHash), 409, 'This request identifier was already used for different observation content.');

                return;
            }
            if ($superseded) {
                abort_if(ClinicalObservation::where('supersedes_id', $superseded->id)->exists(), 409, 'This observation already has a correction.');
            }
            ClinicalObservation::create([
                ...$attributes,
                'content_hash' => $contentHash,
                'request_id' => $data['request_id'],
            ]);
            Workspace::audit($request, $superseded ? 'patient.observations.corrected' : 'patient.observations.recorded', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Observation set signed and retained. No automated clinical interpretation was applied.');
    }
}
