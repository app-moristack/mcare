<?php

namespace App\Http\Controllers;

use App\Models\AccountApplication;
use App\Models\Appointment;
use App\Models\CareTask;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Referral;
use App\Models\User;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OperationsController extends Controller
{
    private const MODELS = ['patients' => Patient::class, 'referrals' => Referral::class, 'appointments' => Appointment::class, 'tasks' => CareTask::class, 'invoices' => Invoice::class];

    private function ability(string $module): string
    {
        return match ($module) {
            'overview' => 'dashboard.view','team' => 'team.manage','audit' => 'audit.view','invoices' => 'finance.manage',default => $module.'.manage'
        };
    }

    private function org(Request $r, string $module): int
    {
        abort_unless(in_array($module, ['overview', 'team', 'audit', ...array_keys(self::MODELS)]), 404);

        return Workspace::organisation($r, $this->ability($module));
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Indian/Mauritius')->utc();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function member(int $org, int $id): void
    {
        if (! Workspace::staff($org)->whereKey($id)->exists()) {
            $this->fail('owner_id', 'Select an active member of this organisation.');
        }
    }

    private function patient(int $org, int $id): Patient
    {
        return Patient::where('organisation_id', $org)->findOrFail($id);
    }

    public function index(Request $r, string $module = 'overview')
    {
        $org = $this->org($r, $module);
        $search = mb_substr((string) $r->query('search', ''), 0, 100);
        $status = (string) $r->query('status', '');
        $query = null;
        if (isset(self::MODELS[$module])) {
            $query = self::MODELS[$module]::where('organisation_id', $org);
            if ($r->filled('record')) {
                $query->whereKey($r->integer('record'));
            }
            if ($module === 'patients') {
                $query->select(['id', 'organisation_id', 'reference', 'name', 'date_of_birth', 'phone', 'email', 'locality', 'address', 'identity_status', 'status', 'authority_type', 'authority_evidence', 'version']);
            }
            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($search !== '') {
                if ($module === 'patients') {
                    $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('reference', 'like', '%'.$search.'%'));
                } else {
                    $query->whereHas('patient', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));
                }
            }
            if ($module !== 'patients') {
                $query->with('patient:id,name,reference');
            }
            if ($module === 'appointments') {
                $query->with(['provider:id,name', 'attendance']);
            }
            if ($module === 'tasks') {
                $query->with(['owner:id,name', 'backup:id,name']);
            }
            if ($module === 'invoices') {
                $query->with(['items', 'payments']);
            }
        }
        $rows = $query?->latest('id')->paginate(15)->withQueryString();
        $staff = Workspace::staff($org)->select('id', 'name')->orderBy('name')->get();
        $lookups = [];
        if (in_array($module, ['referrals', 'appointments', 'tasks', 'invoices'])) {
            $lookups['patients'] = Patient::where('organisation_id', $org)->where('status', 'active')->select('id', 'name')->orderBy('name')->limit(500)->get();
        }
        if ($module === 'appointments') {
            $lookups['referrals'] = Referral::where('organisation_id', $org)->whereIn('status', ['awaiting_match', 'assessment_booked'])->with('patient:id,name')->get()->map(fn ($x) => ['id' => $x->id, 'name' => '#'.$x->id.' · '.$x->patient->name, 'patient_id' => $x->patient_id]);
            $lookups['providers'] = Workspace::staff($org)->when(config('mauricare.workforce_mfa_required'), fn ($q) => $q->whereNotNull('mfa_confirmed_at'))->whereHas('memberships', fn ($q) => $q->where('organisation_id', $org)->whereIn('role', ['doctor', 'nurse', 'carer', 'physiotherapist'])->where('status', 'active'))->select('id', 'name')->get();
        }
        $summary = $module === 'overview' ? [
            'patients' => Patient::where('organisation_id', $org)->count(),
            'referrals' => Referral::where('organisation_id', $org)->whereNotIn('status', ['cancelled'])->count(),
            'appointments' => Appointment::where('organisation_id', $org)->whereDate('starts_at', '>=', now()->utc()->toDateString())->whereIn('status', ['booked', 'confirmed'])->count(),
            'overdue_tasks' => CareTask::where('organisation_id', $org)->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<', now())->count(),
        ] : null;
        $applications = $module === 'team' ? AccountApplication::where('organisation_id', $org)->with(['user:id,name,email,mfa_confirmed_at'])->latest()->paginate(15) : null;
        $members = $module === 'team' ? DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('organisation_id', $org)->when($search !== '', fn ($q) => $q->where(fn ($b) => $b->where('users.name', 'like', '%'.$search.'%')->orWhere('users.email', 'like', '%'.$search.'%')))->select('memberships.*', 'users.name', 'users.email')->orderBy('users.name')->get() : [];
        $events = $module === 'audit' ? DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')->where('audit_events.organisation_id', $org)->select('audit_events.*', 'users.name as actor_name')->orderByDesc('audit_events.id')->paginate(30) : null;
        Workspace::audit($r, 'operations.'.$module.'.viewed', $org);

        return Inertia::render('operations/index', ['module' => $module, 'rows' => $rows, 'summary' => $summary, 'staff' => $staff, 'lookups' => $lookups, 'applications' => $applications, 'members' => $members, 'events' => $events, 'filters' => ['search' => $search, 'status' => $status],
            'upcoming' => $module === 'overview' ? Appointment::where('organisation_id', $org)->whereIn('status', ['booked', 'confirmed'])->with(['patient:id,name', 'provider:id,name'])->orderBy('starts_at')->limit(5)->get() : [],
            'overdue' => $module === 'overview' ? CareTask::where('organisation_id', $org)->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<', now())->with('owner:id,name')->orderBy('due_at')->limit(5)->get() : []]);
    }

    public function store(Request $r, string $module)
    {
        $org = $this->org($r, $module);
        abort_unless(isset(self::MODELS[$module]), 404);
        $r->validate(['request_id' => ['required', 'uuid']]);
        $record = DB::transaction(function () use ($r, $module, $org) {
            $existing = self::MODELS[$module]::where('organisation_id', $org)->where('request_id', $r->request_id)->first();
            if ($existing) {
                return $existing;
            }
            $data = $this->validateCreate($r, $module, $org);
            $record = self::MODELS[$module]::create([...$data, 'organisation_id' => $org, 'request_id' => $r->request_id]);
            if ($module === 'invoices') {
                foreach ($r->input('items') as $item) {
                    $record->items()->create(['description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['unit_price_minor'], 'total_minor' => $item['quantity'] * $item['unit_price_minor']]);
                }
            }
            Workspace::audit($r, $module.'.created', $org, $module, $record->id);

            return $record;
        }, 3);

        return back()->with('success', ucfirst($module).' record #'.$record->id.' saved.');
    }

    private function validateCreate(Request $r, string $module, int $org): array
    {
        if ($module === 'patients') {
            $data = $r->validate(['name' => 'required|string|max:255', 'date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'email' => 'nullable|email|max:255', 'locality' => 'required|string|max:255', 'address' => 'nullable|string|max:2000', 'authority_type' => ['required', Rule::in(['patient_request', 'representative', 'referral_authority'])], 'authority_evidence' => 'required|string|max:2000']);

            return [...$data, 'reference' => (string) Str::uuid()];
        }
        $r->validate(['patient_id' => [$module === 'tasks' ? 'nullable' : 'required', 'integer']]);
        if ($r->patient_id) {
            $this->patient($org, (int) $r->patient_id);
        }
        if (in_array($module, ['referrals', 'tasks'])) {
            $r->validate(['owner_id' => 'required|integer', 'backup_id' => 'required|integer|different:owner_id', 'due_at' => 'required|date']);
            $this->member($org, (int) $r->owner_id);
            $this->member($org, (int) $r->backup_id);
        }
        if ($module === 'referrals') {
            $data = $r->validate(['patient_id' => 'required|integer', 'source' => ['required', Rule::in(['coordinator', 'clinic', 'patient', 'professional'])], 'reason' => 'required|string|max:5000', 'discipline' => ['required', Rule::in(['doctor', 'nurse', 'carer', 'physiotherapist'])], 'priority' => ['required', Rule::in(['routine', 'urgent', 'unknown'])], 'owner_id' => 'required|integer', 'backup_id' => 'required|integer', 'due_at' => 'required|date']);

            return [...$data, 'due_at' => $this->date($data['due_at'])];
        }
        if ($module === 'tasks') {
            $data = $r->validate(['patient_id' => 'nullable|integer', 'title' => 'required|string|max:255', 'description' => 'required|string|max:5000', 'priority' => ['required', Rule::in(['routine', 'high', 'critical'])], 'owner_id' => 'required|integer', 'backup_id' => 'required|integer', 'due_at' => 'required|date']);

            return [...$data, 'created_by' => $r->user()->id, 'due_at' => $this->date($data['due_at'])];
        }
        if ($module === 'appointments') {
            $data = $r->validate(['patient_id' => 'required|integer', 'referral_id' => 'required|integer', 'provider_id' => 'required|integer', 'service' => 'required|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'location_type' => ['required', Rule::in(['home', 'clinic'])], 'location' => 'required|string|max:2000']);
            $referral = Referral::where('organisation_id', $org)->where('patient_id', $data['patient_id'])->findOrFail($data['referral_id']);
            if (! in_array($referral->status, ['awaiting_match', 'assessment_booked']) || ! $referral->triage_evidence) {
                $this->fail('referral_id', 'A triaged referral awaiting scheduling is required.');
            }
            $provider = User::whereKey($data['provider_id'])->lockForUpdate()->firstOrFail();
            $this->member($org, $provider->id);
            if ((config('mauricare.workforce_mfa_required') && ! $provider->mfa_confirmed_at) || ! $provider->memberships()->where('organisation_id', $org)->where('role', $referral->discipline)->where('status', 'active')->whereNotNull('approved_by')->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists()) {
                $this->fail('provider_id', 'Select an approved provider in the requested discipline.');
            }
            if ($provider->account_kind !== 'admin') {
                $app = $provider->application;
                if (! $app || $app->organisation_id !== $org || $app->status !== 'approved' || ! $app->credential_expires_at || $app->credential_expires_at < now()->toDateString()) {
                    $this->fail('provider_id', 'Current credential approval is required.');
                }
            }
            $start = $this->date($data['starts_at']);
            $end = $this->date($data['ends_at']);
            if ($start->isPast()) {
                $this->fail('starts_at', 'Choose a future appointment time.');
            }
            if (Appointment::where('provider_id', $provider->id)->whereNotIn('status', ['cancelled', 'aborted'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first()) {
                $this->fail('starts_at', 'This provider already has an overlapping appointment.');
            }

            return [...$data, 'starts_at' => $start, 'ends_at' => $end];
        }
        $data = $r->validate(['patient_id' => 'required|integer', 'due_date' => 'required|date_format:Y-m-d', 'items' => 'required|array|min:1|max:30', 'items.*.description' => 'required|string|max:255', 'items.*.quantity' => 'required|integer|min:1|max:10000', 'items.*.unit_price_minor' => 'required|integer|min:0|max:100000000']);

        if (collect($data['items'])->sum(fn ($i) => $i['quantity'] * $i['unit_price_minor']) <= 0) {
            $this->fail('items', 'Invoice total must be greater than zero.');
        }

        return ['patient_id' => $data['patient_id'], 'due_date' => $data['due_date'], 'number' => 'MC-'.strtoupper(Str::random(12)), 'total_minor' => collect($data['items'])->sum(fn ($i) => $i['quantity'] * $i['unit_price_minor'])];
    }

    public function update(Request $r, string $module, int $id)
    {
        $org = $this->org($r, $module);
        abort_unless(isset(self::MODELS[$module]), 404);
        if ($module === 'patients' && $r->input('action') === 'details') {
            $r->validate(['version' => 'required|integer']);
            DB::transaction(function () use ($r, $id, $org) {
                $record = Patient::where('organisation_id', $org)->lockForUpdate()->findOrFail($id);
                abort_unless($record->version === (int) $r->version, 409, 'This record changed. Refresh before saving.');
                $data = $this->validateCreate($r, 'patients', $org);
                unset($data['reference']);
                $record->update([...$data, 'version' => $record->version + 1]);
                Workspace::audit($r, 'patients.updated', $org, 'patients', $id);
            }, 3);

            return back()->with('success', 'Patient details updated.');
        }
        $r->validate(['version' => 'required|integer', 'status' => 'required|string']);
        DB::transaction(function () use ($r, $module, $id, $org) {
            $record = self::MODELS[$module]::where('organisation_id', $org)->lockForUpdate()->findOrFail($id);
            if ($record->version !== (int) $r->version) {
                abort(409, 'This record changed. Refresh before trying again.');
            }
            $status = $r->status;
            $data = ['status' => $status];
            if ($module === 'patients') {
                $r->validate(['status' => [Rule::in(['active', 'inactive'])]]);
            } elseif ($module === 'referrals') {
                $paths = ['received' => ['review', 'cancelled'], 'review' => ['awaiting_consent', 'awaiting_match', 'cancelled'], 'awaiting_consent' => ['awaiting_match', 'cancelled'], 'awaiting_match' => ['assessment_booked', 'cancelled'], 'assessment_booked' => ['review', 'cancelled']];
                if (! in_array($status, $paths[$record->status] ?? [])) {
                    $this->fail('status', 'This referral transition is not allowed.');
                }
                if ($status === 'awaiting_match') {
                    if (! $r->user()->memberships()->where('organisation_id', $org)->where('role', 'clinical_admin')->where('status', 'active')->whereNotNull('approved_by')->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists()) {
                        abort(403, 'Clinical triage authority is required.');
                    }
                    $validated = $r->validate(['triage_evidence' => 'required|string|max:5000', 'priority' => ['required', Rule::in(['routine', 'urgent'])]]);
                    $data = [...$data, ...$validated];
                }
                if ($status === 'assessment_booked' && ! Appointment::where('referral_id', $record->id)->whereNotIn('status', ['cancelled', 'aborted'])->exists()) {
                    $this->fail('status', 'Book an assessment appointment first.');
                }
                if ($status === 'cancelled') {
                    $data['disposition'] = $r->validate(['disposition' => 'required|string|max:2000'])['disposition'];
                }
            } elseif ($module === 'tasks') {
                $paths = ['new' => ['acknowledged', 'cancelled'], 'acknowledged' => ['in_progress', 'waiting', 'cancelled'], 'in_progress' => ['waiting', 'completed', 'cancelled'], 'waiting' => ['in_progress', 'cancelled'], 'completed' => ['reopened'], 'reopened' => ['acknowledged', 'cancelled']];
                if (! in_array($status, $paths[$record->status] ?? [])) {
                    $this->fail('status', 'This task transition is not allowed.');
                }
                if ($status === 'waiting') {
                    $v = $r->validate(['waiting_reason' => 'required|string|max:2000', 'next_check_at' => 'required|date|after:now']);
                    $data = [...$data, ...$v, 'next_check_at' => $this->date($v['next_check_at'])];
                }
                if (in_array($status, ['completed', 'cancelled', 'reopened'])) {
                    $data['completion_evidence'] = $r->validate(['completion_evidence' => 'required|string|max:5000'])['completion_evidence'];
                }
            } elseif ($module === 'appointments') {
                $paths = ['booked' => ['confirmed', 'cancelled'], 'confirmed' => ['cancelled'], 'arrived' => ['completed', 'aborted']];
                if (! in_array($status, $paths[$record->status] ?? [])) {
                    $this->fail('status', 'This appointment transition is not allowed.');
                }
                if ($status === 'confirmed') {
                    $data['confirmation_evidence'] = $r->validate(['confirmation_evidence' => 'required|string|max:2000'])['confirmation_evidence'];
                }
                if ($status === 'completed') {
                    if ($record->attendance()->count() !== 2) {
                        $this->fail('status', 'Arrival and departure events are required.');
                    }
                    $data['completion_evidence'] = $r->validate(['completion_evidence' => 'required|string|max:5000'])['completion_evidence'];
                }
                if (in_array($status, ['cancelled', 'aborted'])) {
                    $data['cancellation_reason'] = $r->validate(['cancellation_reason' => 'required|string|max:2000'])['cancellation_reason'];
                }
            } else {
                if (! ($record->status === 'draft' && in_array($status, ['issued', 'void'])) && ! ($record->status === 'issued' && $status === 'void')) {
                    $this->fail('status', 'This invoice transition is not allowed.');
                }
                if ($status === 'void') {
                    if ($record->payments()->exists()) {
                        $this->fail('status', 'An invoice with payments requires a separate credit/refund workflow.');
                    }
                    $data['void_reason'] = $r->validate(['void_reason' => 'required|string|max:2000'])['void_reason'];
                }
            }
            if ($module === 'tasks' && $status === 'reopened') {
                $data['escalated_at'] = null;
            }
            $record->update([...$data, 'version' => $record->version + 1]);
            if ($module === 'tasks' && in_array($status, ['completed', 'cancelled'])) {
                DB::table('operation_notifications')->where('organisation_id', $org)->where('deduplication_key', 'like', 'overdue:'.$record->id.':%')->update(['read_at' => now()]);
            }
            Workspace::audit($r, $module.'.'.$status, $org, $module, $record->id);
        }, 3);

        return back()->with('success', 'Status updated.');
    }

    public function payment(Request $r, int $id)
    {
        $org = Workspace::organisation($r, 'finance.manage');
        $data = $r->validate(['request_id' => 'required|uuid', 'amount_minor' => 'required|integer|min:1|max:1000000000000', 'reference' => 'required|string|max:255', 'evidence' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $id, $org, $data) {
            $invoice = Invoice::where('organisation_id', $org)->lockForUpdate()->findOrFail($id);
            if ($invoice->payments()->where(fn ($q) => $q->where('request_id', $data['request_id'])->orWhere('reference', $data['reference']))->exists()) {
                return;
            }
            if (! in_array($invoice->status, ['issued', 'part_paid'])) {
                $this->fail('amount_minor', 'Issue the invoice before reconciliation.');
            }
            if ($data['amount_minor'] > $invoice->total_minor - $invoice->payments()->sum('amount_minor')) {
                $this->fail('amount_minor', 'Payment exceeds the outstanding amount.');
            }
            $invoice->payments()->create([...$data, 'recorded_by' => $r->user()->id, 'received_at' => now()]);
            $invoice->update(['status' => $invoice->payments()->sum('amount_minor') === $invoice->total_minor ? 'paid' : 'part_paid', 'version' => $invoice->version + 1]);
            Workspace::audit($r, 'payment.reconciled', $org, 'invoices', $id);
        }, 3);

        return back()->with('success', 'Payment reconciled to the ledger.');
    }

    public function reviewApplication(Request $r, int $id)
    {
        $org = Workspace::organisation($r, 'team.manage');
        $data = $r->validate(['status' => ['required', Rule::in(['approved', 'rejected'])], 'review_evidence' => 'required|string|max:5000']);
        DB::transaction(function () use ($r, $id, $org, $data) {
            $application = AccountApplication::where('organisation_id', $org)->lockForUpdate()->findOrFail($id);
            if ($application->status !== 'pending') {
                $this->fail('status', 'This application has already been reviewed.');
            }
            $user = User::findOrFail($application->user_id);
            if ($data['status'] === 'approved') {
                if (! $user->hasVerifiedEmail() || (config('mauricare.workforce_mfa_required') && ! $user->mfa_confirmed_at) || ! $application->credential_reference || ! $application->credential_expires_at || $application->credential_expires_at < now()->toDateString()) {
                    $this->fail('status', 'Verified email, a current credential and any enabled workforce security requirements must be satisfied.');
                }
                if (! in_array($application->discipline, ['doctor', 'nurse', 'carer', 'physiotherapist'])) {
                    $this->fail('status', 'Only care professional activation is supported in this release.');
                }
                $user->memberships()->updateOrCreate(['organisation_id' => $org, 'role' => $application->discipline], ['status' => 'active', 'approved_by' => $r->user()->id, 'starts_at' => now(), 'ends_at' => CarbonImmutable::parse($application->credential_expires_at, 'Indian/Mauritius')->endOfDay()->utc()]);
            }
            $application->forceFill([...$data, 'reviewed_by' => $r->user()->id])->save();
            Workspace::audit($r, 'application.'.$data['status'], $org, 'applications', $id);
        }, 3);

        return back()->with('success', 'Application reviewed.');
    }

    public function membership(Request $r, int $id)
    {
        $org = Workspace::organisation($r, 'team.manage');
        $r->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        $membership = DB::table('memberships')->where('organisation_id', $org)->where('id', $id)->first();
        abort_unless($membership, 404);
        abort_if($membership->user_id === $r->user()->id, 422, 'You cannot change your own access.');
        if ($r->status === 'active') {
            $this->fail('status', 'Suspended members require re-verification through a new review, not direct reactivation.');
        }
        DB::table('memberships')->where('id', $id)->update(['status' => 'suspended', 'updated_at' => now()]);
        Workspace::audit($r, 'membership.suspended', $org, 'memberships', $id);

        return back()->with('success', 'Membership suspended.');
    }

    public function addMembership(Request $r)
    {
        $org = Workspace::organisation($r, 'team.manage');
        $data = $r->validate(['email' => 'required|email', 'role' => ['required', Rule::in(['coordinator', 'finance', 'operational_admin'])], 'approval_evidence' => 'required|string|max:2000']);
        $user = User::where('email', strtolower(trim($data['email'])))->where('account_status', 'active')->whereNotNull('email_verified_at')->first();
        if (! $user) {
            $this->fail('email', 'An existing active account with a verified email is required. Ask the staff member to register and verify first.');
        }
        if (config('mauricare.workforce_mfa_required') && ! $user->mfa_confirmed_at) {
            $this->fail('email', 'The account must enrol workforce MFA before activation.');
        }
        DB::transaction(function () use ($user, $org, $data, $r) {
            $user->memberships()->updateOrCreate(['organisation_id' => $org, 'role' => $data['role']], ['status' => 'active', 'approved_by' => $r->user()->id, 'starts_at' => now(), 'ends_at' => null, 'approval_evidence' => $data['approval_evidence']]);
            Workspace::audit($r, 'membership.approved', $org, 'users', $user->id);
        });

        return back()->with('success', 'Operational membership approved. Clinical permissions are separate.');
    }
}
