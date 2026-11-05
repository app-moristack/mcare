<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\CareTask;
use App\Models\ClinicalNote;
use App\Models\ClinicalPublication;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\Referral;
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

class ClinicalRecordController extends Controller
{
    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function patient(Request $r, int $id): Patient
    {
        $p = Patient::findOrFail($id);
        $org = Workspace::selectedOrganisation($r);
        if ($org !== $p->organisation_id || ! Workspace::memberships($r->user())->contains('organisation_id', $org)) {
            Workspace::audit($r, 'clinical.context.denied', null);
            abort(404);
        }

        return $p;
    }

    private function allow(Request $r, Patient $p, string $kind, string $action): void
    {
        abort_unless(in_array($kind, ['notes', 'plans']), 404);
        PatientAccess::enforce($r, $p, $kind.'.read');
        if ($action !== 'read') {
            PatientAccess::enforce($r, $p, $kind.'.'.$action);
        }
    }

    private function noteTypes(Request $r, Patient $p): array
    {
        $roles = PatientAccess::qualifiedRoles($r->user(), $p);
        $types = [];
        if (in_array('doctor', $roles)) {
            $types = ['doctor', 'consultation', 'daily', 'wound'];
        }if (in_array('nurse', $roles)) {
            $types = array_merge($types, ['nursing', 'daily', 'wound']);
        }if (in_array('physiotherapist', $roles)) {
            $types = array_merge($types, ['physiotherapy']);
        }

        return array_values(array_unique($types));
    }

    private function noteVisible(Request $r, Patient $p, ClinicalNote $note): bool
    {
        return PatientAccess::allowed($r->user(), $p, 'notes.read') && ($note->visibility === 'clinical_team' || count(array_intersect(PatientAccess::qualifiedRoles($r->user(), $p), [$note->discipline, 'clinical_admin'])) > 0);
    }

