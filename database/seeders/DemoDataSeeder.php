<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceReview;
use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\CareTask;
use App\Models\ClinicalObservation;
use App\Models\DocumentExport;
use App\Models\EpisodeHandover;
use App\Models\Investigation;
use App\Models\InvestigationPublication;
use App\Models\InvestigationResult;
use App\Models\Invoice;
use App\Models\MedicationAdministration;
use App\Models\MedicationInstruction;
use App\Models\MedicationReconciliation;
use App\Models\MissedVisitCase;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\PatientAssessment;
use App\Models\PatientDocument;
use App\Models\PatientSummary;
use App\Models\ProviderRosterEntry;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'MauriCareDemo!2026';

    private function uuid(string $key): string
    {
        $hex = md5('mauricare-demo-v1-'.$key);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function user(string $key, string $name, string $kind = 'provider_application'): User
    {
        $u = User::firstOrNew(['email' => $key.'@example.test']);
        if (! $u->exists) {
            $u->password = Hash::make(self::PASSWORD);
        }
        $u->forceFill(['name' => 'Demo '.$name, 'account_kind' => $kind, 'account_status' => 'active', 'email_verified_at' => $u->email_verified_at ?? now(), 'identity_status' => 'verified', 'preferred_language' => 'en', 'time_zone' => 'Indian/Mauritius'])->save();

        return $u;
    }

    public static function assessmentContent(string $kind): array
    {
        $schema = config('assessments.forms.'.$kind);
        $c = [];
        foreach ($schema['sections'] as $s) {
            foreach ($s['fields'] as $f) {
                $c[$f['key']] = $f['choices'] ? explode(',', $f['choices'])[0] : ($f['type'] === 'date' ? now('Indian/Mauritius')->addWeek()->toDateString() : ($f['type'] === 'datetime-local' ? now('Indian/Mauritius')->subMinutes(10)->format('Y-m-d\TH:i') : ($f['type'] === 'number' ? '' : 'Synthetic demonstration: '.$f['label'])));
            }
        }
        foreach ($schema['children'] as $key => $fields) {
            $c[$key] = [];
            if ($kind === 'nursing') {
                $entry = [];
                foreach ($fields as $f) {
                    $entry[$f['key']] = $f['choices'] ? explode(',', $f['choices'])[0] : ($f['type'] === 'date' ? now('Indian/Mauritius')->addWeek()->toDateString() : 'Synthetic demonstration: '.$f['label']);
                }$c[$key][] = $entry;
            }
        }
        if ($kind === 'basic') {
            $c = array_merge($c, ['respondent_relationship' => 'self', 'respondent_name' => 'Demo Aline Patient', 'symptoms_reported' => 'no', 'pain_reported' => 'no', 'pain_scoring' => 'not_applicable', 'conditions_status' => 'unknown', 'medicines_status' => 'unknown', 'allergies_status' => 'unknown', 'recent_falls' => 'unknown']);
        } else {
            $c = array_merge($c, ['identifier_one_type' => 'legal_name', 'identifier_two_type' => 'date_of_birth', 'observations_status' => 'not_obtained', 'observations_reason' => 'Synthetic demonstration only; no real measurements collected.', 'unresolved_concerns' => 'no', 'pressure_risk_indicated' => 'unknown', 'falls_risk_indicated' => 'unknown']);
        }

        return $c;
    }

    public function run(): void
    {
        DB::transaction(function () {
            $org = Organisation::firstOrCreate(['name' => 'MauriCare Demo'], ['type' => 'care_service', 'currency' => 'MUR', 'time_zone' => 'Indian/Mauritius']);
            $admin = User::where('email', 'admin@mauricare.mu')->first();
            if (! $admin) {
                $this->call(AdminUserSeeder::class);
                $admin = User::where('email', 'admin@mauricare.mu')->firstOrFail();
            }
            $admin->memberships()->firstOrCreate(['organisation_id' => $org->id, 'role' => 'super_admin'], ['status' => 'active', 'approved_by' => $admin->id, 'starts_at' => now()->subDay(), 'approval_evidence' => 'Synthetic demo workspace administration only.']);
            $users = [];
            foreach (['nurse' => ['Nadia Nurse', 'nurse'], 'doctor' => ['Dev Doctor', 'doctor'], 'lead' => ['Lea Clinical Lead', 'doctor'], 'physio' => ['Priya Physiotherapist', 'physiotherapist'], 'carer' => ['Chris Carer', 'carer'], 'coordinator' => ['Camille Coordinator', 'coordinator'], 'finance' => ['Farah Finance', 'finance']] as $key => [$name,$role]) {
                $u = $this->user('demo.'.$key, $name);
                $users[$key] = $u;
                $roles = $key === 'lead' ? ['doctor', 'clinical_admin'] : [$role];
                foreach ($roles as $v) {
                    $u->memberships()->firstOrCreate(['organisation_id' => $org->id, 'role' => $v], ['status' => 'active', 'approved_by' => $admin->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'approval_evidence' => 'Synthetic reviewed demo membership; not a real licence.']);
                }
                if (in_array($role, ['doctor', 'nurse', 'physiotherapist', 'carer'])) {
                    $u->application()->firstOrCreate([], ['kind' => 'provider', 'status' => 'approved', 'organisation_id' => $org->id, 'discipline' => $role, 'credential_reference' => 'DEMO-NOT-A-REAL-LICENCE-'.strtoupper($key), 'credential_expires_at' => now()->addYear()->toDateString()])->forceFill(['reviewed_by' => $admin->id, 'review_evidence' => 'Synthetic demo credential verification only.'])->save();
                }
            }
            $pending = $this->user('demo.applicant', 'Pending Applicant');
            $pending->application()->firstOrCreate([], ['kind' => 'provider', 'status' => 'pending', 'organisation_id' => $org->id, 'discipline' => 'nurse', 'credential_reference' => 'DEMO-PENDING-REVIEW', 'credential_expires_at' => now()->addYear()->toDateString()]);
            $partner = $this->user('demo.partner', 'Partner Applicant', 'partner_application');
            $partner->application()->firstOrCreate([], ['kind' => 'partner', 'status' => 'pending', 'organisation_id' => $org->id]);
            $patientUser = $this->user('demo.patient', 'Aline Patient', 'self_patient');
            $patientUser->patientProfile()->firstOrCreate([], ['public_id' => $this->uuid('profile'), 'legal_name' => 'Demo Aline Patient', 'date_of_birth' => '1970-01-02', 'locality' => 'Curepipe', 'onboarding_completed_at' => now()]);
            $patients = [];
            foreach (['Aline Patient', 'Benoit Patient', 'Chloe Patient', 'Dylan Patient', 'Emma Patient', 'Farid Patient'] as $i => $name) {
                $p = Patient::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('patient-'.$i)], ['reference' => $this->uuid('patient-ref-'.$i), 'user_id' => $i === 0 ? $patientUser->id : null, 'name' => 'Demo '.$name, 'date_of_birth' => $i === 5 ? null : '1970-01-02', 'locality' => ['Curepipe', 'Port Louis', 'Moka'][$i % 3], 'address' => 'Synthetic demonstration address; not a real visit location.', 'authority_type' => 'patient_request', 'authority_evidence' => 'Synthetic demo consent; not real patient authority.', 'demographics' => ['given_names' => 'Demo '.explode(' ', $name)[0], 'family_name' => 'Patient', 'dob_precision' => $i === 5 ? 'unknown' : 'exact', 'preferred_language' => 'en', 'country' => 'MU', 'safe_contact_method' => 'in_person', 'assisted_contact' => 'Synthetic in-person demonstration only.', 'emergency_contact_unavailable_reason' => 'Synthetic demonstration: no real emergency contact.', 'information_source' => 'patient', 'source_details' => 'Synthetic seeded data', 'record_status' => 'provisional']]);
                $patients[] = $p;
                foreach (['nurse' => 'nurse', 'doctor' => 'doctor', 'lead' => 'clinical_admin', 'physio' => 'physiotherapist'] as $key => $role) {
                    $u = $users[$key];
                    $rule = ['patient_id' => $p->id, 'user_id' => $u->id, 'scope' => 'all', 'kind' => 'assignment', 'role' => $role, 'purpose' => 'Synthetic demo care', 'authority_type' => 'care_assignment', 'evidence' => 'Synthetic named assignment; not real care authority.', 'wording_version' => 'DEMO-v1', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'recorded_by' => $admin->id];
                    PatientAccessRule::firstOrCreate(['request_id' => $this->uuid('assignment-'.$i.'-'.$key)], $rule);
                    $scopes = $key === 'physio' ? ['summary.read'] : ['summary.read', 'basic.read', 'nursing.read', 'observations.read', 'medications.read', 'medications.reconcile', 'investigations.read', 'results.read'];
                    if ($key === 'nurse') {
                        $scopes = array_merge($scopes, ['summary.write', 'basic.write', 'nursing.write', 'observations.write', 'medications.administer', 'results.receive', 'documents.clinical.read', 'documents.administrative.read', 'documents.upload', 'documents.export']);
                    } elseif ($key === 'doctor') {
                        $scopes = array_merge($scopes, ['medications.verify', 'investigations.request', 'results.acknowledge', 'documents.identity.read', 'documents.clinical.read', 'documents.upload', 'documents.export']);
                    }if ($key === 'lead') {
                        $scopes = array_merge($scopes, ['nursing.cosign', 'results.receive', 'results.publish', 'documents.identity.read', 'documents.clinical.read', 'documents.administrative.read', 'documents.upload', 'documents.review', 'documents.export']);
                    }
                    foreach ($scopes as $scope) {
                        PatientAccessRule::firstOrCreate(['request_id' => $this->uuid('grant-'.$i.'-'.$key.'-'.$scope)], [...$rule, 'kind' => 'grant', 'scope' => $scope, 'role' => null, 'authority_type' => 'patient_consent', 'evidence' => 'Synthetic demo authority for '.$scope.'; no real patient data.']);
                    }
                }
                if ($i < 3) {
                    $s = PatientSummary::firstOrCreate(['patient_id' => $p->id], ['content' => ['allergy_status' => $i === 0 ? 'unknown' : 'not_assessed', 'conditions' => [], 'allergies' => [], 'medications' => [], 'surgeries' => [], 'immunisations' => [], 'functional_baseline' => 'Synthetic demonstration only; not a clinical finding.'], 'updated_by' => $users['nurse']->id]);
                    if (! DB::table('patient_summary_revisions')->where('patient_id', $p->id)->where('version', 1)->exists()) {
                        DB::table('patient_summary_revisions')->insert(['patient_id' => $p->id, 'version' => 1, ...['content' => Crypt::encryptString(json_encode($s->content)), 'recorded_by' => $users['nurse']->id, 'reason' => Crypt::encryptString('Initial synthetic demo summary'), 'created_at' => $s->created_at]]);
                    }
                }
            }
            $visits = [];
            foreach (['nurse', 'doctor', 'lead', 'physio'] as $key) {
                ProviderRosterEntry::firstOrCreate(['request_id' => $this->uuid('roster-availability-'.$key)], [
                    'organisation_id' => $org->id, 'provider_id' => $users[$key]->id, 'kind' => 'availability',
                    'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear(), 'time_zone' => 'Indian/Mauritius', 'locality' => 'SYNTHETIC demo coverage',
                    'notes' => 'Synthetic broad availability for demo scheduling only.', 'status' => 'approved', 'recorded_by' => $users[$key]->id,
                    'reviewed_by' => $admin->id, 'review_evidence' => 'Synthetic independent roster review.', 'reviewed_at' => now()->subDay(),
                ]);
            }
            ProviderRosterEntry::firstOrCreate(['request_id' => $this->uuid('roster-travel-pending')], [
                'organisation_id' => $org->id, 'provider_id' => $users['doctor']->id, 'kind' => 'travel',
                'starts_at' => now()->addMonths(2), 'ends_at' => now()->addMonths(2)->addDays(3), 'time_zone' => 'Indian/Mauritius',
                'notes' => 'SYNTHETIC pending travel example; no real location.', 'status' => 'pending', 'recorded_by' => $users['doctor']->id,
            ]);
            foreach ($patients as $i => $p) {
                $ref = Referral::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('referral-'.$i)], ['patient_id' => $p->id, 'source' => 'patient', 'reason' => 'Synthetic demonstration referral; not a real care request.', 'discipline' => 'nurse', 'priority' => $i === 1 ? 'urgent' : 'routine', 'status' => $i === 5 ? 'received' : 'awaiting_match', 'triage_evidence' => $i === 5 ? null : 'Synthetic triage reviewed by Demo Clinical Lead', 'owner_id' => $users['coordinator']->id, 'backup_id' => $users['lead']->id, 'due_at' => now()->addDays($i + 1)]);
                $start = now('Indian/Mauritius')->addDay()->setTime(9 + $i, 0)->utc();
                $v = Appointment::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('visit-'.$i)], ['patient_id' => $p->id, 'referral_id' => $ref->id, 'provider_id' => $users['nurse']->id, 'service' => 'Demo nursing assessment', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(45), 'location_type' => 'home', 'location' => 'Synthetic demonstration address only', 'status' => $i === 4 ? 'booked' : 'confirmed', 'confirmation_evidence' => 'Synthetic patient/provider confirmation; no real appointment.']);
                if ($v->offer_status === 'pending') {
                    $v->update(['offer_status' => 'accepted', 'offered_at' => $v->created_at, 'offer_responded_at' => $v->created_at, 'offer_evidence' => 'Synthetic provider acceptance; no real visit.']);
                }
                $visits[] = $v;
                CareTask::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('task-'.$i)], ['patient_id' => $p->id, 'title' => 'Demo '.($i === 0 ? 'overdue coordination follow-up' : 'care coordination task '.($i + 1)), 'description' => 'Synthetic operational follow-up only. No real patient action required.', 'priority' => $i === 0 ? 'high' : 'routine', 'status' => $i === 3 ? 'waiting' : 'new', 'owner_id' => $users['coordinator']->id, 'backup_id' => $users['lead']->id, 'created_by' => $admin->id, 'due_at' => $i === 0 ? now()->subHour() : now()->addDays($i + 1), 'waiting_reason' => $i === 3 ? 'Synthetic awaiting confirmation' : null, 'next_check_at' => $i === 3 ? now()->addDay() : null]);
                $invoice = Invoice::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('invoice-'.$i)], ['patient_id' => $p->id, 'number' => 'DEMO-MC-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'status' => $i === 5 ? 'draft' : ($i === 0 ? 'paid' : ($i === 1 ? 'part_paid' : 'issued')), 'currency' => 'MUR', 'due_date' => now()->addDays(14)->toDateString(), 'total_minor' => 150000]);
                $invoice->items()->firstOrCreate(['description' => 'Synthetic demo nursing service'], ['quantity' => 1, 'unit_price_minor' => 150000, 'total_minor' => 150000]);
                if ($i < 2) {
                    $invoice->payments()->firstOrCreate(['request_id' => $this->uuid('payment-'.$i)], ['recorded_by' => $users['finance']->id, 'amount_minor' => $i === 0 ? 150000 : 50000, 'reference' => 'DEMO-NOT-A-REAL-PAYMENT-'.($i + 1), 'evidence' => 'Synthetic demo reconciliation; no funds transferred.', 'received_at' => now()]);
                }
            }
            $past = Appointment::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('completed-visit')], ['patient_id' => $patients[0]->id, 'referral_id' => $visits[0]->referral_id, 'provider_id' => $users['nurse']->id, 'service' => 'Demo completed visit', 'starts_at' => now()->subDay()->startOfHour(), 'ends_at' => now()->subDay()->startOfHour()->addMinutes(45), 'location_type' => 'clinic', 'location' => 'Synthetic demo clinic', 'status' => 'completed', 'confirmation_evidence' => 'Synthetic confirmation only', 'completion_evidence' => 'Synthetic operational completion only']);
            if ($past->offer_status === 'pending') {
                $past->update(['offer_status' => 'accepted', 'offered_at' => $past->created_at, 'offer_responded_at' => $past->created_at, 'offer_evidence' => 'Synthetic provider acceptance; no real visit.']);
            }
            $attendanceEvents = [];
            foreach (['arrival' => 0, 'departure' => 45] as $kind => $minutes) {
                $attendanceEvents[$kind] = $past->attendance()->firstOrCreate(['kind' => $kind], ['actor_id' => $users['nurse']->id, 'occurred_at' => $past->starts_at->copy()->addMinutes($minutes), 'received_at' => $past->starts_at->copy()->addMinutes($minutes), 'capture_status' => 'denied', 'exception_reason' => 'Synthetic demo: no real location collected.', 'request_id' => $this->uuid('attendance-'.$kind)]);
            }
            AttendanceReview::firstOrCreate(['request_id' => $this->uuid('attendance-review-arrival')], ['attendance_event_id' => $attendanceEvents['arrival']->id, 'decision' => 'confirmed_exception', 'evidence' => 'SYNTHETIC location exception review; no real attendance was verified.', 'reviewed_by' => $users['lead']->id, 'reviewed_at' => now()->subHours(20)]);
            AttendanceReview::firstOrCreate(['request_id' => $this->uuid('attendance-review-departure')], ['attendance_event_id' => $attendanceEvents['departure']->id, 'decision' => 'correction_required', 'evidence' => 'SYNTHETIC correction request demonstration only.', 'reviewed_by' => $users['lead']->id, 'reviewed_at' => now()->subHours(19)]);
            AttendanceCorrection::firstOrCreate(['request_id' => $this->uuid('attendance-correction-departure')], [
                'attendance_event_id' => $attendanceEvents['departure']->id, 'appointment_id' => $past->id, 'requested_by' => $users['nurse']->id,
                'proposed_occurred_at' => $past->starts_at->copy()->addMinutes(44), 'reason' => 'SYNTHETIC one-minute correction example.',
                'evidence' => 'Synthetic workflow evidence; no real visit record.', 'status' => 'approved', 'reviewed_by' => $users['lead']->id,
                'review_evidence' => 'Synthetic independent approval.', 'reviewed_at' => now()->subHours(18),
            ]);
            $missedVisit = Appointment::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('missed-visit')], [
                'patient_id' => $patients[1]->id, 'referral_id' => $visits[1]->referral_id, 'provider_id' => $users['nurse']->id,
                'service' => 'SYNTHETIC missed visit demonstration', 'starts_at' => now()->subDays(2)->startOfHour(), 'ends_at' => now()->subDays(2)->startOfHour()->addMinutes(45),
                'location_type' => 'home', 'location' => 'Synthetic demonstration address only', 'status' => 'missed', 'offer_status' => 'accepted',
                'offered_at' => now()->subDays(3), 'offer_responded_at' => now()->subDays(3), 'offer_evidence' => 'Synthetic acceptance only.',
                'cancellation_reason' => 'SYNTHETIC missed visit; no real patient or welfare event.',
            ]);
            $missedTask = CareTask::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('missed-visit-task')], [
                'patient_id' => $patients[1]->id, 'title' => 'Demo missed-visit welfare and replacement', 'description' => 'SYNTHETIC workflow only. No contact is required or performed.',
                'priority' => 'high', 'status' => 'new', 'owner_id' => $users['coordinator']->id, 'backup_id' => $users['lead']->id, 'created_by' => $admin->id, 'due_at' => now()->addDay(),
            ]);
            MissedVisitCase::firstOrCreate(['request_id' => $this->uuid('missed-visit-case')], [
                'organisation_id' => $org->id, 'patient_id' => $patients[1]->id, 'appointment_id' => $missedVisit->id,
                'missed_reason' => 'SYNTHETIC missed-visit evidence; no real appointment was missed.', 'reported_by' => $admin->id, 'reported_at' => now()->subDays(2),
                'owner_id' => $users['coordinator']->id, 'backup_id' => $users['lead']->id, 'task_id' => $missedTask->id, 'due_at' => now()->addDay(),
            ]);
            $basic = PatientAssessment::firstOrCreate(['request_id' => $this->uuid('basic-submitted')], ['patient_id' => $patients[0]->id, 'kind' => 'basic', 'status' => 'submitted', 'schema_version' => config('assessments.schema_version'), 'content' => self::assessmentContent('basic'), 'author_id' => $patientUser->id, 'confirmed_by' => $patientUser->id, 'submitted_at' => now()->subDay(), 'confirmation_method' => 'linked_patient', 'confirmation_evidence' => 'Synthetic explicit patient confirmation.', 'content_hash' => hash('sha256', 'synthetic-basic-example')]);
            PatientAssessment::firstOrCreate(['request_id' => $this->uuid('basic-amendment')], ['patient_id' => $patients[0]->id, 'kind' => 'basic', 'schema_version' => config('assessments.schema_version'), 'content' => self::assessmentContent('basic'), 'author_id' => $patientUser->id, 'previous_id' => $basic->id, 'amendment_reason' => 'Synthetic correction draft for demonstration.']);
            foreach (['signed', 'awaiting_cosign', 'draft'] as $i => $status) {
                PatientAssessment::firstOrCreate(['request_id' => $this->uuid('nursing-'.$status)], ['patient_id' => $patients[$i]->id, 'appointment_id' => $i === 0 ? $past->id : $visits[$i]->id, 'kind' => 'nursing', 'status' => $status, 'schema_version' => config('assessments.schema_version'), 'content' => self::assessmentContent('nursing'), 'author_id' => $users['nurse']->id, 'signed_by' => $status === 'draft' ? null : $users['nurse']->id, 'signed_at' => $status === 'draft' ? null : now()->subMinutes(5), 'cosign_required' => true, 'cosigned_by' => $status === 'signed' ? $users['lead']->id : null, 'cosigned_at' => $status === 'signed' ? now() : null, 'review_due' => $status === 'draft' ? null : now()->addWeek()->toDateString(), 'content_hash' => $status === 'draft' ? null : hash('sha256', 'synthetic-nursing-'.$status)]);
            }
            foreach (PatientAssessment::whereIn('patient_id', collect($patients)->pluck('id'))->whereIn('content_hash', [hash('sha256', 'synthetic-basic-example'), hash('sha256', 'synthetic-nursing-signed'), hash('sha256', 'synthetic-nursing-awaiting_cosign')])->get() as $assessment) {
                $assessment->update(['content_hash' => $assessment->digest($assessment->content)]);
            }
            $observation = ClinicalObservation::firstOrCreate(['request_id' => $this->uuid('observation-original')], [
                'patient_id' => $patients[0]->id,
                'appointment_id' => $past->id,
                'author_id' => $users['nurse']->id,
                'observed_at' => $past->starts_at->copy()->addMinutes(10),
                'measurements' => [
                    ['type' => 'temperature', 'value' => '36.8', 'unit' => '°C', 'method' => 'Synthetic demo device context; not a real measurement.'],
                    ['type' => 'pulse', 'value' => '72', 'unit' => 'beats/min', 'method' => 'Synthetic demonstration only.'],
                ],
                'context' => 'SYNTHETIC observation set. Never use for care decisions.',
                'content_hash' => str_repeat('0', 64),
            ]);
            if ($observation->content_hash === str_repeat('0', 64)) {
                $observation->update(['content_hash' => $observation->digest()]);
            }
            $correction = ClinicalObservation::firstOrCreate(['request_id' => $this->uuid('observation-correction')], [
                'patient_id' => $patients[0]->id,
                'appointment_id' => $past->id,
                'author_id' => $users['nurse']->id,
                'supersedes_id' => $observation->id,
                'observed_at' => $past->starts_at->copy()->addMinutes(10),
                'measurements' => [
                    ['type' => 'temperature', 'value' => '36.7', 'unit' => '°C', 'method' => 'Synthetic corrected demo value; not a real measurement.'],
                    ['type' => 'pulse', 'value' => '72', 'unit' => 'beats/min', 'method' => 'Synthetic demonstration only.'],
                ],
                'context' => 'SYNTHETIC corrected observation set. Never use for care decisions.',
                'correction_reason' => 'Synthetic demonstration of an append-only transcription correction.',
                'content_hash' => str_repeat('0', 64),
            ]);
            if ($correction->content_hash === str_repeat('0', 64)) {
                $correction->update(['content_hash' => $correction->digest()]);
            }
            $followUp = ClinicalObservation::firstOrCreate(['request_id' => $this->uuid('observation-follow-up')], [
                'patient_id' => $patients[0]->id,
                'appointment_id' => $past->id,
                'author_id' => $users['nurse']->id,
                'observed_at' => $past->starts_at->copy()->addMinutes(35),
                'measurements' => [
                    ['type' => 'temperature', 'value' => '36.9', 'unit' => '°C', 'method' => 'Synthetic demo device context; not a real measurement.'],
                    ['type' => 'pulse', 'value' => '74', 'unit' => 'beats/min', 'method' => 'Synthetic demonstration only.'],
                    ['type' => 'oxygen_saturation', 'value' => '98', 'unit' => '%', 'method' => 'Synthetic demonstration only.'],
                ],
                'context' => 'SYNTHETIC follow-up observation set for trend display. Never use for care decisions.',
                'content_hash' => str_repeat('0', 64),
            ]);
            if ($followUp->content_hash === str_repeat('0', 64)) {
                $followUp->update(['content_hash' => $followUp->digest()]);
            }
            $reconciliationAttributes = [
                'patient_id' => $patients[0]->id,
                'appointment_id' => $past->id,
                'author_id' => $users['nurse']->id,
                'supersedes_id' => null,
                'reconciled_at' => $past->starts_at->copy()->addMinutes(5),
                'status' => 'complete',
                'sources' => ['SYNTHETIC patient interview', 'SYNTHETIC labelled container review'],
                'medicines' => [['name' => 'DEMO medicine — not for clinical use', 'dose' => 'DEMO dose', 'route' => 'oral', 'frequency' => 'DEMO frequency', 'status' => 'taking']],
                'discrepancies' => 'Synthetic demonstration only; no real discrepancy.',
                'follow_up' => null,
                'correction_reason' => null,
            ];
            MedicationReconciliation::firstOrCreate(['request_id' => $this->uuid('medication-reconciliation')], [...$reconciliationAttributes, 'content_hash' => MedicationReconciliation::digestFor($reconciliationAttributes)]);
            $instructionAttributes = [
                'patient_id' => $patients[0]->id,
                'verifier_id' => $users['doctor']->id,
                'supersedes_id' => null,
                'verified_at' => $past->starts_at->copy()->subHour(),
                'starts_at' => $past->starts_at->copy()->subDay(),
                'ends_at' => now()->addMonth(),
                'status' => 'active',
                'details' => [
                    'medicine' => 'DEMO medicine — not for clinical use',
                    'dose' => 'DEMO dose',
                    'route' => 'oral',
                    'frequency' => 'DEMO frequency',
                    'instructions' => 'SYNTHETIC instruction. Never administer.',
                    'prescriber_name' => 'Demo External Prescriber',
                    'prescriber_registration' => 'DEMO-NOT-A-REGISTRATION',
                    'source_reference' => 'DEMO-NOT-A-PRESCRIPTION',
                    'verification_evidence' => 'Synthetic external source verification for workflow demonstration only.',
                ],
                'correction_reason' => null,
            ];
            $instruction = MedicationInstruction::firstOrCreate(['request_id' => $this->uuid('medication-instruction')], [...$instructionAttributes, 'content_hash' => MedicationInstruction::digestFor($instructionAttributes)]);
            $administrationAttributes = [
                'patient_id' => $patients[0]->id,
                'medication_instruction_id' => $instruction->id,
                'appointment_id' => $past->id,
                'administrator_id' => $users['nurse']->id,
                'supersedes_id' => null,
                'administered_at' => $past->starts_at->copy()->addMinutes(20),
                'outcome' => 'omitted',
                'instruction_snapshot' => ['instruction_id' => $instruction->id, 'instruction_hash' => $instruction->content_hash, ...$instruction->details],
                'reason' => 'SYNTHETIC record: no medicine was administered.',
                'notes' => 'Synthetic demonstration only.',
                'correction_reason' => null,
            ];
            MedicationAdministration::firstOrCreate(['request_id' => $this->uuid('medication-administration')], [...$administrationAttributes, 'content_hash' => MedicationAdministration::digestFor($administrationAttributes)]);
            $investigationAttributes = [
                'patient_id' => $patients[0]->id,
                'requester_id' => $users['doctor']->id,
                'requested_at' => $past->starts_at->copy()->subHours(3),
                'category' => 'laboratory',
                'details' => ['name' => 'DEMO investigation — not a real order', 'clinical_question' => 'Synthetic demonstration question only.', 'destination' => 'Demo External Laboratory', 'source_reference' => 'DEMO-NOT-AN-ORDER'],
            ];
            $investigation = Investigation::firstOrCreate(['request_id' => $this->uuid('investigation-request')], [...$investigationAttributes, 'content_hash' => Investigation::digestFor($investigationAttributes)]);
            $resultAttributes = [
                'patient_id' => $patients[0]->id,
                'investigation_id' => $investigation->id,
                'received_by' => $users['nurse']->id,
                'owner_id' => $users['doctor']->id,
                'backup_id' => $users['coordinator']->id,
                'supersedes_id' => null,
                'received_at' => $past->starts_at->copy()->subHour(),
                'source_reported_at' => $past->starts_at->copy()->subHours(2),
                'source_flag' => 'routine',
                'details' => ['result_type' => 'final', 'source_reference' => 'DEMO-NOT-A-RESULT', 'summary' => 'SYNTHETIC result summary. Never use for care decisions.', 'source_comment' => 'Synthetic demonstration only.', 'values' => [['label' => 'DEMO value', 'value' => 'NOT A REAL VALUE', 'unit' => 'demo']], 'escalated_at' => null, 'escalation_channel' => null, 'escalation_evidence' => null],
                'correction_reason' => null,
            ];
            $result = InvestigationResult::firstOrCreate(['request_id' => $this->uuid('investigation-result')], [...$resultAttributes, 'status' => 'acknowledged', 'acknowledgement' => ['review_summary' => 'Synthetic owner review only.', 'patient_communication' => 'pending', 'communication_evidence' => 'Synthetic demonstration: no real patient contact.', 'follow_up_required' => 'yes', 'follow_up_plan' => 'Synthetic follow-up only.', 'follow_up_due' => now()->addWeek()->toISOString()], 'acknowledged_by' => $users['doctor']->id, 'acknowledged_at' => now()->subMinutes(30), 'content_hash' => InvestigationResult::digestFor($resultAttributes)]);
            $resultTask = CareTask::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('investigation-result-task')], ['patient_id' => $patients[0]->id, 'title' => 'Follow up synthetic investigation result #'.$result->id, 'description' => 'Synthetic tracking only; no clinical result content is included.', 'priority' => 'routine', 'status' => 'waiting', 'owner_id' => $users['doctor']->id, 'backup_id' => $users['coordinator']->id, 'created_by' => $users['nurse']->id, 'due_at' => now()->addWeek(), 'waiting_reason' => 'Synthetic accountable follow-up demonstration.', 'next_check_at' => now()->addWeek()]);
            if (! $result->task_id) {
                $result->update(['task_id' => $resultTask->id]);
            }
            InvestigationPublication::firstOrCreate(['request_id' => $this->uuid('investigation-publication')], ['patient_id' => $patients[0]->id, 'investigation_result_id' => $result->id, 'content' => ['summary' => 'Reviewed synthetic result summary for portal demonstration only.', 'next_steps' => 'Synthetic next step: contact the demo care team.'], 'source_hash' => $result->content_hash, 'published_by' => $users['lead']->id, 'published_at' => now()->subMinutes(20)]);
            $demoDocument = PatientDocument::firstOrCreate(['request_id' => $this->uuid('document-metadata-only')], [
                'patient_id' => $patients[0]->id,
                'uploaded_by' => $users['nurse']->id,
                'category' => 'clinical',
                'original_name' => 'DEMO-metadata-only-no-file.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 0,
                'sha256' => hash('sha256', ''),
                'storage_path' => null,
                'status' => 'quarantined',
                'description' => 'SYNTHETIC metadata-only quarantine example. No file or patient content was created.',
            ]);
            DocumentExport::firstOrCreate(['request_id' => $this->uuid('document-export-rejected')], [
                'patient_id' => $patients[0]->id,
                'requested_by' => $users['doctor']->id,
                'document_ids' => [$demoDocument->id],
                'purpose' => 'SYNTHETIC rejected export demonstration; no recipient and no disclosure.',
                'status' => 'rejected',
                'review_evidence' => 'Synthetic reviewer rejection because the metadata-only demonstration record has no releasable file.',
                'reviewed_by' => $users['lead']->id,
                'reviewed_at' => now()->subMinutes(10),
            ]);
            $dischargePatient = Patient::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('discharge-patient')], [
                'reference' => $this->uuid('discharge-patient-ref'),
                'name' => 'Demo Discharge Patient',
                'locality' => 'Curepipe',
                'address' => 'Synthetic demonstration address.',
                'authority_type' => 'patient_request',
                'authority_evidence' => 'Synthetic episode discharge demonstration only.',
            ]);
            PatientAccessRule::firstOrCreate(['request_id' => $this->uuid('discharge-assignment')], [
                'patient_id' => $dischargePatient->id, 'user_id' => $users['lead']->id, 'scope' => 'all',
                'kind' => 'assignment', 'role' => 'clinical_admin', 'purpose' => 'Synthetic discharge review',
                'authority_type' => 'care_assignment', 'evidence' => 'Synthetic authority only.', 'wording_version' => 'DEMO-v1',
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'recorded_by' => $admin->id,
            ]);
            $dischargeEpisode = CareEpisode::firstOrCreate(['request_id' => $this->uuid('discharge-episode')], [
                'patient_id' => $dischargePatient->id, 'title' => 'Demo episode ready for discharge', 'created_by' => $users['nurse']->id,
            ]);
            $dischargePlan = CarePlan::firstOrCreate(['request_id' => $this->uuid('discharge-plan')], [
                'patient_id' => $dischargePatient->id, 'episode_id' => $dischargeEpisode->id, 'author_id' => $users['nurse']->id,
                'revision_number' => 1, 'content' => ClinicalRecordDemoSeeder::planContent($users['nurse']->id), 'status' => 'completed',
                'signed_by' => $users['nurse']->id, 'signed_at' => now()->subDays(2),
                'approved_by' => $users['lead']->id, 'approved_at' => now()->subDay(),
            ]);
            if ($dischargePlan->wasRecentlyCreated) {
                $dischargePlan->update(['content_hash' => $dischargePlan->digest($dischargePlan->content)]);
            }
            $this->call(ClinicalRecordDemoSeeder::class);
            $handoverEpisode = CareEpisode::where('patient_id', $patients[0]->id)->firstOrFail();
            foreach (['nurse', 'lead'] as $key) {
                foreach (['handover.read', 'handover.write', 'handover.acknowledge'] as $scope) {
                    PatientAccessRule::firstOrCreate(['request_id' => $this->uuid('handover-'.$key.'-'.$scope)], [
                        'patient_id' => $patients[0]->id, 'user_id' => $users[$key]->id,
                        'kind' => 'grant', 'scope' => $scope, 'purpose' => 'Synthetic episode handover demonstration',
                        'authority_type' => 'patient_consent', 'evidence' => 'Synthetic authority only.',
                        'wording_version' => 'DEMO-v1', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
                        'recorded_by' => $admin->id,
                    ]);
                }
            }
            EpisodeHandover::firstOrCreate(['request_id' => $this->uuid('sbar-handover'), 'care_episode_id' => $handoverEpisode->id, 'sender_id' => $users['lead']->id], [
                'recipient_id' => $users['nurse']->id,
                'content' => [
                    'situation' => 'SYNTHETIC handover for a demo episode.',
                    'background' => 'Synthetic background for workflow practice only.',
                    'assessment' => 'Synthetic assessment; no actual clinical finding.',
                    'recommendation' => 'Acknowledge and review the synthetic care-plan information.',
                ],
                'priority' => 'routine',
                'due_at' => now()->addDay(),
            ]);
            CareTask::firstOrCreate(['organisation_id' => $org->id, 'request_id' => $this->uuid('handover-transfer-task')], [
                'patient_id' => $patients[0]->id,
                'title' => 'Demo handover coordination task',
                'description' => 'Synthetic task: review a demo care coordination checklist.',
                'priority' => 'routine',
                'status' => 'new',
                'owner_id' => $users['nurse']->id,
                'backup_id' => $users['coordinator']->id,
                'created_by' => $users['coordinator']->id,
                'due_at' => now()->addDays(2),
                'handover_transferable' => true,
            ]);
            DB::table('operation_notifications')->updateOrInsert(['deduplication_key' => 'demo-welcome-'.$org->id], ['organisation_id' => $org->id, 'user_id' => $users['coordinator']->id, 'message' => 'Synthetic demo: review the overdue coordination task.', 'url' => '/workspace/tasks', 'created_at' => now(), 'updated_at' => now()]);
            if (! DB::table('audit_events')->where('organisation_id', $org->id)->where('event', 'demo.seeded')->exists()) {
                DB::table('audit_events')->insert(['organisation_id' => $org->id, 'actor_id' => $admin->id, 'event' => 'demo.seeded', 'created_at' => now()]);
            }
        });
    }
}
