<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use App\Services\Workspace;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        Inertia::encryptHistory((bool) $request->user());
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'workspace' => function () use ($request) {
                if (! $request->user()) {
                    return null;
                }
                $ids = Workspace::memberships($request->user())->pluck('organisation_id')->unique()->values();
                $id = Workspace::selectedOrganisation($request);

                return ['organisation_id' => $ids->contains($id) ? $id : null,
                    'organisations' => Organisation::whereIn('id', $ids)->get(['id', 'name']),
                    'permissions' => $ids->contains($id) ? collect(config('permissions.abilities'))->filter(fn ($a) => $request->user()->hasPermission($a, $id))->values() : [],
                    'mfa_enrolled' => (bool) $request->user()->mfa_confirmed_at,
                    'notifications' => DB::table('operation_notifications')->where('user_id', $request->user()->id)->whereIn('organisation_id', $ids)->whereNull('read_at')->latest()->limit(5)->get(['id', 'message', 'url']),
                ];
            },
            'auth' => [
                'user' => $request->user(),
            ],
        ]);
    }
}
