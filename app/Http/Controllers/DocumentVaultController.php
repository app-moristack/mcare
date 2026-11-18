<?php

namespace App\Http\Controllers;

use App\Models\DocumentExport;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use ZipArchive;

class DocumentVaultController extends Controller
{
    private const CATEGORIES = ['identity', 'clinical', 'administrative'];

    private function patient(Request $request, int $id): Patient
    {
        $patient = Patient::findOrFail($id);
        $organisations = Workspace::memberships($request->user())->pluck('organisation_id');
        abort_unless(Workspace::selectedOrganisation($request) === $patient->organisation_id && $organisations->contains($patient->organisation_id), 404);

        return $patient;
    }

    private function scope(string $category): string
    {
        return 'documents.'.$category.'.read';
    }

    private function readableCategories(Request $request, Patient $patient): array
    {
        return array_values(array_filter(self::CATEGORIES, fn (string $category) => PatientAccess::allowed($request->user(), $patient, $this->scope($category))));
    }

    private function enforceCategory(Request $request, Patient $patient, string $category): void
    {
        abort_unless(in_array($category, self::CATEGORIES, true), 404);
        PatientAccess::enforce($request, $patient, $this->scope($category));
    }

    public function index(Request $request, int $id)
    {
        $patient = $this->patient($request, $id);
        $categories = $this->readableCategories($request, $patient);
        abort_if($categories === [], 403, 'A category-specific document grant is required.');
        $documents = PatientDocument::where('patient_id', $patient->id)->whereIn('category', $categories)
            ->with(['uploader:id,name', 'reviewer:id,name'])->latest()->get()->map(fn (PatientDocument $document) => [
                ...$document->only(['id', 'category', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'status', 'description', 'review_evidence', 'reviewed_at', 'version', 'created_at']),
                'uploader' => $document->uploader?->only(['id', 'name']), 'reviewer' => $document->reviewer?->only(['id', 'name']),
                'downloadable' => $document->status === 'released' && filled($document->storage_path),
            ]);
        $canReview = PatientAccess::allowed($request->user(), $patient, 'documents.review');
        $documentCategories = PatientDocument::where('patient_id', $patient->id)->pluck('category', 'id');
        $exports = DocumentExport::where('patient_id', $patient->id)->where(function ($query) use ($request, $canReview) {
            $query->where('requested_by', $request->user()->id);
            if ($canReview) {
                $query->orWhereNotNull('id');
            }
        })->with(['requester:id,name', 'reviewer:id,name'])->latest()->get()
            ->filter(fn (DocumentExport $export) => collect($export->document_ids)->every(fn ($documentId) => in_array($documentCategories->get($documentId), $categories, true)))
            ->map(fn (DocumentExport $export) => [
                ...$export->only(['id', 'document_ids', 'purpose', 'status', 'review_evidence', 'reviewed_at', 'expires_at', 'version', 'created_at']),
                'requester' => $export->requester?->only(['id', 'name']), 'reviewer' => $export->reviewer?->only(['id', 'name']),
                'downloadable' => $export->status === 'approved' && $export->expires_at?->isFuture() && filled($export->archive_path),
            ]);
        Workspace::audit($request, 'patient.documents.viewed', $patient->organisation_id, 'patients', $patient->id);

        return Inertia::render('documents/index', [
            'patient' => $patient->only(['id', 'name', 'reference']), 'documents' => $documents, 'exports' => $exports, 'categories' => $categories,
            'canUpload' => PatientAccess::allowed($request->user(), $patient, 'documents.upload'),
            'canReview' => $canReview,
            'canExport' => PatientAccess::allowed($request->user(), $patient, 'documents.export'),
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function upload(Request $request, int $id)
    {
        $patient = $this->patient($request, $id);
        PatientAccess::enforce($request, $patient, 'documents.upload');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'category' => ['required', Rule::in(self::CATEGORIES)], 'description' => 'nullable|string|max:2000',
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png|mimetypes:application/pdf,image/jpeg,image/png',
            'password' => 'required|current_password', 'declaration' => 'accepted',
        ]);
        $this->enforceCategory($request, $patient, $data['category']);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $sha = hash_file('sha256', $file->getRealPath());
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (! $extension) {
            throw ValidationException::withMessages(['file' => 'Only server-identified PDF, JPEG and PNG files are accepted.']);
        }
        $originalName = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 255, '');
        if ($existing = PatientDocument::where('request_id', $data['request_id'])->first()) {
            abort_unless($existing->patient_id === $patient->id && $existing->uploaded_by === $request->user()->id && $existing->category === $data['category'] && hash_equals($existing->sha256, $sha) && $existing->original_name === $originalName && $existing->description === ($data['description'] ?? null), 409, 'This request identifier was already used for different content.');

            return back()->with('success', 'Upload already received and remains in quarantine.');
        }
        $path = 'patient-documents/'.$patient->id.'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, fopen($file->getRealPath(), 'rb'))) {
            throw ValidationException::withMessages(['file' => 'The private file store is unavailable. Nothing was recorded.']);
        }
        try {
            PatientDocument::create([
                'patient_id' => $patient->id, 'uploaded_by' => $request->user()->id, 'category' => $data['category'],
                'original_name' => $originalName, 'mime_type' => $mime,
                'size_bytes' => $file->getSize(), 'sha256' => $sha, 'storage_path' => $path, 'description' => $data['description'] ?? null,
                'request_id' => $data['request_id'],
            ]);
            Workspace::audit($request, 'patient.document.quarantined', $patient->organisation_id, 'patients', $patient->id);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('success', 'File uploaded to private quarantine. It cannot be downloaded until an authorised reviewer records external scan evidence.');
    }

