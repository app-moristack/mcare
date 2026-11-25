<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Organisation;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ChecklistController extends Controller
{
    private const VERSION_RULES = [
        'steps' => 'required|array|list|min:1|max:40',
        'steps.*' => 'required|string|max:1000',
        'request_id' => 'nullable|uuid',
    ];

    public function index(Request $request)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $templates = ChecklistTemplate::where('organisation_id', $organisationId)->orderBy('title')->orderBy('id')
            ->with(['versions' => fn ($versions) => $versions->orderByDesc('version')])->get()
            ->map(fn (ChecklistTemplate $template) => [
                'id' => $template->id,
                'title' => $template->title,
                'versions' => $template->versions->map->only(['id', 'version', 'status', 'steps', 'published_at']),
            ]);
        Workspace::audit($request, 'checklists.viewed', $organisationId, 'checklist_templates');

        return Inertia::render('checklists/index', ['templates' => $templates]);
    }

    public function store(Request $request)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate(['title' => 'required|string|max:160', ...self::VERSION_RULES]);
        DB::transaction(function () use ($request, $organisationId, $data) {
            Organisation::lockForUpdate()->findOrFail($organisationId);
            $requestId = $data['request_id'] ?? null;
            if ($requestId && ($existing = ChecklistTemplate::where('organisation_id', $organisationId)->where('request_id', $requestId)->first())) {
                $first = $existing->versions()->where('version', 1)->sole();
                abort_unless($existing->created_by === $request->user()->id && $existing->title === $data['title'] && $first->steps === $data['steps'], 409, 'This request already created a different checklist.');

                return;
            }

            $template = ChecklistTemplate::create([
                'organisation_id' => $organisationId,
                'title' => $data['title'],
                'created_by' => $request->user()->id,
                'request_id' => $requestId,
            ]);
            $template->versions()->create([
                'version' => 1,
                'status' => 'draft',
                'steps' => $data['steps'],
                'created_by' => $request->user()->id,
            ]);
            Workspace::audit($request, 'checklist.template.created', $organisationId, 'checklist_templates', $template->id);
        }, 3);

        return back()->with('success', 'Checklist draft created.');
    }

    public function storeVersion(Request $request, int $template)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate(self::VERSION_RULES);
        DB::transaction(function () use ($request, $organisationId, $template, $data) {
            $checklist = ChecklistTemplate::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($template);
            $requestId = $data['request_id'] ?? null;
            if ($requestId && ($existing = $checklist->versions()->where('request_id', $requestId)->first())) {
                abort_unless($existing->created_by === $request->user()->id && $existing->steps === $data['steps'], 409, 'This request already created a different version.');

                return;
            }

            $version = $checklist->versions()->create([
                'version' => $checklist->versions()->max('version') + 1,
                'status' => 'draft',
                'steps' => $data['steps'],
                'created_by' => $request->user()->id,
                'request_id' => $requestId,
            ]);
            Workspace::audit($request, 'checklist.version.drafted', $organisationId, 'checklist_versions', $version->id);
        }, 3);

        return back()->with('success', 'Checklist version drafted.');
    }

    public function publish(Request $request, int $template, int $version)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $request->validate(['declaration' => 'accepted', 'password' => 'required|current_password']);
        DB::transaction(function () use ($request, $organisationId, $template, $version) {
            $checklist = ChecklistTemplate::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($template);
            $draft = $checklist->versions()->lockForUpdate()->findOrFail($version);
            if ($draft->status === 'published') {
                return;
            }

            abort_unless($draft->status === 'draft' && ! $checklist->versions()->where('status', 'published')->where('version', '>', $draft->version)->exists(),
                409, 'A newer checklist version is already published. Create a new draft to make changes.');
            $draft->update([
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $request->user()->id,
                'content_hash' => $draft->digest(),
            ]);
            Workspace::audit($request, 'checklist.version.published', $organisationId, 'checklist_versions', $draft->id);
        }, 3);

        return back()->with('success', 'Checklist publication recorded.');
    }
}
