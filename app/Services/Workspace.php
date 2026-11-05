<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Workspace
{
    public static function memberships(User $user)
    {
        return $user->memberships()->where('status', 'active')->whereNotNull('approved_by')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get();
    }

    public static function selectedOrganisation(Request $request): int
    {
        if ($request->session()->has('organisation_id')) {
            return (int) $request->session()->get('organisation_id');
        }
        $memberships = self::memberships($request->user());
        $ids = $memberships->pluck('organisation_id')->unique()->values();
        if ($ids->count() === 1) {
            return (int) $ids->first();
        }
        // Adding the optional demo workspace must not remove the seeded owner's primary context.
        $owned = $memberships->where('role', 'owner')->pluck('organisation_id')->unique()->values();

        return $request->user()->account_kind === 'admin' && $owned->count() === 1 ? (int) $owned->first() : 0;
    }

    public static function organisation(Request $request, string $ability): int
    {
        $ids = self::memberships($request->user())->pluck('organisation_id')->unique()->values();
        $id = self::selectedOrganisation($request);
        if (! $ids->contains($id) || ! $request->user()->hasPermission($ability, $id)) {
            self::audit($request, 'access.denied', $ids->contains($id) ? $id : null);
            abort(403, 'Select an authorised organisation and working context.');
        }

        return $id;
    }

    public static function audit(Request $request, string $event, ?int $organisationId = null, ?string $type = null, ?int $id = null): void
    {
        DB::table('audit_events')->insert(['actor_id' => $request->user()?->id, 'organisation_id' => $organisationId, 'event' => $event, 'resource_type' => $type, 'resource_id' => $id, 'created_at' => now()]);
    }

    public static function staff(int $organisationId)
    {
        return User::query()->where('account_status', 'active')->whereNotNull('email_verified_at')->whereHas('memberships', fn ($q) => $q->where('organisation_id', $organisationId)->where('status', 'active')->whereNotNull('approved_by')->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now())));
    }
}