    public function review(Request $request, int $id, int $documentId)
    {
        $patient = $this->patient($request, $id);
        PatientAccess::enforce($request, $patient, 'documents.review');
        $data = $request->validate([
            'version' => 'required|integer', 'decision' => ['required', Rule::in(['release', 'reject'])],
            'review_evidence' => 'required|string|max:3000', 'password' => 'required|current_password', 'declaration' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $patient, $documentId, $data) {
            $document = PatientDocument::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($documentId);
            $this->enforceCategory($request, $patient, $document->category);
            abort_unless($document->status === 'quarantined' && $document->version === (int) $data['version'], 409, 'The quarantine state changed. Reload before reviewing.');
            abort_unless($document->uploaded_by !== $request->user()->id, 403, 'The uploader cannot release their own file.');
            abort_unless(filled($document->storage_path) && Storage::disk('local')->exists($document->storage_path), 409, 'The quarantined blob is unavailable.');
            abort_unless(hash_equals($document->sha256, hash_file('sha256', Storage::disk('local')->path($document->storage_path))), 409, 'File integrity check failed.');
            $document->update(['status' => $data['decision'] === 'release' ? 'released' : 'rejected', 'review_evidence' => $data['review_evidence'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'version' => $document->version + 1]);
            Workspace::audit($request, 'patient.document.'.$document->status, $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', $data['decision'] === 'release' ? 'File released for category-scoped downloads.' : 'File rejected and retained in quarantine for audit.');
    }

    public function download(Request $request, int $id, int $documentId)
    {
        $patient = $this->patient($request, $id);
        $document = PatientDocument::where('patient_id', $patient->id)->findOrFail($documentId);
        $this->enforceCategory($request, $patient, $document->category);
        abort_unless($document->status === 'released' && filled($document->storage_path), 404);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);
        abort_unless(hash_equals($document->sha256, hash_file('sha256', Storage::disk('local')->path($document->storage_path))), 409, 'File integrity check failed.');
        Workspace::audit($request, 'patient.document.downloaded', $patient->organisation_id, 'patient_documents', $document->id);

        return Storage::disk('local')->download($document->storage_path, $document->original_name, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function requestExport(Request $request, int $id)
    {
        $patient = $this->patient($request, $id);
        PatientAccess::enforce($request, $patient, 'documents.export');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'document_ids' => 'required|array|min:1|max:20', 'document_ids.*' => 'integer|distinct',
            'purpose' => 'required|string|max:2000', 'password' => 'required|current_password', 'declaration' => 'accepted',
        ]);
        $ids = array_map('intval', $data['document_ids']);
        $documents = PatientDocument::where('patient_id', $patient->id)->whereIn('id', $ids)->get();
        if ($documents->count() !== count($ids) || $documents->contains(fn (PatientDocument $document) => $document->status !== 'released')) {
            throw ValidationException::withMessages(['document_ids' => 'Choose only current released documents from this patient vault.']);
        }
        if ($documents->sum('size_bytes') > 100 * 1024 * 1024) {
            throw ValidationException::withMessages(['document_ids' => 'The export cannot exceed 100 MB. Select a smaller minimum-necessary file set.']);
        }
        foreach ($documents as $document) {
            $this->enforceCategory($request, $patient, $document->category);
        }
        DB::transaction(function () use ($request, $patient, $data, $ids) {
            if ($existing = DocumentExport::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->patient_id === $patient->id && $existing->requested_by === $request->user()->id && $existing->document_ids === $ids && $existing->purpose === $data['purpose'], 409, 'This request identifier was already used for different content.');

                return;
            }
            DocumentExport::create(['patient_id' => $patient->id, 'requested_by' => $request->user()->id, 'document_ids' => $ids, 'purpose' => $data['purpose'], 'request_id' => $data['request_id']]);
            Workspace::audit($request, 'patient.document_export.requested', $patient->organisation_id, 'patients', $patient->id);
        });

        return back()->with('success', 'Export requested. A different authorised reviewer must approve the exact file set.');
    }

    public function reviewExport(Request $request, int $id, int $exportId)
    {
        $patient = $this->patient($request, $id);
        PatientAccess::enforce($request, $patient, 'documents.review');
        $data = $request->validate([
            'version' => 'required|integer', 'decision' => ['required', Rule::in(['approve', 'reject'])], 'review_evidence' => 'required|string|max:3000',
            'expires_at' => 'nullable|required_if:decision,approve|date|after:now|before_or_equal:+7 days', 'password' => 'required|current_password', 'declaration' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $patient, $exportId, $data) {
            $export = DocumentExport::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($exportId);
            abort_unless($export->status === 'pending' && $export->version === (int) $data['version'], 409, 'The export state changed. Reload before reviewing.');
            abort_unless($export->requested_by !== $request->user()->id, 403, 'The requester cannot approve their own export.');
            if ($data['decision'] === 'reject') {
                $export->update(['status' => 'rejected', 'review_evidence' => $data['review_evidence'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'version' => $export->version + 1]);
            } else {
                $documents = PatientDocument::where('patient_id', $patient->id)->whereIn('id', $export->document_ids)->get();
                abort_unless($documents->count() === count($export->document_ids) && $documents->every(fn (PatientDocument $document) => $document->status === 'released'), 409, 'One or more selected files are no longer released.');
                foreach ($documents as $document) {
                    $this->enforceCategory($request, $patient, $document->category);
                }
                [$path, $hash] = $this->buildArchive($patient, $export, $documents);
                $export->update(['status' => 'approved', 'review_evidence' => $data['review_evidence'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'archive_path' => $path, 'archive_sha256' => $hash, 'expires_at' => $data['expires_at'], 'version' => $export->version + 1]);
            }
            Workspace::audit($request, 'patient.document_export.'.$export->status, $patient->organisation_id, 'document_exports', $export->id);
        });

        return back()->with('success', $data['decision'] === 'approve' ? 'Export approved, generated and time-limited.' : 'Export rejected.');
    }

    private function buildArchive(Patient $patient, DocumentExport $export, $documents): array
    {
        $path = 'document-exports/'.$patient->id.'/'.Str::uuid().'.zip';
        Storage::disk('local')->makeDirectory(dirname($path));
        $absolute = Storage::disk('local')->path($path);
        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('The private export archive could not be created.');
        }
        $manifest = ['export_id' => $export->id, 'patient_reference' => $patient->reference, 'generated_at' => now()->toISOString(), 'documents' => []];
        try {
            foreach ($documents as $index => $document) {
                abort_unless(filled($document->storage_path) && Storage::disk('local')->exists($document->storage_path), 409, 'A selected file is unavailable.');
                $source = Storage::disk('local')->path($document->storage_path);
                abort_unless(hash_equals($document->sha256, hash_file('sha256', $source)), 409, 'A selected file failed its integrity check.');
                $name = sprintf('%02d-%s', $index + 1, preg_replace('/[^A-Za-z0-9._-]/', '_', basename($document->original_name)));
                abort_unless($zip->addFile($source, $name), 500, 'A selected file could not be added to the export.');
                $manifest['documents'][] = ['id' => $document->id, 'category' => $document->category, 'name' => $name, 'sha256' => $document->sha256];
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            $zip->close();
        }

        return [$path, hash_file('sha256', $absolute)];
    }

    public function downloadExport(Request $request, int $id, int $exportId)
    {
        $patient = $this->patient($request, $id);
        PatientAccess::enforce($request, $patient, 'documents.export');
        $export = DocumentExport::where('patient_id', $patient->id)->findOrFail($exportId);
        abort_unless($export->requested_by === $request->user()->id, 403, 'Only the original requester can download this export.');
        abort_unless($export->status === 'approved' && $export->expires_at?->isFuture() && filled($export->archive_path), 404);
        abort_unless(Storage::disk('local')->exists($export->archive_path), 404);
        abort_unless(hash_equals($export->archive_sha256, hash_file('sha256', Storage::disk('local')->path($export->archive_path))), 409, 'Export integrity check failed.');
        Workspace::audit($request, 'patient.document_export.downloaded', $patient->organisation_id, 'document_exports', $export->id);

        return Storage::disk('local')->download($export->archive_path, 'mcare-'.$patient->reference.'-export-'.$export->id.'.zip', ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