    public function index(Request $r, int $id)
    {
        $p = $this->patient($r, $id);
        $permissions = [];
        foreach (['notes', 'plans'] as $kind) {
            $read = PatientAccess::allowed($r->user(), $p, $kind.'.read');
            $permissions[$kind] = ['read' => $read, 'write' => $read && PatientAccess::allowed($r->user(), $p, $kind.'.write'), 'publish' => $read && PatientAccess::allowed($r->user(), $p, $kind.'.publish')];
        }$permissions['plans']['approve'] = $permissions['plans']['read'] && PatientAccess::allowed($r->user(), $p, 'plans.approve');
        $types = $this->noteTypes($r, $p);
        $permissions['notes']['write'] = $permissions['notes']['write'] && count($types) > 0;
        $manage = $r->user()->hasPermission('patients.manage', $p->organisation_id);
        if (! $manage && ! $permissions['notes']['read'] && ! $permissions['plans']['read']) {
            Workspace::audit($r, 'clinical.access.denied', $p->organisation_id, 'patients', $id);
            abort(403);
        }
        $notes = ClinicalNote::where('patient_id', $id)->with('author:id,name')->latest('id')->get()->map(function ($n) use ($r, $p, $permissions) {
            $visible = $this->noteVisible($r, $p, $n);
            $row = $n->only(['id', 'status', 'note_type', 'discipline', 'visibility', 'appointment_id', 'original_id', 'version', 'signed_at', 'signed_by', 'created_at']);
            $row['author'] = $n->author;
            $row['kind'] = 'notes';
            if ($visible) {
                $row += ['content' => $n->content, 'addendum_reason' => $n->addendum_reason, 'content_hash' => $n->content_hash];
            }$row['editable'] = $visible && $permissions['notes']['write'] && $n->author_id === $r->user()->id && $n->status === 'draft';
            $row['can_addendum'] = $visible && $permissions['notes']['write'] && $n->author_id === $r->user()->id && $n->status === 'signed';
            $row['can_publish'] = $visible && $permissions['notes']['publish'] && $n->status === 'signed' && ! ClinicalPublication::where('clinical_note_id', $n->id)->exists();

            return $row;
        });
        $plans = CarePlan::where('patient_id', $id)->with(['author:id,name', 'episode:id,title,current_plan_id'])->latest('id')->get()->map(function ($v) use ($r, $permissions) {
            $row = $v->only(['id', 'episode_id', 'status', 'previous_id', 'revision_number', 'version', 'signed_at', 'approved_at', 'approved_by', 'review_due_at', 'review_task_id', 'created_at']);
            $row['kind'] = 'plans';
            $row['author'] = $v->author;
            $row['episode'] = $v->episode;
            if ($permissions['plans']['read']) {
                $row['content'] = $v->content;
                $row['revision_reason'] = $v->revision_reason;
                $row['content_hash'] = $v->content_hash;
                $row['events'] = DB::table('care_plan_events')->leftJoin('users', 'users.id', '=', 'care_plan_events.actor_id')->where('care_plan_id', $v->id)->orderBy('care_plan_events.id')->get(['care_plan_events.id', 'care_plan_events.action', 'care_plan_events.reason', 'care_plan_events.created_at', 'users.name'])->map(fn ($event) => ['id' => $event->id, 'action' => $event->action, 'reason' => Crypt::decryptString($event->reason), 'created_at' => $event->created_at, 'actor' => $event->name]);
            }$row['editable'] = $permissions['plans']['write'] && $v->author_id === $r->user()->id && $v->status === 'draft';
            $row['can_revise'] = $permissions['plans']['write'] && in_array($v->status, ['active', 'paused']) && $v->episode->current_plan_id === $v->id;
            $row['can_approve'] = $permissions['plans']['approve'] && $v->status === 'review' && $v->author_id !== $r->user()->id;
            $row['can_transition'] = $permissions['plans']['approve'] && in_array($v->status, ['active', 'paused']) && $v->episode->current_plan_id === $v->id;
            $row['can_publish'] = $permissions['plans']['publish'] && in_array($v->status, ['active', 'paused']) && ! ClinicalPublication::where('care_plan_id', $v->id)->exists();

            return $row;
        });
        foreach (['notes', 'plans'] as $kind) {
            if ($permissions[$kind]['read']) {
                Workspace::audit($r, 'clinical.'.$kind.'.viewed', $p->organisation_id, 'patients', $id);
            }
        }

        return Inertia::render('clinical/index', ['patient' => $p->only(['id', 'name', 'reference']), 'manage' => $manage, 'permissions' => $permissions, 'noteTypes' => $types, 'rows' => array_merge($notes->all(), $plans->all()), 'schema' => config('clinical.forms'), 'episodes' => CareEpisode::where('patient_id', $id)->get(['id', 'title', 'current_plan_id']), 'visits' => Appointment::where('patient_id', $id)->where('provider_id', $r->user()->id)->whereIn('status', ['confirmed', 'arrived', 'completed', 'aborted'])->get(['id', 'service', 'starts_at']), 'referrals' => Referral::where('patient_id', $id)->get(['id', 'discipline']), 'staff' => Workspace::staff($p->organisation_id)->whereIn('id', PatientAccessRule::where('patient_id', $id)->where('kind', 'assignment')->whereIn('role', ['doctor', 'nurse', 'physiotherapist', 'carer'])->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->select('user_id'))->select('id', 'name')->orderBy('name')->get(), 'reviewBackups' => Workspace::staff($p->organisation_id)->get()->filter(fn ($u) => $u->id !== $r->user()->id && $u->hasPermission('tasks.manage', $p->organisation_id))->values()->map(fn ($u) => $u->only(['id', 'name']))]);
    }

    private function visit(Request $r, Patient $p, int $id): Appointment
    {
        return Appointment::where('patient_id', $p->id)->where('organisation_id', $p->organisation_id)->where('provider_id', $r->user()->id)->whereIn('status', ['confirmed', 'arrived', 'completed', 'aborted'])->findOrFail($id);
    }

