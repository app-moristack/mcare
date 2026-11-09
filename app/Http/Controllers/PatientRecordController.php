<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\PatientSummary;
use App\Models\User;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PatientRecordController extends Controller
{
    private function patient(Request $r, int $id, bool $manage = false): Patient
    {
        $p = Patient::findOrFail($id);
        if ($manage) {
            abort_unless(Workspace::organisation($r, 'patients.manage') === $p->organisation_id, 404);
        } else {
            $ids = Workspace::memberships($r->user())->pluck('organisation_id')->unique();
            $org = Workspace::selectedOrganisation($r);
            if ($org !== $p->organisation_id || ! $ids->contains($org)) {
                Workspace::audit($r, 'patient.record.denied', $ids->contains($org) ? $org : null);
                abort(404);
            }
            if (! $r->user()->hasPermission('patients.manage', $org)) {
                PatientAccess::enforce($r, $p, 'summary.read');
            }
        }

        return $p;
    }

    private function fields(string $group): array
    {
        $rules = [];
        foreach (config('patient.'.$group) as $section) {
            foreach ($section['fields'] as $f) {
                $rule = [$f['required'] ? 'required' : 'nullable', $f['type'] === 'number' ? 'numeric' : 'string'];
                if ($f['type'] !== 'number') {
                    $rule[] = 'max:3000';
                }
                if ($f['choices']) {
                    $rule[] = Rule::in(explode(',', $f['choices']));
                }
                $rules['content.'.$f['key']] = $rule;
            }
        }

        return $rules;
    }

    private function childRules(string $group): array
    {
        $rules = [];
        foreach (config('patient.'.$group) as $key => $fields) {
            $rules['content.'.$key] = 'sometimes|array|max:50';
            $rules['content.'.$key.'.*'] = 'array:'.implode(',', array_column($fields, 'key'));
            foreach ($fields as $f) {
                $rules['content.'.$key.'.*.'.$f['key']] = [$f['required'] ? 'required' : 'nullable', 'string', 'max:1000', ...($f['type'] === 'date' ? ['date_format:Y-m-d'] : [])];
            }
        }

        return $rules;
    }

    public function show(Request $r, int $id)
    {
        $p = $this->patient($r, $id);
        $manage = $r->user()->hasPermission('patients.manage', $p->organisation_id);
        Workspace::audit($r, 'patient.demographics.viewed', $p->organisation_id, 'patients', $id);

        return Inertia::render('patients/show', [
            'patient' => [...$p->only(['id', 'name', 'reference', 'date_of_birth', 'phone', 'email', 'locality', 'address', 'status', 'version', 'identity_status']), 'demographics' => $manage ? $p->demographics : collect($p->demographics ?? [])->except(['insurance_policies', 'external_identifiers', 'payer_relationship', 'payer_details'])->all()],
            'schema' => config('patient'), 'manage' => $manage, 'canRead' => PatientAccess::allowed($r->user(), $p, 'summary.read'), 'canWrite' => PatientAccess::allowed($r->user(), $p, 'summary.write'), 'canReadObservations' => PatientAccess::allowed($r->user(), $p, 'observations.read'),
            'rules' => $manage ? $this->rules($p) : [],
            'staff' => $manage ? Workspace::staff($p->organisation_id)->select('id', 'name')->orderBy('name')->get() : [],
            'history' => $manage ? $this->history($p) : [],
        ]);
    }

    private function rules(Patient $p)
    {
        return PatientAccessRule::where('patient_id', $p->id)->with('user:id,name')->latest()->get()->map(fn ($a) => [...$a->only(['id', 'kind', 'scope', 'role', 'purpose', 'starts_at', 'ends_at', 'revoked_at', 'revocation_reason', 'authority_type', 'wording_version']), 'user' => $a->user]);
    }

    private function history(Patient $p)
    {
        return DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')->where('audit_events.organisation_id', $p->organisation_id)->where('resource_type', 'patients')->where('resource_id', $p->id)->select('audit_events.id', 'audit_events.event', 'audit_events.created_at', 'users.name as actor')->latest('audit_events.id')->limit(100)->get();
    }

    public function update(Request $r, int $id)
    {
        $p = $this->patient($r, $id, true);
        $d = $r->validate(['version' => 'required|integer', 'name' => 'required|string|max:255', 'date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'email' => 'nullable|email|max:255', 'locality' => 'nullable|string|max:255', 'content' => 'required|array:'.implode(',', [...array_column(array_merge(...array_column(config('patient.demographics'), 'fields')), 'key'), ...array_keys(config('patient.demographicsChildren'))]), ...$this->fields('demographics'), ...$this->childRules('demographicsChildren')]);
        $c = $d['content'];
        $r->validate(['content.home_latitude' => 'nullable|numeric|between:-90,90', 'content.home_longitude' => 'nullable|numeric|between:-180,180', 'content.country' => 'required|string|size:2', 'content.emergency_contact_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'content.next_of_kin_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/']]);
        if (empty($c['family_name']) && empty($c['single_name_reason'])) {
            $this->fail('content.family_name', 'Provide a family name or document the single-name exception.');
        }
        if ($c['dob_precision'] !== 'unknown' && empty($d['date_of_birth'])) {
            $this->fail('date_of_birth', 'Enter a date, or explicitly choose unknown.');
        }
        if ($c['dob_precision'] === 'unknown') {
            $d['date_of_birth'] = null;
        }
        if (empty($d['phone']) && empty($d['email']) && empty($c['assisted_contact']) && ! in_array($c['safe_contact_method'], ['app', 'in_person'])) {
            $this->fail('content.assisted_contact', 'Provide a safe contact or an assisted contact arrangement.');
        }
        if ($c['safe_contact_method'] === 'phone' && empty($d['phone'])) {
            $this->fail('phone', 'A phone is required for the chosen contact method.');
        }
        if ($c['safe_contact_method'] === 'email' && empty($d['email'])) {
            $this->fail('email', 'An email is required for the chosen contact method.');
        }
        if ($c['safe_contact_method'] === 'representative' && empty($c['assisted_contact'])) {
            $this->fail('content.assisted_contact', 'Describe the representative contact arrangement.');
        }
        if ((isset($c['home_latitude']) && $c['home_latitude'] !== '') !== (isset($c['home_longitude']) && $c['home_longitude'] !== '')) {
            $this->fail('content.home_latitude', 'Provide both coordinates or leave both blank.');
        }
        if (isset($c['home_latitude']) && $c['home_latitude'] !== '' && empty($c['pin_verification_evidence'])) {
            $this->fail('content.pin_verification_evidence', 'Record how and when the patient confirmed this pin.');
        }
        if (! empty($c['emergency_contact_name']) && (empty($c['emergency_contact_phone']) || empty($c['emergency_contact_relationship']))) {
            $this->fail('content.emergency_contact_phone', 'Record the emergency contact relationship and phone.');
        }
        if (empty($c['emergency_contact_name']) && empty($c['emergency_contact_unavailable_reason'])) {
            $this->fail('content.emergency_contact_unavailable_reason', 'Provide an emergency contact or an unavailable / declined reason.');
        }
        if (in_array($c['record_status'], ['inactive', 'deceased']) && empty($c['status_reason'])) {
            $this->fail('content.status_reason', 'Record the status reason and date.');
        }
        DB::transaction(function () use ($p, $d, $c, $r) {
            $locked = Patient::lockForUpdate()->findOrFail($p->id);
            abort_unless($locked->version === (int) $d['version'], 409, 'This patient changed. Refresh before saving.');
            $locked->update(['name' => $d['name'], 'date_of_birth' => $d['date_of_birth'] ?? null, 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null, 'locality' => $d['locality'] ?? '', 'address' => trim(($c['address_line_1'] ?? '').' '.($c['address_line_2'] ?? '')), 'demographics' => $c, 'status' => $c['record_status'] === 'active' ? 'active' : ($c['record_status'] === 'provisional' ? 'active' : 'inactive'), 'version' => $locked->version + 1]);
            Workspace::audit($r, 'patient.demographics.updated', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Patient record saved. Emergency contacts do not receive record access.');
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    public function summary(Request $r, int $id)
    {
        $p = $this->patient($r, $id);
        PatientAccess::enforce($r, $p, 'summary.read');
        $s = PatientSummary::where('patient_id', $id)->first();

        return response()->json(['content' => $s?->content ?? ['allergy_status' => 'not_assessed'], 'version' => $s?->version ?? 0, 'updated_at' => $s?->updated_at]);
    }

    public function saveSummary(Request $r, int $id)
    {
        $p = $this->patient($r, $id);
        PatientAccess::enforce($r, $p, 'summary.read');
        PatientAccess::enforce($r, $p, 'summary.write');
        $rules = ['version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000', 'content' => 'required|array', ...$this->fields('clinical')];
        foreach (config('patient.children') as $key => $fields) {
            $rules['content.'.$key] = 'present|array|max:50';
            $rules['content.'.$key.'.*'] = 'array:'.implode(',', array_column($fields, 'key'));
            foreach ($fields as $f) {
                $rules['content.'.$key.'.*.'.$f['key']] = [$f['required'] ? 'required' : 'nullable', 'string', 'max:1000', ...($f['choices'] ? [Rule::in(explode(',', $f['choices']))] : [])];
            }
        }
        // Whitelist keys; no private nursing records or arbitrary payloads can enter the patient-visible summary.
        $keys = array_merge(array_column(config('patient.clinical')[0]['fields'], 'key'), array_keys(config('patient.children')));
        $rules['content'] = 'required|array:'.implode(',', $keys);
        $d = $r->validate($rules);
        $c = $d['content'];
        if ($c['allergy_status'] === 'known_allergies' && count($c['allergies']) === 0) {
            $this->fail('content.allergies', 'Add at least one allergy.');
        }
        if ($c['allergy_status'] !== 'known_allergies' && count($c['allergies']) > 0) {
            $this->fail('content.allergy_status', 'Allergy entries require known allergies status.');
        }
        if (! empty($c['blood_group']) && $c['blood_group'] !== 'unknown' && empty($c['blood_group_source'])) {
            $this->fail('content.blood_group_source', 'Blood group requires source and verification evidence.');
        }
        DB::transaction(function () use ($r, $p, $d, $c) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            PatientAccess::enforce($r, $p->fresh(), 'summary.write');
            $s = PatientSummary::where('patient_id', $p->id)->first();
            abort_unless(($s?->version ?? 0) === (int) $d['version'], 409, 'Summary changed. Reload before saving.');
            $version = ($s?->version ?? 0) + 1;
            PatientSummary::updateOrCreate(['patient_id' => $p->id], ['content' => $c, 'version' => $version, 'updated_by' => $r->user()->id]);
            DB::table('patient_summary_revisions')->insert(['patient_id' => $p->id, 'content' => Crypt::encryptString(json_encode($c, JSON_THROW_ON_ERROR)), 'version' => $version, 'recorded_by' => $r->user()->id, 'reason' => Crypt::encryptString($d['reason']), 'created_at' => now()]);
            Workspace::audit($r, 'patient.summary.updated', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Clinical summary updated with an attributable revision.');
    }

    public function addRule(Request $r, int $id)
    {
        $p = $this->patient($r, $id, true);
        $d = $r->validate(['request_id' => 'required|uuid', 'user_id' => 'required|integer', 'kind' => ['required', Rule::in(['assignment', 'grant', 'block'])], 'scope' => ['required', Rule::in([...PatientAccess::SCOPES, 'all'])], 'role' => ['nullable', Rule::in(['doctor', 'nurse', 'physiotherapist', 'carer', 'clinical_admin'])], 'purpose' => 'required|string|max:255', 'authority_type' => ['required', Rule::in(['patient_consent', 'verified_representative', 'care_assignment', 'patient_block'])], 'evidence' => 'required|string|max:3000', 'wording_version' => 'required|string|max:100', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'attested' => 'accepted']);
        abort_unless(Workspace::staff($p->organisation_id)->whereKey($d['user_id'])->exists(), 422, 'Choose an active member of this organisation.');
        if ($d['kind'] === 'assignment' && (empty($d['role']) || $d['scope'] !== 'all' || $d['authority_type'] !== 'care_assignment')) {
            $this->fail('role', 'Assignments require a clinical role, all scope and care assignment evidence.');
        }
        if ($d['kind'] === 'assignment') {
            $provider = User::findOrFail($d['user_id']);
            $roles = Workspace::memberships($provider)->where('organisation_id', $p->organisation_id)->pluck('role');
            if (! $roles->contains($d['role']) || ! $provider->application()->where('organisation_id', $p->organisation_id)->where('status', 'approved')->whereIn('discipline', $d['role'] === 'clinical_admin' ? ['doctor', 'nurse'] : [$d['role']])->whereDate('credential_expires_at', '>=', now(config('mauricare.time_zone'))->toDateString())->exists()) {
                $this->fail('role', 'The professional needs an approved role and a current reviewed credential for this assignment.');
            }
        }
        if ($d['kind'] === 'grant' && (! in_array($d['authority_type'], ['patient_consent', 'verified_representative']) || $d['scope'] === 'all')) {
            $this->fail('scope', 'Record actual patient or verified representative authority for a specific record action.');
        }
        if ($d['kind'] === 'block' && ($d['scope'] !== 'all' || $d['authority_type'] !== 'patient_block')) {
            $this->fail('scope', 'Patient blocks apply to all ordinary clinical access.');
        }
        $start = CarbonImmutable::parse($d['starts_at'], 'Indian/Mauritius')->utc();
        $end = CarbonImmutable::parse($d['ends_at'], 'Indian/Mauritius')->utc();
        if ($start->lt(now()->subMinutes(5)) || $end->isPast()) {
            $this->fail('starts_at', 'Rules cannot backdate access. Choose a current or future period.');
        }
        $start = $start->max(now());
        DB::transaction(function () use ($p, $r, $d, $start, $end) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $existing = PatientAccessRule::where('request_id', $d['request_id'])->first();
            if ($existing) {
                abort_unless($existing->patient_id === $p->id, 409);

                return;
            }
            unset($d['attested']);
            PatientAccessRule::create([...$d, 'patient_id' => $p->id, 'starts_at' => $start, 'ends_at' => $end, 'recorded_by' => $r->user()->id]);
            Workspace::audit($r, 'patient.access.'.$d['kind'].'.recorded', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Access rule recorded. It takes effect only when all access checks pass.');
    }

    public function revoke(Request $r, int $id, int $rule)
    {
        $p = $this->patient($r, $id, true);

        return $this->revokeRule($r, $p, $rule);
    }

    private function revokeRule(Request $r, Patient $p, int $rule)
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $p, $rule, $d) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $a = PatientAccessRule::where('patient_id', $p->id)->lockForUpdate()->findOrFail($rule);
            // Only the patient can lift a patient block; staff may record one but cannot silently undo it.
            if ($a->kind === 'block') {
                abort_unless($p->user_id === $r->user()->id, 403, 'Only the linked patient can lift this block. Assisted lifting requires a separate reviewed recovery process.');
            }
            if (! $a->revoked_at) {
                $a->update(['revoked_at' => now(), 'revoked_by' => $r->user()->id, 'revocation_reason' => $d['reason']]);
                Workspace::audit($r, 'patient.access.'.$a->kind.'.revoked', $p->organisation_id, 'patients', $p->id);
            }
        });

        return back()->with('success', 'Access rule revoked.');
    }

    public function own(Request $r, int $id)
    {
        $p = Patient::where('user_id', $r->user()->id)->findOrFail($id);
        Workspace::audit($r, 'patient.own_record.viewed', $p->organisation_id, 'patients', $p->id);

        return Inertia::render('patients/access', ['patient' => $p->only(['id', 'name', 'reference']), 'rules' => $this->rules($p), 'history' => $this->history($p), 'summary' => PatientSummary::where('patient_id', $id)->first()?->only(['content', 'version', 'updated_at'])]);
    }

    public function ownRevoke(Request $r, int $id, int $rule)
    {
        $p = Patient::where('user_id', $r->user()->id)->findOrFail($id);
        abort_if(PatientAccessRule::where('patient_id', $p->id)->whereKey($rule)->where('kind', 'assignment')->exists(), 403, 'Block this professional or revoke their grant instead.');

        return $this->revokeRule($r, $p, $rule);
    }

    public function ownGrant(Request $r, int $id)
    {
        $p = Patient::where('user_id', $r->user()->id)->findOrFail($id);
        $d = $r->validate(['user_id' => 'required|integer', 'request_id' => 'required|uuid', 'scope' => ['required', Rule::in(PatientAccess::SCOPES)], 'reason' => 'required|string|max:2000', 'ends_at' => 'required|date|after:now']);
        DB::transaction(function () use ($p, $r, $d) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $existing = PatientAccessRule::where('request_id', $d['request_id'])->first();
            if ($existing) {
                abort_unless($existing->patient_id === $p->id && $existing->recorded_by === $r->user()->id, 409);

                return;
            }
            $end = CarbonImmutable::parse($d['ends_at'], 'Indian/Mauritius')->utc();
            $assignment = PatientAccess::current($p, $d['user_id'])->where('kind', 'assignment')->where('ends_at', '>=', $end)->firstOrFail();
            PatientAccessRule::create(['patient_id' => $p->id, 'user_id' => $d['user_id'], 'kind' => 'grant', 'scope' => $d['scope'], 'purpose' => $assignment->purpose, 'authority_type' => 'patient_consent', 'evidence' => 'Linked patient explicitly authorised '.$d['scope'].' for the specified record category. '.$d['reason'], 'wording_version' => 'patient-summary-authority-v1', 'starts_at' => now(), 'ends_at' => $end, 'recorded_by' => $r->user()->id, 'request_id' => $d['request_id']]);
            Workspace::audit($r, 'patient.access.grant.recorded', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Your scoped clinical record authority has been recorded. Existing blocks still take precedence.');
    }

    public function ownBlock(Request $r, int $id)
    {
        $p = Patient::where('user_id', $r->user()->id)->findOrFail($id);
        $d = $r->validate(['user_id' => 'required|integer', 'reason' => 'required|string|max:2000']);
        abort_unless(PatientAccessRule::where('patient_id', $id)->where('user_id', $d['user_id'])->exists(), 404);
        DB::transaction(function () use ($r, $p, $d) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if (PatientAccess::current($p, $d['user_id'])->where('kind', 'block')->exists()) {
                return;
            }
            PatientAccessRule::create(['patient_id' => $p->id, 'user_id' => $d['user_id'], 'kind' => 'block', 'scope' => 'all', 'purpose' => 'Patient access restriction', 'authority_type' => 'patient_block', 'evidence' => $d['reason'], 'wording_version' => 'patient-control-v1', 'starts_at' => now(), 'ends_at' => now()->addYears(100), 'recorded_by' => $r->user()->id, 'request_id' => (string) Str::uuid()]);
            Workspace::audit($r, 'patient.access.block.recorded', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'This professional is blocked from clinical summary access.');
    }
}
