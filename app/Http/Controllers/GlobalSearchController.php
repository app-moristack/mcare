<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CarePlan;
use App\Models\CareTask;
use App\Models\ClinicalNote;
use App\Models\ClinicalPublication;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\PatientAssessment;
use App\Models\Referral;
use App\Models\User;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $r)
    {
        $d = $r->validate(['q' => 'required|string|min:2|max:100']);
        $q = trim($d['q']);
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $u = $r->user();
        $memberships = Workspace::memberships($u);
        $orgs = $memberships->pluck('organisation_id')->unique();
        $providerOrgs = $memberships->whereIn('role', ['doctor', 'nurse', 'carer', 'physiotherapist'])->pluck('organisation_id')->unique();
        $org = Workspace::selectedOrganisation($r);
        $providerOrgs = $providerOrgs->filter(fn ($id) => (int) $id === $org);
        $can = fn ($a) => $orgs->contains($org) && $u->hasPermission($a, $org);
        $like = '%'.$q.'%';
        $results = [];
        $add = function ($category, $title, $subtitle, $url) use (&$results) {
            $results[] = ['category' => $category, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url];
        };
        $patients = Patient::where(function ($b) use ($u, $org, $can) {
            $b->where('user_id', $u->id);
            if ($can('patients.manage')) {
                $b->orWhere('organisation_id', $org);
            } elseif ($org) {
                $b->orWhere(fn ($a) => $a->where('organisation_id', $org)->whereIn('id', PatientAccessRule::where('user_id', $u->id)->where('kind', 'assignment')->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->select('patient_id')));
            }
        })
            ->where(fn ($b) => $b->where('name', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))->orderBy('name')->limit(40)->get(['id', 'organisation_id', 'user_id', 'name', 'reference', 'status']);
        foreach ($patients as $p) {
            $own = $p->user_id === $u->id;
            $operational = $p->organisation_id === $org && $can('patients.manage');
            if (! $own && ! $operational && ! collect(['summary.read', 'basic.read', 'nursing.read', 'notes.read', 'plans.read'])->contains(fn ($scope) => PatientAccess::allowed($u, $p, $scope))) {
                continue;
            }
            $add('Patients / clients', $p->name, $p->reference, $own ? '/my-care/patients/'.$p->id : ($operational || PatientAccess::allowed($u, $p, 'summary.read') ? '/patient-records/'.$p->id : '/patient-records/'.$p->id.(collect(['basic.read', 'nursing.read'])->contains(fn ($scope) => PatientAccess::allowed($u, $p, $scope)) ? '/assessments' : '/clinical')));
        }
        if ($can('team.manage')) {
            $users = User::where(fn ($b) => $b->whereHas('memberships', fn ($m) => $m->where('organisation_id', $org))->orWhereHas('application', fn ($a) => $a->where('organisation_id', $org)))->where(fn ($b) => $b->where('name', 'like', $like)->orWhere('email', 'like', $like))->orderBy('name')->limit(8)->get(['id', 'name', 'email']);
            foreach ($users as $v) {
                $add('Users / team', $v->name, $v->email, '/workspace/team?search='.urlencode($v->name));
            }
        }
        foreach (['referrals' => [Referral::class, 'referrals.manage'], 'appointments' => [Appointment::class, 'appointments.manage'], 'tasks' => [CareTask::class, 'tasks.manage'], 'invoices' => [Invoice::class, 'finance.manage']] as $module => [$model,$ability]) {
            $query = $model::query();
            $staff = $can($ability);
            if ($staff) {
                $query->where('organisation_id', $org);
            } elseif ($module === 'appointments') {
                $query->where(fn ($b) => $b->whereHas('patient', fn ($p) => $p->where('user_id', $u->id))->orWhere(fn ($v) => $v->where('provider_id', $u->id)->whereIn('organisation_id', $providerOrgs)));
            } elseif ($module === 'invoices') {
                $query->whereHas('patient', fn ($p) => $p->where('user_id', $u->id))->whereNotIn('status', ['draft', 'void']);
            } else {
                continue;
            }
            $field = match ($module) {
                'tasks' => 'title','invoices' => 'number','appointments' => 'service',default => 'discipline'
            };
            $query->where(fn ($b) => $b->where($field, 'like', $like)->orWhere('id', ctype_digit($q) ? (int) $q : 0)->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('reference', 'like', $like)));
            foreach ($query->with('patient:id,name')->latest('id')->limit(8)->get() as $v) {
                $url = $staff ? '/workspace/'.$module.'?record='.$v->id : ($module === 'appointments' && $v->provider_id === $u->id ? '/my-visits' : '/my-care');
                $add(ucfirst($module), $v->{$field}.' · #'.$v->id, ($v->patient?->name ?? 'Organisation task').' · '.$v->status, $url);
            }
        }
        $assessments = PatientAssessment::where(function ($b) use ($u, $org, $orgs) {
            $b->where(fn ($a) => $a->where('kind', 'basic')->whereHas('patient', fn ($p) => $p->where('user_id', $u->id)));
            if ($orgs->contains($org)) {
                $b->orWhereHas('patient', fn ($p) => $p->where('organisation_id', $org));
            }
        })->where(fn ($b) => $b->where('kind', 'like', $like)->orWhere('id', ctype_digit($q) ? (int) $q : 0)->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('reference', 'like', $like)))->with('patient')->latest()->limit(30)->get();
        foreach ($assessments as $a) {
            $own = $a->kind === 'basic' && $a->patient->user_id === $u->id;
            $operational = $a->patient->organisation_id === $org && $can('patients.manage');
            if (! $own && ! $operational && ! PatientAccess::allowed($u, $a->patient, $a->kind.'.read')) {
                continue;
            }
            $add('Assessments', ucfirst($a->kind).' assessment #'.$a->id, $a->patient->name.' · '.$a->status, ($own ? '/my-care/patients/' : '/patient-records/').$a->patient_id.'/assessments');
        }
        if ($orgs->contains($org)) {
            foreach (['notes' => ClinicalNote::class, 'plans' => CarePlan::class] as $kind => $model) {
                $query = $model::whereHas('patient', fn ($p) => $p->where('organisation_id', $org));
                $query->where(function ($b) use ($kind, $like, $q) {
                    $b->where('id', ctype_digit($q) ? (int) $q : 0)->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('reference', 'like', $like));
                    if ($kind === 'notes') {
                        $b->orWhere('note_type', 'like', $like);
                    } else {
                        $b->orWhereHas('episode', fn ($e) => $e->where('title', 'like', $like));
                    }
                });
                foreach ($query->with('patient')->latest('id')->limit(12)->get() as $a) {
                    $operational = $can('patients.manage');
                    if (! $operational && ! PatientAccess::allowed($u, $a->patient, $kind.'.read')) {
                        continue;
                    }
                    if (! $operational && $kind === 'notes' && $a->visibility === 'discipline_only' && ! array_intersect(PatientAccess::qualifiedRoles($u, $a->patient), [$a->discipline, 'clinical_admin'])) {
                        continue;
                    }
                    $add($kind === 'notes' ? 'Professional notes' : 'Care plans', ($kind === 'notes' ? 'Note' : 'Plan').' #'.$a->id, $a->patient->name.' · '.$a->status, '/patient-records/'.$a->patient_id.'/clinical');
                }
            }
        }
        foreach (ClinicalPublication::whereHas('note.patient', fn ($p) => $p->where('user_id', $u->id)->where('name', 'like', $like))->orWhereHas('plan.patient', fn ($p) => $p->where('user_id', $u->id)->where('name', 'like', $like))->latest('id')->limit(8)->get(['id', 'patient_id', 'clinical_note_id', 'care_plan_id']) as $publication) {
            $add('My published care', ($publication->clinical_note_id ? 'Reviewed visit summary' : 'Published care plan').' #'.$publication->id, 'Patient-visible summary only', '/my-care/patients/'.$publication->patient_id.'/clinical');
        }
        if (str_contains(mb_strtolower($u->name.' '.$u->email), mb_strtolower($q))) {
            $add('My account', $u->name, 'Personal account settings', '/settings/profile');
        }
        Workspace::audit($r, 'search.performed', $orgs->contains($org) ? $org : null);

        return response()->json(['results' => array_slice($results, 0, 50), 'organisation_selected' => $orgs->contains($org), 'truncated' => count($results) > 50])->header('Cache-Control', 'no-store, private');
    }
}