    public function store(Request $r, int $id, string $kind)
    {
        $p = $this->patient($r, $id);
        $this->allow($r, $p, $kind, 'write');
        $r->validate(['request_id' => 'required|uuid']);
        DB::transaction(function () use ($r, $p, $kind) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), $kind, 'write');
            $model = $kind === 'notes' ? ClinicalNote::class : CarePlan::class;
            $old = $model::where('request_id', $r->request_id)->first();
            if ($old) {
                abort_unless($old->patient_id === $p->id && $old->author_id === $r->user()->id, 409);

                return;
            }
            if ($kind === 'notes') {
                $d = $r->validate(['note_type' => ['required', Rule::in($this->noteTypes($r, $p))], 'visibility' => ['required', Rule::in(['clinical_team', 'discipline_only'])], 'appointment_id' => 'required|integer', 'original_id' => 'nullable|integer', 'addendum_reason' => 'nullable|string|max:3000']);
                $this->visit($r, $p, $d['appointment_id']);
                if (! empty($d['original_id'])) {
                    $original = ClinicalNote::where('patient_id', $p->id)->where('status', 'signed')->findOrFail($d['original_id']);
                    abort_unless($original->author_id === $r->user()->id && $this->noteVisible($r, $p, $original), 403);
                    if (empty($d['addendum_reason'])) {
                        $this->fail('addendum_reason', 'Explain the correction. The signed original will remain unchanged.');
                    }
                }
                $discipline = match ($d['note_type']) {
                    'doctor','consultation' => 'doctor','physiotherapy' => 'physiotherapist',default => in_array('nurse', PatientAccess::qualifiedRoles($r->user(), $p)) ? 'nurse' : 'doctor'
                };
                ClinicalNote::create([...$d, 'patient_id' => $p->id, 'author_id' => $r->user()->id, 'discipline' => $discipline, 'content' => [], 'request_id' => $r->request_id]);
            } else {
                $d = $r->validate(['episode_id' => 'nullable|integer', 'episode_title' => 'nullable|string|max:255', 'referral_id' => 'nullable|integer', 'previous_id' => 'nullable|integer', 'revision_reason' => 'nullable|string|max:3000']);
                $previous = null;
                if (! empty($d['previous_id'])) {
                    $previous = CarePlan::where('patient_id', $p->id)->whereIn('status', ['active', 'paused'])->findOrFail($d['previous_id']);
                    if (empty($d['revision_reason'])) {
                        $this->fail('revision_reason', 'Record why this plan version is being replaced.');
                    }$episode = CareEpisode::where('patient_id', $p->id)->lockForUpdate()->findOrFail($previous->episode_id);
                    abort_unless($episode->current_plan_id === $previous->id, 409, 'The current plan changed. Reload before revising.');
                } elseif (! empty($d['episode_id'])) {
                    $episode = CareEpisode::where('patient_id', $p->id)->lockForUpdate()->findOrFail($d['episode_id']);
                    if ($episode->current_plan_id) {
                        $this->fail('episode_id', 'Revise the current approved plan instead of creating another active plan.');
                    }
                } else {
                    if (empty($d['episode_title'])) {
                        $this->fail('episode_title', 'Name the care episode.');
                    }if (! empty($d['referral_id'])) {
                        Referral::where('patient_id', $p->id)->findOrFail($d['referral_id']);
                    }$episode = CareEpisode::create(['patient_id' => $p->id, 'referral_id' => $d['referral_id'] ?? null, 'title' => $d['episode_title'], 'created_by' => $r->user()->id, 'request_id' => $r->request_id]);
                }
                CarePlan::create(['patient_id' => $p->id, 'episode_id' => $episode->id, 'author_id' => $r->user()->id, 'previous_id' => $previous?->id, 'revision_number' => CarePlan::where('episode_id', $episode->id)->max('revision_number') + 1, 'revision_reason' => $d['revision_reason'] ?? null, 'content' => $previous?->content ?? [], 'request_id' => $r->request_id]);
            }Workspace::audit($r, 'clinical.'.$kind.'.draft_created', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Clinical draft created.');
    }

    private function validateContent(Request $r, string $kind, bool $final): array
    {
        $schema = config('clinical.forms.'.$kind);
        $keys = array_merge(array_column(array_merge(...array_column($schema['sections'], 'fields')), 'key'), array_keys($schema['children']));
        $rules = ['content' => 'required|array:'.implode(',', $keys)];
        foreach ($schema['sections'] as $s) {
            foreach ($s['fields'] as $f) {
                $rr = [$final && $f['required'] ? 'required' : 'nullable', in_array($f['type'], ['number', 'staff']) ? 'numeric' : 'string'];
                if (! in_array($f['type'], ['number', 'staff'])) {
                    $rr[] = 'max:5000';
                }if ($f['choices']) {
                    $rr[] = Rule::in(explode(',', $f['choices']));
                }if ($f['type'] === 'datetime-local') {
                    $rr[] = 'date';
                }$rules['content.'.$f['key']] = $rr;
            }
        }
        foreach ($schema['children'] as $key => $fields) {
            $rules['content.'.$key] = ($final ? 'required' : 'sometimes').'|array|max:40';
            $rules['content.'.$key.'.*'] = 'array:'.implode(',', array_column($fields, 'key'));
            foreach ($fields as $f) {
                $rr = [$final && $f['required'] ? 'required' : 'nullable', $f['type'] === 'staff' ? 'integer' : 'string', 'max:3000'];
                if ($f['type'] === 'staff') {
                    $rr = [$final ? 'required' : 'nullable', 'integer', 'min:1'];
                }if ($f['choices']) {
                    $rr[] = Rule::in(explode(',', $f['choices']));
                }$rules['content.'.$key.'.*.'.$f['key']] = $rr;
            }
        }

        return $r->validate($rules)['content'];
    }

