<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientAssessment;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AssessmentController extends Controller
{
    private function fail(string $key, string $text): never
    {
        throw ValidationException::withMessages([$key => $text]);
    }

    private function patient(Request $r, int $id, bool $own): Patient
    {
        if ($own) {
            return Patient::where('user_id', $r->user()->id)->findOrFail($id);
        }
        $p = Patient::findOrFail($id);
        $ids = Workspace::memberships($r->user())->pluck('organisation_id')->unique();
        $org = Workspace::selectedOrganisation($r);
        if ($org !== $p->organisation_id || ! $ids->contains($org)) {
            Workspace::audit($r, 'assessment.access.denied', $ids->contains($org) ? $org : null);
            abort(404);
        }

        return $p;
    }

    private function allow(Request $r, Patient $p, string $kind, string $action, bool $own): void
    {
        abort_unless(in_array($kind, ['basic', 'nursing']), 404);
        if ($own) {
            abort_unless($kind === 'basic' && $p->user_id === $r->user()->id, 404);

            return;
        }
        PatientAccess::enforce($r, $p, $kind.'.read');
        if ($action !== 'read') {
            PatientAccess::enforce($r, $p, $kind.'.'.$action);
        }
    }

    public function index(Request $r, int $id)
    {
        return $this->listing($r, $id, false);
    }

    public function own(Request $r, int $id)
    {
        return $this->listing($r, $id, true);
    }

    private function listing(Request $r, int $id, bool $own)
    {
        $p = $this->patient($r, $id, $own);
        $manage = ! $own && $r->user()->hasPermission('patients.manage', $p->organisation_id);
        $permissions = [];
        foreach (['basic', 'nursing'] as $kind) {
            $permissions[$kind] = ['read' => $own ? $kind === 'basic' : PatientAccess::allowed($r->user(), $p, $kind.'.read'), 'write' => $own ? $kind === 'basic' : PatientAccess::allowed($r->user(), $p, $kind.'.write')];
        }
        if (! $manage && ! $permissions['basic']['read'] && ! $permissions['nursing']['read']) {
            Workspace::audit($r, 'assessment.access.denied', $p->organisation_id, 'patients', $p->id);
            abort(403);
        }
        $rows = PatientAssessment::where('patient_id', $id)->when($own, fn ($q) => $q->where('kind', 'basic'))->with('author:id,name')->latest('id')->get()->map(function ($a) use ($permissions, $r, $own) {
            $read = $permissions[$a->kind]['read'];
            $row = $a->only(['id', 'kind', 'status', 'version', 'schema_version', 'previous_id', 'appointment_id', 'submitted_at', 'signed_at', 'cosigned_at', 'review_due', 'created_at', 'updated_at', 'author_id', 'signed_by', 'cosigned_by', 'confirmed_by', 'confirmation_method', 'cosign_required']);
            $row['author'] = $a->author;
            if ($read) {
                $row['content'] = $a->content;
                $row['amendment_reason'] = $a->amendment_reason;
                $row['content_hash'] = $a->content_hash;
            }
            $row['editable'] = $read && $permissions[$a->kind]['write'] && $a->status === 'draft' && ($own || $a->author_id === $r->user()->id);
            $row['can_sign'] = ! $own && $a->kind === 'nursing' && $row['editable'];
            $row['can_submit'] = $a->kind === 'basic' && $row['editable'] && ($own || ! Patient::find($a->patient_id)->user_id);
            $row['can_amend'] = $read && $permissions[$a->kind]['write'] && in_array($a->status, ['submitted', 'signed']) && ($own || $a->author_id === $r->user()->id) && ! PatientAssessment::where('previous_id', $a->id)->exists();
            $row['can_cosign'] = ! $own && $read && $a->kind === 'nursing' && $a->status === 'awaiting_cosign' && $a->signed_by !== $r->user()->id && PatientAccess::allowed($r->user(), Patient::find($a->patient_id), 'nursing.cosign');

            return $row;
        });
        Workspace::audit($r, 'assessment.index.viewed', $p->organisation_id, 'patients', $p->id);
        foreach (['basic', 'nursing'] as $kind) {
            if ($permissions[$kind]['read']) {
                Workspace::audit($r, 'assessment.'.$kind.'.viewed', $p->organisation_id, 'patients', $p->id);
            }
        }

        return Inertia::render('assessments/index', ['patient' => $p->only(['id', 'name', 'reference']), 'own' => $own, 'manage' => $manage, 'permissions' => $permissions, 'rows' => $rows, 'schema' => collect(config('assessments.forms'))->only($own ? ['basic'] : ['basic', 'nursing'])->all(), 'visits' => $own ? [] : Appointment::where('patient_id', $p->id)->where('provider_id', $r->user()->id)->whereIn('status', ['confirmed', 'arrived', 'completed'])->get(['id', 'service', 'starts_at', 'status']), 'cosignRequired' => config('assessments.nursing_cosign_required')]);
    }

    private function content(Request $r, string $kind, bool $final): array
    {
        $schema = config('assessments.forms.'.$kind);
        $keys = array_merge(array_column(array_merge(...array_column($schema['sections'], 'fields')), 'key'), array_keys($schema['children']));
        $rules = ['content' => ['required', 'array:'.implode(',', $keys)]];
        foreach ($schema['sections'] as $s) {
            foreach ($s['fields'] as $f) {
                $rr = [$final && $f['required'] ? 'required' : 'nullable', $f['type'] === 'number' ? 'numeric' : 'string'];
                if ($f['type'] !== 'number') {
                    $rr[] = 'max:5000';
                }
                if ($f['choices']) {
                    $rr[] = Rule::in(explode(',', $f['choices']));
                }
                if ($f['type'] === 'datetime-local') {
                    $rr[] = 'date';
                }
                if ($f['type'] === 'date') {
                    $rr[] = 'date_format:Y-m-d';
                }
                $rules['content.'.$f['key']] = $rr;
            }
        }
        foreach ($schema['children'] as $key => $fields) {
            $rules['content.'.$key] = ($final ? 'present' : 'sometimes').'|array|max:50';
            $rules['content.'.$key.'.*'] = 'array:'.implode(',', array_column($fields, 'key'));
            foreach ($fields as $f) {
                $rr = [$final && $f['required'] ? 'required' : 'nullable', 'string', 'max:3000'];
                if ($f['choices']) {
                    $rr[] = Rule::in(explode(',', $f['choices']));
                }
                if ($f['type'] === 'datetime-local') {
                    $rr[] = 'date';
                }
                if ($f['type'] === 'date') {
                    $rr[] = 'date_format:Y-m-d';
                }
                $rules['content.'.$key.'.*.'.$f['key']] = $rr;
            }
        }
        $c = $r->validate($rules)['content'];
        if (isset($c['pain_score']) && $c['pain_score'] !== null && $c['pain_score'] !== '') {
            if (filter_var($c['pain_score'], FILTER_VALIDATE_INT) === false || $c['pain_score'] < 0 || $c['pain_score'] > 10) {
                $this->fail('content.pain_score', 'Pain score must be an integer from 0 to 10.');
            }
        }
        if (! $final) {
            return $c;
        }
        if ($kind === 'basic') {
            if ($c['respondent_relationship'] === 'assisted' && empty($c['assistance_details'])) {
                $this->fail('content.assistance_details', 'Record who assisted and how.');
            }
            if ($c['symptoms_reported'] === 'yes' && (empty($c['symptom_onset']) || empty($c['symptom_pattern']))) {
                $this->fail('content.symptom_onset', 'Describe onset and pattern, or explicitly record unknown.');
            }
            if ($c['pain_reported'] === 'yes' && (empty($c['pain_scoring']) || $c['pain_scoring'] === 'not_applicable' || empty($c['pain_location']))) {
                $this->fail('content.pain_location', 'Record pain location and scoring status / reason.');
            }
            if ($c['pain_reported'] === 'yes' && $c['pain_scoring'] === 'scored' && (! isset($c['pain_score']) || $c['pain_score'] === '')) {
                $this->fail('content.pain_score', 'Enter a pain score or choose unable.');
            }
            if ($c['recent_falls'] === 'yes' && empty($c['falls_details'])) {
                $this->fail('content.falls_details', 'Describe the fall and injury details or explicitly unknown.');
            }
            foreach (['conditions' => 'reported_conditions', 'medicines' => 'reported_medicines', 'allergies' => 'reported_allergies'] as $status => $list) {
                if (($c[$status.'_status'] === 'listed') !== (count($c[$list]) > 0)) {
                    $this->fail('content.'.$list, 'Listed history needs entries; none / unknown must not contain listed entries.');
                }
            }
        } else {
            if ($c['identifier_one_type'] === $c['identifier_two_type']) {
                $this->fail('content.identifier_two_type', 'Use two distinct approved identifiers, not address or room alone.');
            }
            $actual = CarbonImmutable::parse($c['assessment_at'], 'Indian/Mauritius')->utc();
            if ($actual->isFuture()) {
                $this->fail('content.assessment_at', 'Actual assessment time cannot be in the future.');
            }
            if ($actual->lt(now()->subHour()) && empty($c['late_entry_reason'])) {
                $this->fail('content.late_entry_reason', 'Record why this is a late entry.');
            }
            if ($c['observations_status'] === 'not_obtained' && empty($c['observations_reason'])) {
                $this->fail('content.observations_reason', 'Record why observations were not obtained.');
            }
            if ($c['observations_status'] === 'recorded') {
                foreach (['observed_at', 'temperature', 'pulse', 'respiratory_rate', 'systolic', 'diastolic', 'spo2', 'oxygen_device'] as $k) {
                    if (! isset($c[$k]) || $c[$k] === '') {
                        $this->fail('content.'.$k, 'Enter the measured value / context, or explicitly mark observations not obtained.');
                    }
                }
            }
            if (! empty($c['observed_at']) && CarbonImmutable::parse($c['observed_at'], 'Indian/Mauritius')->isFuture()) {
                $this->fail('content.observed_at', 'Observation time cannot be in the future.');
            }
            if (isset($c['spo2']) && $c['spo2'] !== '' && ($c['spo2'] < 0 || $c['spo2'] > 100)) {
                $this->fail('content.spo2', 'SpO2 must be a percentage between 0 and 100.');
            }
            if ($c['unresolved_concerns'] === 'yes' && empty($c['escalation_evidence'])) {
                $this->fail('content.escalation_evidence', 'Document actual escalation, responsible owner, acknowledgement and deadline. This form does not dispatch an emergency response.');
            }
            if (count($c['problems']) === 0 || count($c['interventions']) === 0) {
                $this->fail('content.problems', 'Record nursing problems and proposed interventions.');
            }
            if ($c['review_due'] < now('Indian/Mauritius')->toDateString()) {
                $this->fail('content.review_due', 'Choose a current or future review date.');
            }
        }

        return $c;
    }

    public function store(Request $r, int $id)
    {
        return $this->create($r, $id, false);
    }

    public function ownStore(Request $r, int $id)
    {
        return $this->create($r, $id, true);
    }

    private function create(Request $r, int $id, bool $own)
    {
        $p = $this->patient($r, $id, $own);
        $d = $r->validate(['kind' => ['required', Rule::in($own ? ['basic'] : ['basic', 'nursing'])], 'request_id' => 'required|uuid', 'previous_id' => 'nullable|integer', 'amendment_reason' => 'nullable|string|max:2000', 'appointment_id' => 'nullable|integer']);
        $this->allow($r, $p, $d['kind'], 'write', $own);
        DB::transaction(function () use ($r, $p, $d, $own) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), $d['kind'], 'write', $own);
            $old = PatientAssessment::where('request_id', $d['request_id'])->first();
            if ($old) {
                abort_unless($old->patient_id === $p->id && $old->author_id === $r->user()->id, 409);

                return;
            }
            $content = [];
            $previous = null;
            if (! empty($d['previous_id'])) {
                $previous = PatientAssessment::where('patient_id', $p->id)->where('kind', $d['kind'])->findOrFail($d['previous_id']);
                abort_unless(in_array($previous->status, ['submitted', 'signed']) && ($own || $previous->author_id === $r->user()->id), 403);
                if (empty($d['amendment_reason'])) {
                    $this->fail('amendment_reason', 'Explain the correction in a new version.');
                }abort_if(PatientAssessment::where('previous_id', $previous->id)->exists(), 409, 'An amendment already exists. Continue that draft.');
                $content = $previous->content;
            }
            $visit = $d['appointment_id'] ?? $previous?->appointment_id;
            if ($d['kind'] === 'nursing') {
                $this->visit($r, $p, (int) $visit);
            }
            PatientAssessment::create(['patient_id' => $p->id, 'appointment_id' => $visit, 'kind' => $d['kind'], 'author_id' => $r->user()->id, 'content' => $content, 'schema_version' => config('assessments.schema_version'), 'previous_id' => $previous?->id, 'amendment_reason' => $d['amendment_reason'] ?? null, 'request_id' => $d['request_id'], 'cosign_required' => $d['kind'] === 'nursing' && config('assessments.nursing_cosign_required')]);
            Workspace::audit($r, 'assessment.'.$d['kind'].'.draft_created', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Assessment draft created.');
    }

    private function visit(Request $r, Patient $p, int $id): Appointment
    {
        return Appointment::where('patient_id', $p->id)->where('organisation_id', $p->organisation_id)->where('provider_id', $r->user()->id)->whereIn('status', ['confirmed', 'arrived', 'completed'])->findOrFail($id);
    }

    public function update(Request $r, int $id, int $assessment)
    {
        return $this->change($r, $id, $assessment, false);
    }

    public function ownUpdate(Request $r, int $id, int $assessment)
    {
        return $this->change($r, $id, $assessment, true);
    }

    private function change(Request $r, int $id, int $assessment, bool $own)
    {
        $p = $this->patient($r, $id, $own);
        $a = PatientAssessment::where('patient_id', $id)->findOrFail($assessment);
        $this->allow($r, $p, $a->kind, 'write', $own);
        $d = $r->validate(['version' => 'required|integer', 'action' => ['required', Rule::in(['save', 'submit', 'sign'])]]);
        $final = $d['action'] !== 'save';
        abort_unless(($a->kind === 'basic' && $d['action'] !== 'sign') || ($a->kind === 'nursing' && $d['action'] !== 'submit'), 422);
        if ($final) {
            $r->validate(['declaration' => 'accepted']);
            if (! $own) {
                $r->validate(['password' => 'required|current_password']);
            }
        }
        $c = $this->content($r, $a->kind, $final);
        if ($a->kind === 'basic' && $final && ! $own) {
            abort_if($p->user_id !== null, 403, 'The linked patient must confirm and submit their own basic assessment.');
            $r->validate(['confirmation_evidence' => 'required|string|max:3000']);
        }
        DB::transaction(function () use ($r, $p, $a, $d, $c, $own, $final) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), $a->kind, 'write', $own);
            $locked = PatientAssessment::lockForUpdate()->findOrFail($a->id);
            abort_unless($locked->version === (int) $d['version'], 409, 'Assessment changed. Reload before saving.');
            abort_unless($locked->status === 'draft', 409, 'Submitted and signed content is immutable. Create an amendment.');
            abort_unless($own || $locked->author_id === $r->user()->id, 403);
            $changes = ['content' => $c, 'version' => $locked->version + 1];
            if ($final && $a->kind === 'basic') {
                $changes += ['status' => 'submitted', 'confirmed_by' => $r->user()->id, 'submitted_at' => now(), 'confirmation_method' => $own ? 'linked_patient' : 'assisted_verbal', 'confirmation_evidence' => $own ? 'Patient explicitly confirmed accuracy to the best of their knowledge.' : $r->confirmation_evidence];
            }
            if ($final && $a->kind === 'nursing') {
                $this->visit($r, $p, $locked->appointment_id);
                $demo = $p->fresh()->demographics ?? [];
                if (empty($demo['safe_contact_method']) || (empty($demo['emergency_contact_name']) && empty($demo['emergency_contact_unavailable_reason']))) {
                    $this->fail('content', 'Complete safe contact and emergency contact / declined reason in the patient master record before signing.');
                }
                $changes += ['status' => $locked->cosign_required ? 'awaiting_cosign' : 'signed', 'signed_by' => $r->user()->id, 'signed_at' => now(), 'review_due' => $c['review_due']];
            }
            if ($final) {
                $changes['content_hash'] = $locked->digest($c);
            }
            $locked->update($changes);
            Workspace::audit($r, 'assessment.'.$a->kind.'.'.$d['action'], $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', $final ? 'Assessment locked and attributed.' : 'Draft saved.');
    }

    public function cosign(Request $r, int $id, int $assessment)
    {
        $p = $this->patient($r, $id, false);
        $a = PatientAssessment::where('patient_id', $id)->where('kind', 'nursing')->findOrFail($assessment);
        $this->allow($r, $p, 'nursing', 'cosign', false);
        $r->validate(['password' => 'required|current_password', 'version' => 'required|integer', 'declaration' => 'accepted']);
        DB::transaction(function () use ($r, $p, $a) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), 'nursing', 'cosign', false);
            $locked = PatientAssessment::lockForUpdate()->findOrFail($a->id);
            abort_unless($locked->status === 'awaiting_cosign' && $locked->version === (int) $r->version, 409);
            abort_unless(hash_equals($locked->content_hash ?? '', $locked->digest($locked->content)), 409, 'Content integrity check failed. Clinical review is required.');
            abort_if($locked->signed_by === $r->user()->id, 403, 'A separate clinical lead must co-sign.');
            $locked->update(['status' => 'signed', 'cosigned_by' => $r->user()->id, 'cosigned_at' => now(), 'version' => $locked->version + 1]);
            Workspace::audit($r, 'assessment.nursing.cosigned', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Clinical lead co-signature recorded.');
    }
}
