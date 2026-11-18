<?php

namespace Tests\Feature;

use App\Models\DocumentExport;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\PatientDocument;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentVaultTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    private User $doctor;

    private User $lead;

    private Patient $patient;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->seed(DemoDataSeeder::class);
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->patient = Patient::whereNotNull('user_id')->firstOrFail();
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function url(string $suffix = ''): string
    {
        return '/patient-records/'.$this->patient->id.'/documents'.$suffix;
    }

    private function signature(): array
    {
        return ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
    }

    private function upload(array $extra = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(),
            'category' => 'clinical',
            'description' => 'Synthetic private document test',
            'file' => UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n% synthetic test only\n%%EOF"),
            ...$this->signature(),
        ], ...$extra];
    }

    private function releasedDocument(): PatientDocument
    {
        $payload = $this->upload();
        $this->as($this->nurse)->post($this->url(), $payload)->assertSessionHasNoErrors();
        $document = PatientDocument::where('request_id', $payload['request_id'])->firstOrFail();
        $this->as($this->lead)->post($this->url('/'.$document->id.'/review'), [
            'version' => 1, 'decision' => 'release', 'review_evidence' => 'Synthetic external scanner TEST-SCANNER returned clean for the exact SHA-256.', ...$this->signature(),
        ])->assertSessionHasNoErrors();

        return $document->fresh();
    }

    public function test_upload_is_private_encrypted_and_quarantined_until_independent_review(): void
    {
        $payload = $this->upload();
        $this->as($this->nurse)->post($this->url(), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $document = PatientDocument::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame('quarantined', $document->status);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertStringNotContainsString('synthetic-report.pdf', $document->storage_path);
        $raw = DB::table('patient_documents')->where('id', $document->id)->first();
        $this->assertStringNotContainsString('synthetic-report.pdf', $raw->original_name);
        $this->assertStringNotContainsString('Synthetic private', $raw->description);
        $this->get($this->url('/'.$document->id.'/download'))->assertNotFound();
        $this->post($this->url('/'.$document->id.'/review'), ['version' => 1, 'decision' => 'release', 'review_evidence' => 'Self review is forbidden.', ...$this->signature()])->assertForbidden();

        $this->as($this->lead)->post($this->url('/'.$document->id.'/review'), ['version' => 1, 'decision' => 'release', 'review_evidence' => 'Synthetic external scanner TEST-SCANNER returned clean for exact checksum.', ...$this->signature()])->assertSessionHasNoErrors();
        $response = $this->as($this->nurse)->get($this->url('/'.$document->id.'/download'));
        $response->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->lead->id, 'event' => 'patient.document.released']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'patient.document.downloaded', 'resource_id' => $document->id]);
    }

    public function test_category_grants_validation_integrity_and_idempotency_are_enforced(): void
    {
        $payload = $this->upload();
        $this->as($this->nurse)->post($this->url(), $payload)->assertSessionHasNoErrors();
        $count = PatientDocument::count();
        $payload['file'] = UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n% synthetic test only\n%%EOF");
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->assertSame($count, PatientDocument::count());
        $payload['file'] = UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n% different content\n%%EOF");
        $this->post($this->url(), $payload)->assertConflict();
        $this->post($this->url(), $this->upload(['file' => UploadedFile::fake()->createWithContent('unsafe.html', '<script>bad</script>')]))->assertSessionHasErrors('file');

        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->doctor->id)->where('scope', 'documents.identity.read')->update(['revoked_at' => now()]);
        $this->as($this->doctor)->post($this->url(), $this->upload(['category' => 'identity']))->assertForbidden();
        $this->as($this->nurse)->post($this->url(), $this->upload(['category' => 'identity']))->assertForbidden();
    }

    public function test_two_person_export_is_exact_time_limited_and_only_requester_can_download(): void
    {
        $document = $this->releasedDocument();
        $requestId = (string) Str::uuid();
        $payload = ['request_id' => $requestId, 'document_ids' => [$document->id], 'purpose' => 'Synthetic minimum-necessary disclosure to a test recipient.', ...$this->signature()];
        $this->as($this->doctor)->post('/patient-records/'.$this->patient->id.'/document-exports', $payload)->assertSessionHasNoErrors();
        $export = DocumentExport::where('request_id', $requestId)->firstOrFail();
        $this->post('/patient-records/'.$this->patient->id.'/document-exports', $payload)->assertSessionHasNoErrors();
        $this->post('/patient-records/'.$this->patient->id.'/document-exports', [...$payload, 'purpose' => 'Conflicting retry purpose.'])->assertConflict();
        $this->post('/patient-records/'.$this->patient->id.'/document-exports/'.$export->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Requester cannot self approve.', 'expires_at' => now()->addDay()->format('Y-m-d H:i:s'), ...$this->signature()])->assertForbidden();

        $this->as($this->lead)->post('/patient-records/'.$this->patient->id.'/document-exports/'.$export->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Synthetic minimum-necessary and recipient control review completed.', 'expires_at' => now()->addDay()->format('Y-m-d H:i:s'), ...$this->signature()])->assertRedirect()->assertSessionHasNoErrors();
        $export = $export->fresh();
        $this->assertSame('approved', $export->status);
        Storage::disk('local')->assertExists($export->archive_path);
        $this->as($this->lead)->get('/patient-records/'.$this->patient->id.'/document-exports/'.$export->id.'/download')->assertForbidden();
        $this->as($this->doctor)->get('/patient-records/'.$this->patient->id.'/document-exports/'.$export->id.'/download')->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertDatabaseHas('audit_events', ['event' => 'patient.document_export.approved', 'resource_id' => $export->id]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->doctor->id, 'event' => 'patient.document_export.downloaded']);
    }

    public function test_visibility_roles_tenant_blocks_and_demo_metadata_are_safe(): void
    {
        $this->as($this->nurse)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('documents/index', false)->where('canUpload', true)->where('canReview', false)->where('canExport', true)->has('documents', 1));
        $this->as($this->lead)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canReview', true)->has('categories', 3)->has('exports', 1));
        $metadata = PatientDocument::all()->first(fn (PatientDocument $document) => $document->original_name === 'DEMO-metadata-only-no-file.pdf');
        $this->assertNotNull($metadata);
        $this->assertNull($metadata->storage_path);
        $this->assertSame('quarantined', $metadata->status);

        $coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->as($coordinator)->get($this->url())->assertForbidden();
        $other = Organisation::create(['name' => 'Other document tenant']);
        $this->as($this->nurse)->withSession(['organisation_id' => $other->id])->get($this->url())->assertNotFound();
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->where('kind', 'assignment')->update(['revoked_at' => now()]);
        $this->as($this->nurse)->get($this->url())->assertForbidden();
    }
}
