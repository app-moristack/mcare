<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\CareTask;
use App\Models\ClinicalNote;
use App\Models\ClinicalPublication;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ClinicalRecordDemoSeeder extends Seeder
{
    private function uuid(string $key): string
    {
        $h = md5('mauricare-clinical-demo-v1-'.$key);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-4'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    public static function noteContent(): array
    {
        return ['encounter_at' => now('Indian/Mauritius')->subDay()->format('Y-m-d\TH:i'), 'late_entry_reason' => 'Synthetic historical example only.', 'subjective' => 'Synthetic patient report — not a real finding.', 'unable_reason' => '', 'objective' => 'SYNTHETIC PRIVATE PROFESSIONAL FINDING', 'impression' => 'Synthetic professional impression only.', 'actions_response' => 'Synthetic care documentation — no real care delivered.', 'follow_up_needed' => 'no', 'follow_up' => 'None needed for this synthetic demonstration.', 'follow_up_owner' => '', 'follow_up_due' => ''];
    }

    public static function planContent(int $nurseId): array
    {
        return ['service' => 'Synthetic home-care programme', 'visits_per_week' => '2', 'session_minutes' => '45', 'duration_weeks' => '6', 'patient_agreement' => 'agreed', 'agreement_evidence' => 'Synthetic demo agreement, not real patient consent.', 'capacity_evidence' => '', 'medication_order_refs' => '', 'risks_contingencies' => 'SYNTHETIC PRIVATE CONTINGENCY. No real clinical instructions.', 'equipment_training' => 'Synthetic demonstration only.', 'review_due' => now('Indian/Mauritius')->addWeek()->format('Y-m-d\TH:i'), 'goals' => [['description' => 'Synthetic measurable goal', 'problem_source' => 'Demo nursing assessment', 'verification' => 'Synthetic clinical review', 'baseline' => 'Demo baseline 1', 'target' => 'Demo target 2', 'unit' => 'Synthetic measure', 'timeframe' => 'Six demonstration weeks']], 'interventions' => [['action' => 'Synthetic care action — not real advice.', 'responsible_id' => (string) $nurseId, 'discipline' => 'nurse', 'frequency' => 'Two demonstration visits weekly', 'precautions' => 'Synthetic demonstration only.']]];
    }

    public function run(): void
    {
        if (! Organisation::where('name', 'MauriCare Demo')->exists()) {
            $this->call(DemoDataSeeder::class);

            return;
        }
        DB::transaction(function () {
            $org = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
            $nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
            $lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
            $coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
            $admin = User::where('email', 'admin@mauricare.mu')->firstOrFail();
            $patients = Patient::where('organisation_id', $org->id)->orderBy('id')->get();
            $p = $patients[0];
            foreach ($patients as $patient) {
                foreach (['nurse' => ['notes.read', 'notes.write', 'plans.read', 'plans.write'], 'doctor' => ['notes.read', 'notes.write', 'plans.read', 'plans.write'], 'physio' => ['notes.read', 'notes.write', 'plans.read', 'plans.write'], 'lead' => ['notes.read', 'notes.publish', 'plans.read', 'plans.approve', 'plans.publish']] as $key => $scopes) {
                    $u = User::where('email', 'demo.'.$key.'@example.test')->firstOrFail();
                    foreach ($scopes as $scope) {
                        PatientAccessRule::firstOrCreate(['request_id' => $this->uuid('grant-'.$patient->id.'-'.$key.'-'.$scope)], ['patient_id' => $patient->id, 'user_id' => $u->id, 'kind' => 'grant', 'scope' => $scope, 'purpose' => 'Synthetic clinical documentation demo', 'authority_type' => 'patient_consent', 'evidence' => 'Synthetic permission example only; not real consent.', 'wording_version' => 'DEMO-CLINICAL-v1', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'recorded_by' => $admin->id]);
                    }
                }
            }
            $past = Appointment::where('patient_id', $p->id)->where('status', 'completed')->firstOrFail();
            $future = Appointment::where('patient_id', $patients[1]->id)->firstOrFail();
            $note = ClinicalNote::firstOrCreate(['request_id' => $this->uuid('signed-note')], ['patient_id' => $p->id, 'appointment_id' => $past->id, 'author_id' => $nurse->id, 'discipline' => 'nurse', 'note_type' => 'nursing', 'visibility' => 'discipline_only', 'status' => 'signed', 'content' => self::noteContent(), 'encounter_at' => now()->subDay(), 'signed_by' => $nurse->id, 'signed_at' => now()->subHours(2)]);
            if ($note->wasRecentlyCreated) {
                $note->update(['content_hash' => $note->digest($note->content)]);
            }
            $addendum = ClinicalNote::firstOrCreate(['request_id' => $this->uuid('signed-addendum')], ['patient_id' => $p->id, 'appointment_id' => $past->id, 'author_id' => $nurse->id, 'discipline' => 'nurse', 'note_type' => 'nursing', 'visibility' => 'discipline_only', 'status' => 'signed', 'original_id' => $note->id, 'addendum_reason' => 'Synthetic correction demonstrating retained original content.', 'content' => [...self::noteContent(), 'objective' => 'Synthetic addendum clarification only.'], 'encounter_at' => now()->subDay(), 'signed_by' => $nurse->id, 'signed_at' => now()->subHour()]);
            if ($addendum->wasRecentlyCreated) {
                $addendum->update(['content_hash' => $addendum->digest($addendum->content)]);
            }
            ClinicalNote::firstOrCreate(['request_id' => $this->uuid('draft-note')], ['patient_id' => $patients[1]->id, 'appointment_id' => $future->id, 'author_id' => $nurse->id, 'discipline' => 'nurse', 'note_type' => 'nursing', 'visibility' => 'clinical_team', 'content' => ['subjective' => 'Synthetic unsent draft.']]);
            $physio = User::where('email', 'demo.physio@example.test')->firstOrFail();
            $ref = Referral::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('physio-referral')], ['patient_id' => $p->id, 'source' => 'coordinator', 'reason' => 'Synthetic rehabilitation demonstration.', 'discipline' => 'physiotherapist', 'priority' => 'routine', 'status' => 'awaiting_match', 'triage_evidence' => 'Synthetic clinical review only.', 'owner_id' => $coordinator->id, 'backup_id' => $lead->id, 'due_at' => now()->addWeek()]);
            Appointment::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('physio-visit')], ['patient_id' => $p->id, 'referral_id' => $ref->id, 'provider_id' => $physio->id, 'service' => 'Demo physiotherapy documentation', 'starts_at' => now()->addWeek()->startOfHour(), 'ends_at' => now()->addWeek()->startOfHour()->addMinutes(45), 'location_type' => 'clinic', 'location' => 'Synthetic demonstration clinic', 'status' => 'confirmed', 'confirmation_evidence' => 'Synthetic patient/provider confirmation only.']);
            $episode = CareEpisode::firstOrCreate(['request_id' => $this->uuid('episode')], ['patient_id' => $p->id, 'referral_id' => $past->referral_id, 'title' => 'Demo coordinated home care', 'created_by' => $nurse->id]);
            $newEpisode = $episode->wasRecentlyCreated;
            $previous = null;
            $active = null;
            foreach (['superseded', 'active', 'review', 'draft'] as $i => $status) {
                $plan = CarePlan::firstOrCreate(['request_id' => $this->uuid('plan-'.$i)], ['patient_id' => $p->id, 'episode_id' => $episode->id, 'author_id' => $nurse->id, 'previous_id' => $i === 0 ? null : ($i === 1 ? $previous?->id : $active?->id), 'revision_number' => $i + 1, 'revision_reason' => $i === 0 ? null : 'Synthetic plan replacement example.', 'content' => self::planContent($nurse->id), 'status' => $status, 'signed_by' => $status === 'draft' ? null : $nurse->id, 'signed_at' => $status === 'draft' ? null : now()->subDay(), 'approved_by' => in_array($status, ['active', 'superseded']) ? $lead->id : null, 'approved_at' => in_array($status, ['active', 'superseded']) ? now()->subHours(2) : null, 'review_due_at' => $status === 'draft' ? null : now()->addWeek()]);
                if ($plan->wasRecentlyCreated && $status !== 'draft') {
                    $plan->update(['content_hash' => $plan->digest($plan->content)]);
                }
                if (in_array($status, ['active', 'superseded'])) {
                    $task = CareTask::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('plan-review-'.$i)], ['patient_id' => $p->id, 'title' => 'Demo routine care-plan review #'.$plan->id, 'description' => 'Synthetic routine review. Open clinical records with approved authority.', 'status' => $status === 'superseded' ? 'cancelled' : 'new', 'priority' => 'routine', 'owner_id' => $lead->id, 'backup_id' => $coordinator->id, 'created_by' => $lead->id, 'due_at' => $plan->review_due_at, 'completion_evidence' => $status === 'superseded' ? 'Synthetic replacement plan approved.' : null]);
                    if (! $plan->review_task_id) {
                        $plan->update(['review_task_id' => $task->id]);
                    }if (! DB::table('care_plan_events')->where('care_plan_id', $plan->id)->where('action', $status)->exists()) {
                        DB::table('care_plan_events')->insert(['care_plan_id' => $plan->id, 'actor_id' => $lead->id, 'action' => $status, 'reason' => Crypt::encryptString('Synthetic plan lifecycle example.'), 'created_at' => now()]);
                    }
                }
                if ($i === 0) {
                    $previous = $plan;
                }if ($i === 1) {
                    $active = $plan;
                    if ($newEpisode) {
                        $episode->update(['current_plan_id' => $plan->id]);
                    }
                }
            }
            foreach ([$note, $addendum] as $i => $n) {
                ClinicalPublication::firstOrCreate(['request_id' => $this->uuid('note-publication-'.$i)], ['patient_id' => $p->id, 'clinical_note_id' => $n->id, 'content' => ['summary' => $i === 0 ? 'Demo shared visit summary only.' : 'Demo shared addendum clarification only.', 'shared_goals' => null], 'source_hash' => $n->content_hash, 'published_by' => $lead->id, 'published_at' => now()->subMinutes(20 - $i)]);
            }
            ClinicalPublication::firstOrCreate(['request_id' => $this->uuid('plan-publication')], ['patient_id' => $p->id, 'care_plan_id' => $active->id, 'content' => ['summary' => 'Demo shared care plan only. This is not real clinical advice.', 'shared_goals' => 'Synthetic shared patient goal for demonstration.'], 'source_hash' => $active->content_hash, 'published_by' => $lead->id, 'published_at' => now()]);
            if (! DB::table('audit_events')->where('organisation_id', $org->id)->where('event', 'clinical.demo.seeded')->exists()) {
                DB::table('audit_events')->insert(['organisation_id' => $org->id, 'actor_id' => $admin->id, 'event' => 'clinical.demo.seeded', 'created_at' => now()]);
            }
        });
    }
}
