<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\MedicationAdministration;
use App\Models\MedicationInstruction;
use App\Models\MedicationReconciliation;
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

class MedicationController extends Controller
{
    private function patient(Request $request, int $id, string $scope): Patient
    {
        $patient = Patient::findOrFail($id);
        $organisations = Workspace::memberships($request->user())->pluck('organisation_id');
        abort_unless(Workspace::selectedOrganisation($request) === $patient->organisation_id && $organisations->contains($patient->organisation_id), 404);
        PatientAccess::enforce($request, $patient, $scope);

        return $patient;
    }

    private function signature(Request $request, array $data): void
    {
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
    }

    private function time(string $value, string $field, bool $allowFuture = false): CarbonImmutable
    {
        $time = CarbonImmutable::parse($value, config('mauricare.time_zone'))->utc();
        if (! $allowFuture && $time->isFuture()) {
            throw ValidationException::withMessages([$field => 'The recorded time cannot be in the future.']);
        }

        return $time;
    }

    public function index(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'medications.read');
        $canReconcile = PatientAccess::allowed($request->user(), $patient, 'medications.reconcile');
        $canVerify = PatientAccess::allowed($request->user(), $patient, 'medications.verify');
        $canAdminister = PatientAccess::allowed($request->user(), $patient, 'medications.administer');
        $reconciliations = MedicationReconciliation::where('patient_id', $id)->with(['author:id,name', 'appointment:id,service'])->latest('reconciled_at')->get();
        $instructions = MedicationInstruction::where('patient_id', $id)->with('verifier:id,name')->latest('starts_at')->get();
        $administrations = MedicationAdministration::where('patient_id', $id)->with(['administrator:id,name', 'appointment:id,service'])->latest('administered_at')->get();
        $mapReconciliation = fn (MedicationReconciliation $row) => [...$row->only(['id', 'appointment_id', 'supersedes_id', 'reconciled_at', 'status', 'sources', 'medicines', 'discrepancies', 'follow_up', 'correction_reason', 'content_hash']), 'author' => $row->author?->only(['id', 'name']), 'appointment' => $row->appointment?->only(['id', 'service'])];
        $mapInstruction = fn (MedicationInstruction $row) => [...$row->only(['id', 'supersedes_id', 'verified_at', 'starts_at', 'ends_at', 'status', 'details', 'correction_reason', 'content_hash']), 'verifier' => $row->verifier?->only(['id', 'name'])];
        $mapAdministration = fn (MedicationAdministration $row) => [...$row->only(['id', 'medication_instruction_id', 'appointment_id', 'supersedes_id', 'administered_at', 'outcome', 'instruction_snapshot', 'reason', 'notes', 'correction_reason', 'content_hash']), 'administrator' => $row->administrator?->only(['id', 'name']), 'appointment' => $row->appointment?->only(['id', 'service'])];
        Workspace::audit($request, 'patient.medications.viewed', $patient->organisation_id, 'patients', $patient->id);

