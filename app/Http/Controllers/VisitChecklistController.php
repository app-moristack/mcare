<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ChecklistTemplate;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitChecklistController extends Controller
{
    public function attach(Request $request, int $id)
    {
        $organisationId = Workspace::organisation($request, 'appointments.manage');
        $data = $request->validate([
            'checklist_version_id' => 'required|integer|min:1',
            'visit_version' => 'required|integer|min:1',
            'request_id' => 'required|uuid',
        ]);

        DB::transaction(function () use ($request, $id, $organisationId, $data) {
            $visit = Appointment::where('organisation_id', $organisationId)->lockForUpdate()->findOrFail($id);
            $attached = $visit->checklist()->lockForUpdate()->first();
            if ($attached) {
                abort_unless($attached->attach_request_id === $data['request_id']
                    && $attached->attached_by === $request->user()->id
                    && $attached->checklist_version_id === (int) $data['checklist_version_id'],
                    409,
                    'This visit already has an attached checklist.'
                );

                return;
            }

            abort_unless($visit->version === (int) $data['visit_version'] && in_array($visit->status, ['booked', 'confirmed'], true),
                409,
                'This visit changed. Reload before attaching a checklist.'
            );
            $template = ChecklistTemplate::where('organisation_id', $organisationId)
                ->whereHas('versions', fn ($versions) => $versions->whereKey($data['checklist_version_id']))
                ->lockForUpdate()->firstOrFail();
            $source = $template->versions()->where('status', 'published')->latest('version')->lockForUpdate()->first();
            if (! $source || $source->id !== (int) $data['checklist_version_id']) {
                throw ValidationException::withMessages(['checklist_version_id' => 'Choose the currently published version of this checklist.']);
            }
            abort_unless(hash_equals($source->content_hash ?? '', $source->digest()), 409, 'Checklist publication integrity check failed.');

            $visit->checklist()->create([
                'checklist_version_id' => $source->id,
                'title' => $template->title,
                'version' => $source->version,
                'steps' => $source->steps,
                'source_hash' => $source->content_hash,
                'attached_by' => $request->user()->id,
                'attach_request_id' => $data['request_id'],
                'attached_at' => now(),
            ]);
            $visit->increment('version');
            Workspace::audit($request, 'visit.checklist.attached', $organisationId, 'appointments', $visit->id);
        }, 3);

        return back()->with('success', 'Published checklist attached to this visit.');
    }
}
