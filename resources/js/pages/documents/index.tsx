import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Person = { id: number; name: string } | null;
type Document = {
    id: number;
    category: string;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    status: string;
    description: string | null;
    review_evidence: string | null;
    reviewed_at: string | null;
    version: number;
    created_at: string;
    uploader: Person;
    reviewer: Person;
    downloadable: boolean;
};
type ExportRecord = {
    id: number;
    document_ids: number[];
    purpose: string;
    status: string;
    review_evidence: string | null;
    reviewed_at: string | null;
    expires_at: string | null;
    version: number;
    created_at: string;
    requester: Person;
    reviewer: Person;
    downloadable: boolean;
};
const title = (value: string) =>
    value
        .replaceAll('.', ' / ')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
const when = (value: string | null) => (value ? new Date(value).toLocaleString('en-MU', { timeZone: 'Indian/Mauritius' }) : 'Not recorded');
const errors = (values: Record<string, string>) =>
    Object.entries(values).length > 0 ? (
        <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-xl border p-3 text-sm" role="alert">
            <p className="font-medium">Please correct the highlighted submission.</p>
            <ul className="mt-1 list-disc pl-5">
                {Object.entries(values).map(([key, message]) => (
                    <li key={key}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;

function DocumentReview({ patientId, document }: { patientId: number; document: Document }) {
    const form = useForm({ version: document.version, decision: 'release', review_evidence: '', password: '', declaration: false as boolean });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/patient-records/${patientId}/documents/${document.id}/review`, {
            preserveScroll: true,
            onSuccess: () => form.reset('review_evidence', 'password', 'declaration'),
        });
    };
    return (
        <form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border p-3">
            <p className="text-sm font-medium">Independent quarantine decision</p>
            <p className="text-muted-foreground text-xs">
                MauriCare does not scan this file itself. Record evidence from the external scanner or review process you actually completed.
            </p>
            <select
                className="w-full"
                aria-label="Review decision"
                value={form.data.decision}
                onChange={(event) => form.setData('decision', event.target.value)}
            >
                <option value="release">Release after clean external review</option>
                <option value="reject">Reject and retain in quarantine</option>
            </select>
            <textarea
                className="w-full"
                rows={3}
                required
                maxLength={3000}
                placeholder="Scanner/tool, timestamp, result and evidence reference *"
                value={form.data.review_evidence}
                onChange={(event) => form.setData('review_evidence', event.target.value)}
            />
            <input
                className="w-full"
                type="password"
                required
                autoComplete="current-password"
                placeholder="Your password *"
                value={form.data.password}
                onChange={(event) => form.setData('password', event.target.value)}
            />
            <label className="flex items-start gap-2 text-sm">
                <input
                    type="checkbox"
                    className="mt-1"
                    checked={form.data.declaration}
                    onChange={(event) => form.setData('declaration', event.target.checked)}
                />{' '}
                I attest that I independently reviewed the exact checksum shown and that this evidence is accurate.
            </label>
            {errors(form.errors)}
            <Button disabled={form.processing}>{form.processing ? 'Recording…' : 'Record decision'}</Button>
        </form>
    );
}

function ExportReview({ patientId, record }: { patientId: number; record: ExportRecord }) {
    const form = useForm({
        version: record.version,
        decision: 'approve',
        review_evidence: '',
        expires_at: '',
        password: '',
        declaration: false as boolean,
    });
    return (
        <form
            className="mt-4 space-y-3 rounded-xl border p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/patient-records/${patientId}/document-exports/${record.id}/review`, { preserveScroll: true });
            }}
        >
            <p className="text-sm font-medium">Independent export review</p>
            <select
                className="w-full"
                aria-label="Export decision"
                value={form.data.decision}
                onChange={(event) => form.setData('decision', event.target.value)}
            >
                <option value="approve">Approve exact file set</option>
                <option value="reject">Reject request</option>
            </select>
            {form.data.decision === 'approve' && (
                <>
                    <label className="text-sm" htmlFor={`expiry-${record.id}`}>
                        Download expires (maximum 7 days) *
                    </label>
                    <input
                        id={`expiry-${record.id}`}
                        className="w-full"
                        type="datetime-local"
                        required
                        value={form.data.expires_at}
                        onChange={(event) => form.setData('expires_at', event.target.value)}
                    />
                </>
            )}
            <textarea
                className="w-full"
                rows={3}
                required
                maxLength={3000}
                placeholder="Approval basis, minimum necessary review and recipient controls *"
                value={form.data.review_evidence}
                onChange={(event) => form.setData('review_evidence', event.target.value)}
            />
            <input
                className="w-full"
                type="password"
                required
                autoComplete="current-password"
                placeholder="Your password *"
                value={form.data.password}
                onChange={(event) => form.setData('password', event.target.value)}
            />
            <label className="flex items-start gap-2 text-sm">
                <input
                    type="checkbox"
                    className="mt-1"
                    checked={form.data.declaration}
                    onChange={(event) => form.setData('declaration', event.target.checked)}
                />{' '}
                I reviewed the purpose, exact released files and minimum necessary disclosure.
            </label>
            {errors(form.errors)}
            <Button disabled={form.processing}>{form.processing ? 'Recording…' : 'Record export decision'}</Button>
        </form>
    );
}

