<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\CareTask;
use App\Models\EpisodeDischarge;
use App\Models\InvestigationResult;
use App\Models\Patient;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EpisodeDischargeController extends Controller
{
    public function store(Request $request, int $id, int $episode)
    {
        $patient = Patient::where('organisation_id', Workspace::selectedOrganisation($request))->findOrFail($id);
        PatientAccess::enforce($request, $patient, 'plans.read');
        PatientAccess::enforce($request, $patient, 'plans.approve');

        $data = $request->validate([
            'request_id' => 'required|uuid',
            'plan_id' => 'required|integer',
            'plan_version' => 'required|integer',
            'outcomes' => 'required|string|max:5000',
            'patient_discussion' => 'required|string|max:5000',
            'medications' => 'required|string|max:5000',
            'results_review' => 'required|string|max:5000',
            'summary' => 'required|string|max:5000',
            'next_steps' => 'required|string|max:5000',
            'password' => 'required|current_password',
            'declaration' => 'accepted',
        ]);

        DB::transaction(function () use ($request, $patient, $episode, $data) {
            $patient = Patient::lockForUpdate()->findOrFail($patient->id);
            PatientAccess::enforce($request, $patient, 'plans.read');
            PatientAccess::enforce($request, $patient, 'plans.approve');
            $record = CareEpisode::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($episode);
            $existing = $record->discharge()->first();
            if ($existing) {
                abort_unless($existing->request_id === $data['request_id'] && $existing->signed_by === $request->user()->id, 409, 'This episode already has a signed discharge.');

                return;
            }

            $plan = CarePlan::where('episode_id', $record->id)->where('patient_id', $patient->id)
                ->where('status', 'completed')->latest('revision_number')->lockForUpdate()->first();
            abort_unless($record->current_plan_id === null && $plan &&
                $plan->id === (int) $data['plan_id'] && $plan->version === (int) $data['plan_version'] &&
                hash_equals($plan->content_hash ?? '', $plan->digest($plan->content)), 409, 'Complete the approved care plan and reload before discharging.');

            $openVisits = Appointment::where('patient_id', $patient->id)
                ->whereNotIn('status', ['completed', 'cancelled', 'aborted', 'declined', 'missed'])->exists();
            $openTasks = CareTask::where('patient_id', $patient->id)->whereNotIn('status', ['completed', 'cancelled'])->exists();
            $openResults = InvestigationResult::where('patient_id', $patient->id)->where('status', '!=', 'completed')
                ->whereNotIn('id', InvestigationResult::where('patient_id', $patient->id)->whereNotNull('supersedes_id')->select('supersedes_id'))->exists();

            if ($openVisits || $openTasks || $openResults) {
                throw ValidationException::withMessages([
                    'episode' => 'Resolve outstanding patient visits, tasks and investigation results before signing discharge.',
                ]);
            }

            $content = collect($data)->only(['outcomes', 'patient_discussion', 'medications', 'results_review', 'summary', 'next_steps'])->all();
            $signer = $request->user()->id;
            $discharge = $record->discharge()->create([
                'care_plan_id' => $plan->id,
                'signed_by' => $signer,
                'request_id' => $data['request_id'],
                'content' => $content,
                'content_hash' => EpisodeDischarge::digest($record->id, $plan->id, $signer, $content),
                'signed_at' => now(),
            ]);
            Workspace::audit($request, 'clinical.episode.discharged', $patient->organisation_id, 'episode_discharges', $discharge->id);
        });

        return back()->with('success', 'Episode discharged and the patient summary published.');
    }
}