    private function noteFinal(Request $r, Patient $p, ClinicalNote $note, array $c): array
    {
        abort_unless(in_array($note->note_type, $this->noteTypes($r, $p)), 403);
        $this->visit($r, $p, $note->appointment_id);
        $actual = CarbonImmutable::parse($c['encounter_at'], 'Indian/Mauritius')->utc();
        if ($actual->isFuture()) {
            $this->fail('content.encounter_at', 'Actual encounter time cannot be in the future.');
        }if ($actual->lt(now()->subHour()) && empty($c['late_entry_reason'])) {
            $this->fail('content.late_entry_reason', 'Explain the late entry.');
        }if (empty($c['subjective']) && empty($c['unable_reason'])) {
            $this->fail('content.subjective', 'Record the patient report or why it could not be obtained.');
        }if ($c['follow_up_needed'] === 'yes' && (empty($c['follow_up_owner']) || empty($c['follow_up_due']))) {
            $this->fail('content.follow_up_owner', 'Record the responsible owner and deadline.');
        }

        return ['status' => 'signed', 'signed_by' => $r->user()->id, 'signed_at' => now(), 'encounter_at' => $actual, 'content_hash' => $note->digest($c)];
    }

    private function planFinal(Patient $p, array $c): void
    {
        foreach (['visits_per_week' => 100, 'session_minutes' => 1440, 'duration_weeks' => 520] as $key => $max) {
            if (filter_var($c[$key], FILTER_VALIDATE_INT) === false || $c[$key] < 1 || $c[$key] > $max) {
                $this->fail('content.'.$key, 'Enter a positive whole number within the service planning limit.');
            }
        }
        if ($c['patient_agreement'] === 'capacity_route' && empty($c['capacity_evidence'])) {
            $this->fail('content.capacity_evidence', 'Record the verified capacity / representative basis.');
        }
        if (CarbonImmutable::parse($c['review_due'], 'Indian/Mauritius')->isPast()) {
            $this->fail('content.review_due', 'Choose a future review deadline.');
        }
        foreach ($c['interventions'] as $i => $entry) {
            $u = Workspace::staff($p->organisation_id)->find($entry['responsible_id']);
            if (! $u || ! $u->memberships()->where('organisation_id', $p->organisation_id)->where('role', $entry['discipline'])->where('status', 'active')->whereNotNull('approved_by')->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists() || ! PatientAccess::current($p, $u->id)->where('kind', 'assignment')->where('role', $entry['discipline'])->exists()) {
                $this->fail('content.interventions.'.$i.'.responsible_id', 'Choose a current named professional assigned in this discipline.');
            }
            if (! $u->application()->where('organisation_id', $p->organisation_id)->where('status', 'approved')->where('discipline', $entry['discipline'])->whereDate('credential_expires_at', '>=', now(config('mauricare.time_zone'))->toDateString())->exists()) {
                $this->fail('content.interventions.'.$i.'.responsible_id', 'Current reviewed credentials are required for the responsible professional.');
            }
        }
    }

    public function update(Request $r, int $id, string $kind, int $record)
    {
        $p = $this->patient($r, $id);
        $this->allow($r, $p, $kind, 'write');
        $d = $r->validate(['version' => 'required|integer', 'action' => ['required', Rule::in(['save', 'sign'])]]);
        $final = $d['action'] === 'sign';
        if ($final) {
            $r->validate(['password' => 'required|current_password', 'declaration' => 'accepted']);
        }$content = $this->validateContent($r, $kind, $final);
        $saved = DB::transaction(function () use ($r, $p, $kind, $record, $d, $content, $final) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), $kind, 'write');
            $model = $kind === 'notes' ? ClinicalNote::class : CarePlan::class;
            $a = $model::where('patient_id', $p->id)->lockForUpdate()->findOrFail($record);
            abort_unless($a->author_id === $r->user()->id, 403);
            if ($kind === 'notes') {
                abort_unless($this->noteVisible($r, $p, $a), 403);
            }abort_unless($a->version === (int) $d['version'], 409, 'This record changed. Reload to reconcile your draft.');
            abort_unless($a->status === 'draft', 409, 'Signed content is immutable. Create an addendum or new plan version.');
            $changes = ['content' => $content, 'version' => $a->version + 1];
            if ($final) {
                if ($kind === 'notes') {
                    $changes += $this->noteFinal($r, $p, $a, $content);
                } else {
                    $this->planFinal($p, $content);
                    $changes += ['status' => 'review', 'signed_by' => $r->user()->id, 'signed_at' => now(), 'review_due_at' => CarbonImmutable::parse($content['review_due'], 'Indian/Mauritius')->utc(), 'content_hash' => $a->digest($content)];
                }
            }
            $a->update($changes);
            Workspace::audit($r, 'clinical.'.$kind.'.'.$d['action'], $p->organisation_id, 'patients', $p->id);

