<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use Illuminate\Http\Request;

class PatientAccess
{
    public const SCOPES = ['summary.read', 'summary.write', 'basic.read', 'basic.write', 'nursing.read', 'nursing.write', 'nursing.cosign', 'notes.read', 'notes.write', 'notes.publish', 'plans.read', 'plans.write', 'plans.approve', 'plans.publish'];

    public static function current(Patient $p, int $user)
    {
        return PatientAccessRule::where('patient_id', $p->id)->where('user_id', $user)->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public static function allowed(User $u, Patient $p, string $action): bool
    {
        if (! in_array($action, self::SCOPES, true) || $p->user_id === $u->id || $u->account_status !== 'active' || ! $u->hasVerifiedEmail() || $p->status !== 'active') {
            return false;
        }
        $rules = self::current($p, $u->id)->get();
        if ($rules->contains('kind', 'block')) {
            return false;
        }
        $roles = Workspace::memberships($u)->where('organisation_id', $p->organisation_id)->pluck('role');
        $allowedRoles = match (true) {
            $action === 'notes.publish' || $action === 'plans.approve' || $action === 'plans.publish' => ['clinical_admin'], $action === 'nursing.write' => ['nurse'], $action === 'nursing.cosign' => ['clinical_admin'], str_starts_with($action, 'nursing.') || str_starts_with($action, 'basic.') => ['doctor', 'nurse', 'clinical_admin'], default => ['doctor', 'nurse', 'physiotherapist', 'clinical_admin']
        };
        $assignments = $rules->where('kind', 'assignment')->whereIn('role', $allowedRoles)->filter(fn ($a) => $roles->contains($a->role));
        if ($assignments->isEmpty()) {
            return false;
        }
        // Current credentials are required even for seeded administrators.
        $qualified = $assignments->contains(function ($a) use ($u, $p) {
            return $u->application()->where('organisation_id', $p->organisation_id)->where('status', 'approved')->whereIn('discipline', $a->role === 'clinical_admin' ? ['doctor', 'nurse'] : [$a->role])->whereDate('credential_expires_at', '>=', now(config('mauricare.time_zone'))->toDateString())->exists();
        });
        if (! $qualified) {
            return false;
        }

        return $rules->where('kind', 'grant')->contains(fn ($g) => $g->scope === $action);
    }

    public static function qualifiedRoles(User $u, Patient $p): array
    {
        $roles = Workspace::memberships($u)->where('organisation_id', $p->organisation_id)->pluck('role');
        $discipline = $u->application()->where('organisation_id', $p->organisation_id)->where('status', 'approved')->whereDate('credential_expires_at', '>=', now(config('mauricare.time_zone'))->toDateString())->value('discipline');

        return self::current($p, $u->id)->where('kind', 'assignment')->get()->filter(fn ($a) => $roles->contains($a->role) && in_array($discipline, $a->role === 'clinical_admin' ? ['doctor', 'nurse'] : [$a->role], true))->pluck('role')->unique()->values()->all();
    }

    public static function enforce(Request $r, Patient $p, string $action): void
    {
        $allowed = self::allowed($r->user(), $p, $action);
        Workspace::audit($r, $allowed ? 'patient.'.$action.'.accessed' : 'patient.'.$action.'.denied', $p->organisation_id, 'patients', $p->id);
        abort_unless($allowed, 403, 'A current clinical credential, assignment and scoped authority are required. Patient blocks take precedence.');
    }
}