export default function DocumentVault({
    patient,
    documents,
    exports: exportRecords,
    categories,
    canUpload,
    canReview,
    canExport,
    currentUserId,
}: {
    patient: { id: number; name: string; reference: string };
    documents: Document[];
    exports: ExportRecord[];
    categories: string[];
    canUpload: boolean;
    canReview: boolean;
    canExport: boolean;
    currentUserId: number;
}) {
    const upload = useForm<{ request_id: string; category: string; description: string; file: File | null; password: string; declaration: boolean }>({
        request_id: crypto.randomUUID(),
        category: categories[0] ?? 'clinical',
        description: '',
        file: null,
        password: '',
        declaration: false,
    });
    const request = useForm<{ request_id: string; document_ids: number[]; purpose: string; password: string; declaration: boolean }>({
        request_id: crypto.randomUUID(),
        document_ids: [],
        purpose: '',
        password: '',
        declaration: false,
    });
    const [section, setSection] = useState<'vault' | 'exports'>('vault');
    const released = documents.filter((document) => document.status === 'released');
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/workspace/patients' },
                { title: patient.name, href: `/patient-records/${patient.id}` },
                { title: 'Private documents', href: `/patient-records/${patient.id}/documents` },
            ]}
        >
            <Head title={`Private documents · ${patient.name}`} />
            <main className="mx-auto w-full max-w-6xl min-w-0 space-y-5 p-3 sm:p-6">
                <Link className="text-primary text-sm" href={`/patient-records/${patient.id}`}>
                    Back to patient record
                </Link>
                <header className="surface space-y-2 p-4 sm:p-6">
                    <p className="text-muted-foreground text-xs">Private document vault</p>
                    <h1 className="text-2xl font-semibold break-words">{patient.name}</h1>
                    <p className="text-muted-foreground font-mono text-xs break-all">{patient.reference}</p>
                    <p className="text-muted-foreground text-sm">
                        Files are private, checksum-verified and never exposed through public storage. Quarantine is a workflow state, not a claim
                        that MauriCare performed malware scanning.
                    </p>
                </header>
                <nav className="flex flex-wrap gap-2" aria-label="Vault sections">
                    <Button variant={section === 'vault' ? 'default' : 'outline'} onClick={() => setSection('vault')}>
                        Vault
                    </Button>
                    <Button variant={section === 'exports' ? 'default' : 'outline'} onClick={() => setSection('exports')}>
                        Reviewed exports
                    </Button>
                </nav>
                {section === 'vault' && (
                    <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                        <section className="surface min-w-0 p-4 sm:p-6">
                            <h2 className="text-lg font-semibold">Category-scoped files</h2>
                            <div className="mt-4 space-y-4">
                                {documents.length === 0 && (
                                    <p className="text-muted-foreground text-sm">No documents are visible in your authorised categories.</p>
                                )}
                                {documents.map((document) => (
                                    <article className="min-w-0 rounded-xl border p-4" key={document.id}>
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <h3 className="font-medium break-all">{document.original_name}</h3>
                                                <p className="text-muted-foreground text-xs">
                                                    {title(document.category)} · {(document.size_bytes / 1024).toFixed(1)} KB ·{' '}
                                                    {when(document.created_at)}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    document.status === 'released'
                                                        ? 'secondary'
                                                        : document.status === 'rejected'
                                                          ? 'warning'
                                                          : 'outline'
                                                }
                                            >
                                                {title(document.status)}
                                            </Badge>
                                        </div>
                                        {document.description && <p className="mt-2 text-sm break-words">{document.description}</p>}
                                        <p className="text-muted-foreground mt-2 font-mono text-[11px] break-all">SHA-256 {document.sha256}</p>
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            Uploaded by {document.uploader?.name ?? 'Unknown'}
                                            {document.reviewer ? ` · reviewed by ${document.reviewer.name} at ${when(document.reviewed_at)}` : ''}
                                        </p>
                                        {document.review_evidence && (
                                            <p className="mt-2 text-xs break-words">Review evidence: {document.review_evidence}</p>
                                        )}{' '}
                                        {document.downloadable && (
                                            <Button asChild variant="outline" className="mt-3">
                                                <a href={`/patient-records/${patient.id}/documents/${document.id}/download`}>
                                                    Download verified file
                                                </a>
                                            </Button>
                                        )}
                                        {canReview && document.status === 'quarantined' && document.uploader?.id !== currentUserId && (
                                            <DocumentReview patientId={patient.id} document={document} />
                                        )}
                                    </article>
                                ))}
                            </div>
                        </section>
                        {canUpload && (
                            <form
                                className="surface space-y-4 p-4 sm:p-6"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    upload.post(`/patient-records/${patient.id}/documents`, {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            upload.setData({
                                                request_id: crypto.randomUUID(),
                                                category: categories[0] ?? 'clinical',
                                                description: '',
                                                file: null,
                                                password: '',
                                                declaration: false,
                                            }),
                                    });
                                }}
                            >
                                <h2 className="text-lg font-semibold">Upload to quarantine</h2>
                                <label className="text-sm" htmlFor="document-category">
                                    Category *
                                </label>
                                <select
                                    id="document-category"
                                    className="w-full"
                                    value={upload.data.category}
                                    onChange={(event) => upload.setData('category', event.target.value)}
                                >
                                    {categories.map((category) => (
                                        <option value={category} key={category}>
                                            {title(category)}
                                        </option>
                                    ))}
                                </select>
                                <label className="text-sm" htmlFor="document-file">
                                    PDF, JPEG or PNG (maximum 10 MB) *
                                </label>
                                <input
                                    id="document-file"
                                    className="w-full text-sm"
                                    type="file"
                                    required
                                    accept="application/pdf,image/jpeg,image/png"
                                    onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)}
                                />
                                <textarea
                                    className="w-full"
                                    rows={3}
                                    maxLength={2000}
                                    placeholder="Document description"
                                    value={upload.data.description}
                                    onChange={(event) => upload.setData('description', event.target.value)}
                                />
                                <input
                                    className="w-full"
                                    type="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder="Your password *"
                                    value={upload.data.password}
                                    onChange={(event) => upload.setData('password', event.target.value)}
                                />
                                <label className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={upload.data.declaration}
                                        onChange={(event) => upload.setData('declaration', event.target.checked)}
                                    />{' '}
                                    I confirm the category, authority and minimum necessary purpose are accurate.
                                </label>
                                {errors(upload.errors)}
                                <Button disabled={upload.processing}>{upload.processing ? 'Uploading…' : 'Upload privately'}</Button>
                            </form>
                        )}
                    </div>
                )}
                {section === 'exports' && (
                    <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                        <section className="surface min-w-0 p-4 sm:p-6">
                            <h2 className="text-lg font-semibold">Export requests</h2>
                            <div className="mt-4 space-y-4">
                                {exportRecords.length === 0 && <p className="text-muted-foreground text-sm">No export requests are visible.</p>}
                                {exportRecords.map((record) => (
                                    <article className="rounded-xl border p-4" key={record.id}>
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <h3 className="font-medium">Export #{record.id}</h3>
                                            <Badge
                                                variant={
                                                    record.status === 'approved' ? 'secondary' : record.status === 'rejected' ? 'warning' : 'outline'
                                                }
                                            >
                                                {title(record.status)}
                                            </Badge>
                                        </div>
                                        <p className="mt-2 text-sm break-words">{record.purpose}</p>
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            Requested by {record.requester?.name ?? 'Unknown'} · {record.document_ids.length} file(s) ·{' '}
                                            {when(record.created_at)}
                                        </p>
                                        {record.reviewer && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                Reviewed by {record.reviewer.name} · expires {when(record.expires_at)}
                                            </p>
                                        )}
                                        {record.review_evidence && (
                                            <p className="mt-2 text-xs break-words">Review evidence: {record.review_evidence}</p>
                                        )}
                                        {record.downloadable && record.requester?.id === currentUserId && (
                                            <Button asChild variant="outline" className="mt-3">
                                                <a href={`/patient-records/${patient.id}/document-exports/${record.id}/download`}>
                                                    Download integrity-checked ZIP
                                                </a>
                                            </Button>
                                        )}
                                        {canReview && record.status === 'pending' && record.requester?.id !== currentUserId && (
                                            <ExportReview patientId={patient.id} record={record} />
                                        )}
                                    </article>
                                ))}
                            </div>
                        </section>
                        {canExport && (
                            <form
                                className="surface space-y-4 p-4 sm:p-6"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    request.post(`/patient-records/${patient.id}/document-exports`, {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            request.setData({
                                                request_id: crypto.randomUUID(),
                                                document_ids: [],
                                                purpose: '',
                                                password: '',
                                                declaration: false,
                                            }),
                                    });
                                }}
                            >
                                <h2 className="text-lg font-semibold">Request reviewed export</h2>
                                <p className="text-muted-foreground text-sm">
                                    Select only the minimum released files required. A different clinical administrator must approve the exact set.
                                </p>
                                <div className="max-h-64 space-y-2 overflow-y-auto rounded-xl border p-3">
                                    {released.length === 0 && <p className="text-muted-foreground text-sm">No released files are available.</p>}
                                    {released.map((document) => (
                                        <label className="flex min-w-0 items-start gap-2 text-sm" key={document.id}>
                                            <input
                                                className="mt-1"
                                                type="checkbox"
                                                checked={request.data.document_ids.includes(document.id)}
                                                onChange={(event) =>
                                                    request.setData(
                                                        'document_ids',
                                                        event.target.checked
                                                            ? [...request.data.document_ids, document.id]
                                                            : request.data.document_ids.filter((id) => id !== document.id),
                                                    )
                                                }
                                            />
                                            <span className="break-all">
                                                {document.original_name} <span className="text-muted-foreground">({title(document.category)})</span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <textarea
                                    className="w-full"
                                    rows={3}
                                    required
                                    maxLength={2000}
                                    placeholder="Specific recipient and minimum necessary purpose *"
                                    value={request.data.purpose}
                                    onChange={(event) => request.setData('purpose', event.target.value)}
                                />
                                <input
                                    className="w-full"
                                    type="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder="Your password *"
                                    value={request.data.password}
                                    onChange={(event) => request.setData('password', event.target.value)}
                                />
                                <label className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={request.data.declaration}
                                        onChange={(event) => request.setData('declaration', event.target.checked)}
                                    />{' '}
                                    I confirm this export is necessary and limited to the stated purpose.
                                </label>
                                {errors(request.errors)}
                                <Button disabled={request.processing || request.data.document_ids.length === 0}>
                                    {request.processing ? 'Requesting…' : 'Request export review'}
                                </Button>
                            </form>
                        )}
                    </div>
                )}
            </main>
        </AppLayout>
    );
}