            return $a;
        });
        if ($r->expectsJson()) {
            return response()->json($saved->only(['id', 'version', 'status', 'signed_at']))->header('Cache-Control', 'no-store, private');
        }

        return back()->with('success', $final ? 'Clinical content signed and locked.' : 'Draft saved.');
    }

    private function event(Request $r, CarePlan $p, string $action, string $reason): void
    {
        DB::table('care_plan_events')->insert(['care_plan_id' => $p->id, 'actor_id' => $r->user()->id, 'action' => $action, 'reason' => Crypt::encryptString($reason), 'created_at' => now()]);
    }

    private function cancelReview(CarePlan $p, string $evidence): void
    {
        if (! $p->review_task_id) {
            return;
        }$task = CareTask::lockForUpdate()->find($p->review_task_id);
        if ($task && ! in_array($task->status, ['completed', 'cancelled'])) {
            $task->update(['status' => 'cancelled', 'completion_evidence' => $evidence, 'version' => $task->version + 1]);
            DB::table('operation_notifications')->where('deduplication_key', 'like', 'overdue:'.$task->id.':%')->update(['read_at' => now()]);
        }
    }

    public function transition(Request $r, int $id, int $record)
    {
        $p = $this->patient($r, $id);
        $this->allow($r, $p, 'plans', 'approve');
        $d = $r->validate(['version' => 'required|integer', 'action' => ['required', Rule::in(['approve', 'pause', 'resume', 'complete'])], 'reason' => 'required|string|max:3000', 'review_backup_id' => 'nullable|integer', 'password' => 'required|current_password', 'declaration' => 'accepted']);
        DB::transaction(function () use ($r, $p, $record, $d) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), 'plans', 'approve');
            $plan = CarePlan::where('patient_id', $p->id)->lockForUpdate()->findOrFail($record);
            $episode = CareEpisode::lockForUpdate()->findOrFail($plan->episode_id);
            abort_unless($plan->version === (int) $d['version'], 409);
            abort_unless(hash_equals($plan->content_hash ?? '', $plan->digest($plan->content)), 409, 'Content integrity check failed.');
            if ($d['action'] === 'approve') {
                abort_unless($plan->status === 'review' && $plan->author_id !== $r->user()->id, 403, 'A separate clinical lead must approve a signed proposal.');
                abort_unless($episode->current_plan_id === $plan->previous_id, 409, 'Another plan became current. Create a revision of that plan.');
                $this->planFinal($p, $plan->content);
                if ($plan->content['patient_agreement'] === 'declined') {
                    $this->fail('reason', 'A declined plan cannot become active. Agree an appropriate alternative in a new proposal.');
                }
                if ($episode->current_plan_id) {
                    $previous = CarePlan::lockForUpdate()->findOrFail($episode->current_plan_id);
                    $previous->update(['status' => 'superseded', 'version' => $previous->version + 1]);
                    $this->cancelReview($previous, 'Superseded by approved care plan #'.$plan->id);
                    $this->event($r, $previous, 'superseded', 'Replaced by plan #'.$plan->id.'. '.$d['reason']);
                }
                $backup = Workspace::staff($p->organisation_id)->find((int) ($d['review_backup_id'] ?? 0));
                if (! $backup || $backup->id === $r->user()->id || ! $backup->hasPermission('tasks.manage', $p->organisation_id)) {
                    $this->fail('review_backup_id', 'Choose a distinct active operational task owner as review backup.');
                }
                $task = CareTask::create(['organisation_id' => $p->organisation_id, 'patient_id' => $p->id, 'title' => 'Review care plan #'.$plan->id, 'description' => 'Routine approved plan review. Open the authorised clinical workspace; no clinical narrative is included in this task.', 'priority' => 'routine', 'status' => 'new', 'owner_id' => $r->user()->id, 'backup_id' => $backup->id, 'created_by' => $r->user()->id, 'due_at' => $plan->review_due_at, 'request_id' => (string) Str::uuid()]);
                $plan->update(['status' => 'active', 'approved_by' => $r->user()->id, 'approved_at' => now(), 'review_task_id' => $task->id, 'version' => $plan->version + 1]);
                $episode->update(['current_plan_id' => $plan->id]);
            } else {
                abort_unless($episode->current_plan_id === $plan->id, 409, 'Only the current approved plan can change state.');
                $target = match ($d['action']) {
                    'pause' => $plan->status === 'active' ? 'paused' : null,'resume' => $plan->status === 'paused' ? 'active' : null,'complete' => in_array($plan->status, ['active', 'paused']) ? 'completed' : null
                };
                abort_unless($target, 409, 'Invalid plan transition.');
                $plan->update(['status' => $target, 'version' => $plan->version + 1]);
                if ($target === 'completed') {
                    $episode->update(['current_plan_id' => null]);
                    $this->cancelReview($plan, 'Care plan completed by an authorised clinical lead.');
                }
            }$this->event($r, $plan, $d['action'], $d['reason']);
            Workspace::audit($r, 'clinical.plans.'.$d['action'], $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Care plan state updated.');
    }

    public function publish(Request $r, int $id, string $kind, int $record)
    {
        $p = $this->patient($r, $id);
        $this->allow($r, $p, $kind, 'publish');
        $d = $r->validate(['request_id' => 'required|uuid', 'version' => 'required|integer', 'password' => 'required|current_password', 'declaration' => 'accepted', 'summary' => 'required|string|max:5000', 'shared_goals' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($r, $p, $kind, $record, $d) {
            Patient::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->allow($r, $p->fresh(), $kind, 'publish');
            $model = $kind === 'notes' ? ClinicalNote::class : CarePlan::class;
            $a = $model::where('patient_id', $p->id)->lockForUpdate()->findOrFail($record);
            abort_unless($a->version === (int) $d['version'], 409);
            abort_unless($kind === 'notes' ? $a->status === 'signed' : in_array($a->status, ['active', 'paused']), 409, 'Only signed notes or approved current plans can be published.');
            if ($kind === 'notes') {
                abort_unless($this->noteVisible($r, $p, $a), 403);
            }abort_unless(hash_equals($a->content_hash ?? '', $a->digest($a->content)), 409, 'Content integrity check failed.');
            $column = $kind === 'notes' ? 'clinical_note_id' : 'care_plan_id';
            $old = ClinicalPublication::where('request_id', $d['request_id'])->first();
            if ($old) {
                abort_unless($old->patient_id === $p->id && $old->{$column} === $a->id, 409);

                return;
            }if (ClinicalPublication::where($column, $a->id)->exists()) {
                $this->fail('summary', 'This version already has a published summary. Use an addendum / replacement version to correct it.');
            }
            ClinicalPublication::create(['patient_id' => $p->id, $column => $a->id, 'content' => ['summary' => $d['summary'], 'shared_goals' => $kind === 'plans' ? ($d['shared_goals'] ?? '') : null], 'source_hash' => $a->content_hash, 'published_by' => $r->user()->id, 'published_at' => now(), 'request_id' => $d['request_id']]);
            Workspace::audit($r, 'clinical.'.$kind.'.published', $p->organisation_id, 'patients', $p->id);
        });

        return back()->with('success', 'Reviewed patient summary published. Private record contents were not copied to the portal.');
    }

    public function own(Request $r, int $id)
    {
        $p = Patient::where('user_id', $r->user()->id)->findOrFail($id);
        $items = ClinicalPublication::where('patient_id', $id)->with(['note:id,original_id,status', 'plan:id,status,episode_id,revision_number'])->latest('published_at')->get()->map(fn ($v) => ['id' => $v->id, 'kind' => $v->clinical_note_id ? 'notes' : 'plans', 'record_id' => $v->clinical_note_id ?? $v->care_plan_id, 'original_id' => $v->note?->original_id, 'status' => $v->clinical_note_id ? 'signed' : $v->plan?->status, 'revision_number' => $v->plan?->revision_number, 'content' => $v->content, 'published_at' => $v->published_at]);
        Workspace::audit($r, 'clinical.patient_publications.viewed', $p->organisation_id, 'patients', $id);

        return Inertia::render('clinical/portal', ['patient' => $p->only(['id', 'name', 'reference']), 'items' => $items]);
    }
}