        return Inertia::render('medications/index', [
            'patient' => $patient->only(['id', 'name', 'reference']),
            'reconciliations' => $reconciliations->map($mapReconciliation),
            'instructions' => $instructions->map($mapInstruction),
            'administrations' => $administrations->map($mapAdministration),
            'canReconcile' => $canReconcile,
            'canVerify' => $canVerify,
            'canAdminister' => $canAdminister,
            'reconciliationVisits' => $canReconcile ? Appointment::where('patient_id', $id)->where('organisation_id', $patient->organisation_id)
                ->whereIn('status', ['confirmed', 'arrived', 'completed'])->latest('starts_at')->get(['id', 'provider_id', 'service', 'starts_at', 'status']) : [],
            'administrationVisits' => $canAdminister ? Appointment::where('patient_id', $id)->where('organisation_id', $patient->organisation_id)
                ->where('provider_id', $request->user()->id)->whereIn('status', ['confirmed', 'arrived', 'completed'])->latest('starts_at')->get(['id', 'provider_id', 'service', 'starts_at', 'status']) : [],
        ]);
    }

    public function reconcile(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'medications.reconcile');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'appointment_id' => 'required|integer', 'reconciled_at' => 'required|date',
            'status' => ['required', Rule::in(['complete', 'incomplete', 'unable'])],
            'sources' => 'required|array|min:1|max:10', 'sources.*' => 'required|string|max:255',
            'medicines' => 'present|array|max:50', 'medicines.*' => 'array:name,dose,route,frequency,status',
            'medicines.*.name' => 'required|string|max:255', 'medicines.*.dose' => 'required|string|max:255', 'medicines.*.route' => 'required|string|max:100', 'medicines.*.frequency' => 'required|string|max:255',
            'medicines.*.status' => ['required', Rule::in(['taking', 'not_taking', 'uncertain'])],
            'discrepancies' => 'nullable|string|max:4000', 'follow_up' => 'nullable|string|max:4000',
            'supersedes_id' => 'nullable|integer', 'correction_reason' => 'nullable|required_with:supersedes_id|string|max:2000',
            'password' => 'required|string', 'declaration' => 'accepted',
        ]);
        $this->signature($request, $data);
        if ($data['status'] !== 'complete' && empty($data['follow_up'])) {
            throw ValidationException::withMessages(['follow_up' => 'Record the follow-up required when reconciliation is not complete.']);
        }
        $visit = Appointment::where('patient_id', $id)->where('organisation_id', $patient->organisation_id)->whereIn('status', ['confirmed', 'arrived', 'completed'])->findOrFail($data['appointment_id']);
        $superseded = empty($data['supersedes_id']) ? null : MedicationReconciliation::where('patient_id', $id)->findOrFail($data['supersedes_id']);
        $attributes = ['patient_id' => $id, 'appointment_id' => $visit->id, 'author_id' => $request->user()->id, 'supersedes_id' => $superseded?->id, 'reconciled_at' => $this->time($data['reconciled_at'], 'reconciled_at'), 'status' => $data['status'], 'sources' => array_values($data['sources']), 'medicines' => array_values($data['medicines']), 'discrepancies' => $data['discrepancies'] ?? null, 'follow_up' => $data['follow_up'] ?? null, 'correction_reason' => $data['correction_reason'] ?? null];
        $hash = MedicationReconciliation::digestFor($attributes);

        DB::transaction(function () use ($request, $patient, $data, $attributes, $hash, $superseded) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'medications.reconcile');
            if ($existing = MedicationReconciliation::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->patient_id === $patient->id && $existing->author_id === $request->user()->id && hash_equals($existing->content_hash, $hash), 409, 'This request identifier was already used for different content.');

                return;
            }
            if ($superseded) {
                abort_if(MedicationReconciliation::where('supersedes_id', $superseded->id)->exists(), 409, 'This reconciliation already has a correction.');
            }
            MedicationReconciliation::create([...$attributes, 'content_hash' => $hash, 'request_id' => $data['request_id']]);
            Workspace::audit($request, $superseded ? 'patient.medications.reconciliation_corrected' : 'patient.medications.reconciled', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Medication reconciliation signed and retained.');
    }

    public function verify(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'medications.verify');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'verified_at' => 'required|date', 'starts_at' => 'required|date', 'ends_at' => 'nullable|date|after:starts_at',
            'status' => ['required', Rule::in(['active', 'discontinued'])],
            'medicine' => 'required|string|max:255', 'dose' => 'required|string|max:255', 'route' => 'required|string|max:100', 'frequency' => 'required|string|max:255', 'instructions' => 'nullable|string|max:2000',
            'prescriber_name' => 'required|string|max:255', 'prescriber_registration' => 'required|string|max:255', 'source_reference' => 'required|string|max:500', 'verification_evidence' => 'required|string|max:2000',
            'supersedes_id' => 'nullable|integer', 'correction_reason' => 'nullable|required_with:supersedes_id|string|max:2000', 'password' => 'required|string', 'declaration' => 'accepted',
        ]);
        $this->signature($request, $data);
        $superseded = empty($data['supersedes_id']) ? null : MedicationInstruction::where('patient_id', $id)->findOrFail($data['supersedes_id']);
        $details = collect($data)->only(['medicine', 'dose', 'route', 'frequency', 'instructions', 'prescriber_name', 'prescriber_registration', 'source_reference', 'verification_evidence'])->all();
        $attributes = ['patient_id' => $id, 'verifier_id' => $request->user()->id, 'supersedes_id' => $superseded?->id, 'verified_at' => $this->time($data['verified_at'], 'verified_at'), 'starts_at' => $this->time($data['starts_at'], 'starts_at', true), 'ends_at' => empty($data['ends_at']) ? null : $this->time($data['ends_at'], 'ends_at', true), 'status' => $data['status'], 'details' => $details, 'correction_reason' => $data['correction_reason'] ?? null];
        $hash = MedicationInstruction::digestFor($attributes);

        DB::transaction(function () use ($request, $patient, $data, $attributes, $hash, $superseded) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'medications.verify');
            if ($existing = MedicationInstruction::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->patient_id === $patient->id && $existing->verifier_id === $request->user()->id && hash_equals($existing->content_hash, $hash), 409, 'This request identifier was already used for different content.');

                return;
            }
            if ($superseded) {
                abort_if(MedicationInstruction::where('supersedes_id', $superseded->id)->exists(), 409, 'This instruction already has a correction or replacement.');
            }
            MedicationInstruction::create([...$attributes, 'content_hash' => $hash, 'request_id' => $data['request_id']]);
            Workspace::audit($request, $superseded ? 'patient.medications.instruction_corrected' : 'patient.medications.instruction_verified', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'External medication instruction verified and retained. This is not a prescription.');
    }

    public function administer(Request $request, int $id)
    {
        $patient = $this->patient($request, $id, 'medications.administer');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'medication_instruction_id' => 'required|integer', 'appointment_id' => 'required|integer', 'administered_at' => 'required|date',
            'outcome' => ['required', Rule::in(['administered', 'withheld', 'refused', 'omitted'])], 'reason' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:2000',
            'supersedes_id' => 'nullable|integer', 'correction_reason' => 'nullable|required_with:supersedes_id|string|max:2000', 'password' => 'required|string', 'declaration' => 'accepted',
        ]);
        $this->signature($request, $data);
        if ($data['outcome'] !== 'administered' && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Record a reason when the medicine was not administered.']);
        }
        $administeredAt = $this->time($data['administered_at'], 'administered_at');
        $visit = Appointment::where('patient_id', $id)->where('organisation_id', $patient->organisation_id)->where('provider_id', $request->user()->id)->whereIn('status', ['confirmed', 'arrived', 'completed'])->findOrFail($data['appointment_id']);
        $instruction = MedicationInstruction::where('patient_id', $id)->where('status', 'active')->findOrFail($data['medication_instruction_id']);
        if (MedicationInstruction::where('supersedes_id', $instruction->id)->exists() || $instruction->starts_at->isAfter($administeredAt) || ($instruction->ends_at && $instruction->ends_at->isBefore($administeredAt))) {
            throw ValidationException::withMessages(['medication_instruction_id' => 'Choose a current, active, non-superseded verified instruction.']);
        }
        $superseded = empty($data['supersedes_id']) ? null : MedicationAdministration::where('patient_id', $id)->findOrFail($data['supersedes_id']);
        $snapshot = ['instruction_id' => $instruction->id, 'instruction_hash' => $instruction->content_hash, ...$instruction->details];
        $attributes = ['patient_id' => $id, 'medication_instruction_id' => $instruction->id, 'appointment_id' => $visit->id, 'administrator_id' => $request->user()->id, 'supersedes_id' => $superseded?->id, 'administered_at' => $administeredAt, 'outcome' => $data['outcome'], 'instruction_snapshot' => $snapshot, 'reason' => $data['reason'] ?? null, 'notes' => $data['notes'] ?? null, 'correction_reason' => $data['correction_reason'] ?? null];
        $hash = MedicationAdministration::digestFor($attributes);

        DB::transaction(function () use ($request, $patient, $data, $attributes, $hash, $superseded) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($request, $patient->fresh(), 'medications.administer');
            if ($existing = MedicationAdministration::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->patient_id === $patient->id && $existing->administrator_id === $request->user()->id && hash_equals($existing->content_hash, $hash), 409, 'This request identifier was already used for different content.');

                return;
            }
            if ($superseded) {
                abort_if(MedicationAdministration::where('supersedes_id', $superseded->id)->exists(), 409, 'This administration already has a correction.');
            }
            MedicationAdministration::create([...$attributes, 'content_hash' => $hash, 'request_id' => $data['request_id']]);
            Workspace::audit($request, $superseded ? 'patient.medications.administration_corrected' : 'patient.medications.administration_recorded', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Medication administration outcome signed and retained.');
    }
}
