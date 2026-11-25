import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Person = { id: number; name: string };
type Investigation = {
    id: number;
    requested_at: string;
    category: string;
    details: { name: string; clinical_question: string; destination: string; source_reference: string };
    requester: Person;
};
type Result = {
    id: number;
    investigation_id: number;
    owner_id: number;
    effective_owner_id: number;
    backup_id: number;
    supersedes_id: number | null;
    received_at: string;
    source_reported_at: string | null;
    source_flag: string;
    details: {
        result_type: string;
        source_reference: string;
        summary: string;
        source_comment?: string;
        values: { label: string; value: string; unit?: string }[];
        escalated_at?: string;
        escalation_channel?: string;
        escalation_evidence?: string;
    };
    correction_reason?: string;
    status: string;
    acknowledgement?: {
        review_summary: string;
        patient_communication: string;
        communication_evidence: string;
        follow_up_required: string;
        follow_up_plan?: string;
        follow_up_due?: string;
    };
    acknowledged_at?: string;
    completion?: { outcome: string; evidence: string };
    completed_at?: string;
    version: number;
    investigation?: { id: number; category: string; name: string };
    receiver: Person;
    owner: Person;
    effective_owner: Person;
    backup: Person;
    published_at?: string;
};

const localNow = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
const format = (value: string) =>
    new Intl.DateTimeFormat('en-MU', { timeZone: 'Indian/Mauritius', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

function Signature({
    data,
    setData,
    errors,
    text,
}: {
    data: { password: string; declaration: boolean };
    setData: (field: 'password' | 'declaration', value: string | boolean) => void;
    errors: Record<string, string | undefined>;
    text: string;
}) {
    return (
        <div className="border-border space-y-3 border-t pt-4">
            <div className="max-w-md">
                <Label>Confirm your password</Label>
                <Input className="mt-1" type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} required />
                <InputError message={errors.password} />
            </div>
            <label className="flex min-h-11 items-start gap-3 text-sm">
                <input
                    className="mt-1"
                    type="checkbox"
                    checked={data.declaration}
                    onChange={(e) => setData('declaration', e.target.checked)}
                    required
                />
                <span>{text}</span>
            </label>
            <InputError message={errors.declaration} />
        </div>
    );
}

function ResultActions({
    patientId,
    row,
    canAcknowledge,
    canPublish,
    currentUserId,
    superseded,
}: {
    patientId: number;
    row: Result;
    canAcknowledge: boolean;
    canPublish: boolean;
    currentUserId: number;
    superseded: boolean;
}) {
    const acknowledgement = useForm({
        version: row.version,
        review_summary: '',
        patient_communication: 'pending',
        communication_evidence: '',
        follow_up_required: 'yes',
        follow_up_plan: '',
        follow_up_due: '',
        password: '',
        declaration: false,
    });
    const completion = useForm({ version: row.version, outcome: '', evidence: '', password: '', declaration: false });
    const publication = useForm({ request_id: crypto.randomUUID(), summary: '', next_steps: '', password: '', declaration: false });
    const submitAcknowledgement = (event: FormEvent) => {
        event.preventDefault();
        acknowledgement.post(`/patient-records/${patientId}/investigations/results/${row.id}/acknowledge`, { preserveScroll: true });
    };
    const submitCompletion = (event: FormEvent) => {
        event.preventDefault();
        completion.post(`/patient-records/${patientId}/investigations/results/${row.id}/complete`, { preserveScroll: true });
    };
    const submitPublication = (event: FormEvent) => {
        event.preventDefault();
        publication.post(`/patient-records/${patientId}/investigations/results/${row.id}/publish`, { preserveScroll: true });
    };
    const owner = row.effective_owner_id === currentUserId;
    return (
        <div className="space-y-4">
            {canAcknowledge && owner && row.status === 'pending' && !superseded && (
                <form className="glass-subtle space-y-4 rounded-xl p-4" onSubmit={submitAcknowledgement}>
                    <h4 className="font-medium">Owner acknowledgement</h4>
                    <div>
                        <Label>Clinical review summary</Label>
                        <textarea
                            className="glass-input mt-1 min-h-24 w-full"
                            value={acknowledgement.data.review_summary}
                            onChange={(e) => acknowledgement.setData('review_summary', e.target.value)}
                            required
                        />
                        <InputError message={acknowledgement.errors.review_summary} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label>Patient communication</Label>
                            <select
                                className="glass-input mt-1 min-h-11 w-full"
                                value={acknowledgement.data.patient_communication}
                                onChange={(e) => acknowledgement.setData('patient_communication', e.target.value)}
                            >
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                                <option value="not_required">Not required</option>
                            </select>
                        </div>
                        <div>
                            <Label>Communication evidence / rationale</Label>
                            <Input
                                className="mt-1"
                                value={acknowledgement.data.communication_evidence}
                                onChange={(e) => acknowledgement.setData('communication_evidence', e.target.value)}
                                required
                            />
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <Label>Further follow-up</Label>
                            <select
                                className="glass-input mt-1 min-h-11 w-full"
                                value={acknowledgement.data.follow_up_required}
                                onChange={(e) => acknowledgement.setData('follow_up_required', e.target.value)}
                            >
                                <option value="yes">Required</option>
                                <option value="no">Not required</option>
                            </select>
                        </div>
                        <div>
                            <Label>Follow-up plan</Label>
                            <Input
                                className="mt-1"
                                value={acknowledgement.data.follow_up_plan}
                                onChange={(e) => acknowledgement.setData('follow_up_plan', e.target.value)}
                                required={acknowledgement.data.follow_up_required === 'yes'}
                            />
                            <InputError message={acknowledgement.errors.follow_up_plan} />
                        </div>
                        <div>
                            <Label>Follow-up due</Label>
                            <Input
                                className="mt-1"
                                type="datetime-local"
                                value={acknowledgement.data.follow_up_due}
                                onChange={(e) => acknowledgement.setData('follow_up_due', e.target.value)}
                                required={acknowledgement.data.follow_up_required === 'yes'}
                            />
                        </div>
                    </div>
                    <Signature
                        data={acknowledgement.data}
                        setData={(field, value) => acknowledgement.setData(field, value as never)}
                        errors={acknowledgement.errors as Record<string, string | undefined>}
                        text="I am the named clinical owner and have reviewed this source result and recorded the accountable next step."
                    />
                    <Button disabled={acknowledgement.processing}>Acknowledge result</Button>
                </form>
            )}
            {canAcknowledge && owner && row.status === 'acknowledged' && !superseded && row.acknowledgement?.follow_up_required === 'yes' && (
                <form className="glass-subtle space-y-4 rounded-xl p-4" onSubmit={submitCompletion}>
                    <h4 className="font-medium">Complete follow-up</h4>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label>Outcome</Label>
                            <textarea
                                className="glass-input mt-1 min-h-20 w-full"
                                value={completion.data.outcome}
                                onChange={(e) => completion.setData('outcome', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label>Completion evidence</Label>
                            <textarea
                                className="glass-input mt-1 min-h-20 w-full"
                                value={completion.data.evidence}
                                onChange={(e) => completion.setData('evidence', e.target.value)}
                                required
                            />
                        </div>
                    </div>
                    <Signature
                        data={completion.data}
                        setData={(field, value) => completion.setData(field, value as never)}
                        errors={completion.errors as Record<string, string | undefined>}
                        text="I confirm the recorded follow-up was completed and the evidence is accurate."
                    />
                    <Button disabled={completion.processing}>Complete follow-up</Button>
                </form>
            )}
            {canPublish && ['acknowledged', 'completed'].includes(row.status) && !superseded && !row.published_at && (
                <form className="glass-subtle space-y-4 rounded-xl p-4" onSubmit={submitPublication}>
                    <h4 className="font-medium">Publish a patient-safe summary</h4>
                    <p className="text-muted-foreground text-sm">
                        Write a reviewed plain-language snapshot. Raw source values and private review content are never copied automatically.
                    </p>
                    <div>
                        <Label>Plain-language summary</Label>
                        <textarea
                            className="glass-input mt-1 min-h-24 w-full"
                            value={publication.data.summary}
                            onChange={(e) => publication.setData('summary', e.target.value)}
                            required
                        />
                    </div>
                    <div>
                        <Label>Next steps for the patient</Label>
                        <textarea
                            className="glass-input mt-1 min-h-20 w-full"
                            value={publication.data.next_steps}
                            onChange={(e) => publication.setData('next_steps', e.target.value)}
                            required
                        />
                    </div>
                    <Signature
                        data={publication.data}
                        setData={(field, value) => publication.setData(field, value as never)}
                        errors={publication.errors as Record<string, string | undefined>}
                        text="I reviewed this plain-language publication against the acknowledged source result and confirm it is appropriate for the patient portal."
                    />
                    <Button disabled={publication.processing}>Publish reviewed summary</Button>
                </form>
            )}
        </div>
    );
}

export default function Investigations({
    patient,
    investigations,
    results,
    canRequest,
    canReadResults,
    canReceive,
    canAcknowledge,
    canPublish,
    owners,
    backups,
    currentUserId,
}: {
    patient: { id: number; name: string; reference: string };
    investigations: Investigation[];
    results: Result[];
    canRequest: boolean;
    canReadResults: boolean;
    canReceive: boolean;
    canAcknowledge: boolean;
    canPublish: boolean;
    owners: Person[];
    backups: Person[];
    currentUserId: number;
}) {
    const corrected = new Set(results.map((row) => row.supersedes_id).filter(Boolean));
    const requestForm = useForm({
        request_id: crypto.randomUUID(),
        requested_at: localNow(),
        category: 'laboratory',
        name: '',
        clinical_question: '',
        destination: '',
        source_reference: '',
        password: '',
        declaration: false,
    });
    const resultForm = useForm({
        request_id: crypto.randomUUID(),
        investigation_id: '',
        received_at: localNow(),
        source_reported_at: '',
        source_flag: 'routine',
        result_type: 'final',
        source_reference: '',
        summary: '',
        source_comment: '',
        values: [] as { label: string; value: string; unit: string }[],
        owner_id: '',
        backup_id: '',
        due_at: '',
        escalated_at: '',
        escalation_channel: '',
        escalation_evidence: '',
        supersedes_id: '',
        correction_reason: '',
        password: '',
        declaration: false,
    });
    const resultErrors = resultForm.errors as Record<string, string | undefined>;
    const submitRequest = (event: FormEvent) => {
        event.preventDefault();
        requestForm.post(`/patient-records/${patient.id}/investigations`, {
            preserveScroll: true,
            onSuccess: () => {
                requestForm.reset();
                requestForm.setData('request_id', crypto.randomUUID());
                requestForm.setData('requested_at', localNow());
            },
        });
    };
    const submitResult = (event: FormEvent) => {
        event.preventDefault();
        resultForm.post(`/patient-records/${patient.id}/investigations/results`, {
            preserveScroll: true,
            onSuccess: () => {
                resultForm.reset();
                resultForm.setData('request_id', crypto.randomUUID());
                resultForm.setData('received_at', localNow());
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patient record', href: `/patient-records/${patient.id}` },
                { title: 'Investigations', href: `/patient-records/${patient.id}/investigations` },
            ]}
        >
            <Head title={`${patient.name} investigations`} />
            <main className="mx-auto w-full max-w-7xl min-w-0 space-y-6 p-3 sm:p-6">
                <header className="surface space-y-3 p-4 sm:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-muted-foreground text-xs">Accountable result workflow</p>
                            <h1 className="text-2xl font-semibold break-words">{patient.name}: investigations and results</h1>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={`/patient-records/${patient.id}`}>Patient record</Link>
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Records external requests and source results; it does not transmit orders, integrate with a laboratory, interpret values, or
                        infer urgency. “Critical” means the external source explicitly flagged it and real-world escalation evidence was recorded.
                    </p>
                </header>

                {canRequest && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Record an external investigation request</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-5" onSubmit={submitRequest}>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Request time (Mauritius)</Label>
                                        <Input
                                            className="mt-1"
                                            type="datetime-local"
                                            value={requestForm.data.requested_at}
                                            onChange={(e) => requestForm.setData('requested_at', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <select
                                            className="glass-input mt-1 min-h-11 w-full"
                                            value={requestForm.data.category}
                                            onChange={(e) => requestForm.setData('category', e.target.value)}
                                        >
                                            <option value="laboratory">Laboratory</option>
                                            <option value="imaging">Imaging</option>
                                            <option value="pathology">Pathology</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Investigation name</Label>
                                        <Input
                                            className="mt-1"
                                            value={requestForm.data.name}
                                            onChange={(e) => requestForm.setData('name', e.target.value)}
                                            required
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Clinical question / reason</Label>
                                    <textarea
                                        className="glass-input mt-1 min-h-24 w-full"
                                        value={requestForm.data.clinical_question}
                                        onChange={(e) => requestForm.setData('clinical_question', e.target.value)}
                                        required
                                    />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>External destination</Label>
                                        <Input
                                            className="mt-1"
                                            value={requestForm.data.destination}
                                            onChange={(e) => requestForm.setData('destination', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>External request reference</Label>
                                        <Input
                                            className="mt-1"
                                            value={requestForm.data.source_reference}
                                            onChange={(e) => requestForm.setData('source_reference', e.target.value)}
                                            required
                                        />
                                    </div>
                                </div>
                                <Signature
                                    data={requestForm.data}
                                    setData={(field, value) => requestForm.setData(field, value as never)}
                                    errors={requestForm.errors as Record<string, string | undefined>}
                                    text="I personally verified this external request record. Saving it does not transmit an order to any provider."
                                />
                                <Button disabled={requestForm.processing}>Sign request record</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {canReceive && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Receive and assign a source result</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {investigations.length === 0 || owners.length === 0 || backups.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    A recorded investigation, eligible doctor owner and operational backup are required.
                                </p>
                            ) : (
                                <form className="space-y-5" onSubmit={submitResult}>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <Label>Investigation</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={resultForm.data.investigation_id}
                                                onChange={(e) => resultForm.setData('investigation_id', e.target.value)}
                                                required
                                            >
                                                <option value="">Choose investigation</option>
                                                {investigations.map((row) => (
                                                    <option key={row.id} value={row.id}>
                                                        #{row.id} · {row.details.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <Label>Received time</Label>
                                            <Input
                                                className="mt-1"
                                                type="datetime-local"
                                                value={resultForm.data.received_at}
                                                onChange={(e) => resultForm.setData('received_at', e.target.value)}
                                                required
                                            />
                                        </div>
                                        <div>
                                            <Label>Source report time</Label>
                                            <Input
                                                className="mt-1"
                                                type="datetime-local"
                                                value={resultForm.data.source_reported_at}
                                                onChange={(e) => resultForm.setData('source_reported_at', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label>Source result type</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={resultForm.data.result_type}
                                                onChange={(e) => resultForm.setData('result_type', e.target.value)}
                                            >
                                                <option value="preliminary">Preliminary</option>
                                                <option value="final">Final</option>
                                                <option value="corrected">Corrected</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div>
                                            <Label>Source urgency flag</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={resultForm.data.source_flag}
                                                onChange={(e) => resultForm.setData('source_flag', e.target.value)}
                                            >
                                                <option value="routine">Routine</option>
                                                <option value="urgent">Urgent</option>
                                                <option value="critical">Critical — explicitly source flagged</option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label>Source result reference</Label>
                                            <Input
                                                className="mt-1"
                                                value={resultForm.data.source_reference}
                                                onChange={(e) => resultForm.setData('source_reference', e.target.value)}
                                                required
                                            />
                                        </div>
                                        <div>
                                            <Label>Accountable due time</Label>
                                            <Input
                                                className="mt-1"
                                                type="datetime-local"
                                                value={resultForm.data.due_at}
                                                onChange={(e) => resultForm.setData('due_at', e.target.value)}
                                                required
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Source result summary (transcribed, no app interpretation)</Label>
                                        <textarea
                                            className="glass-input mt-1 min-h-28 w-full"
                                            value={resultForm.data.summary}
                                            onChange={(e) => resultForm.setData('summary', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Source comment</Label>
                                        <textarea
                                            className="glass-input mt-1 min-h-20 w-full"
                                            value={resultForm.data.source_comment}
                                            onChange={(e) => resultForm.setData('source_comment', e.target.value)}
                                        />
                                    </div>
                                    <fieldset className="space-y-3">
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <legend className="font-medium">Values exactly as supplied by the source (optional)</legend>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    resultForm.setData('values', [...resultForm.data.values, { label: '', value: '', unit: '' }])
                                                }
                                            >
                                                Add value
                                            </Button>
                                        </div>
                                        {resultForm.data.values.map((value, index) => (
                                            <div className="glass-subtle grid gap-3 rounded-xl p-3 sm:grid-cols-[1fr_1fr_0.6fr_auto]" key={index}>
                                                {(['label', 'value', 'unit'] as const).map((field) => (
                                                    <div key={field}>
                                                        <Label>{title(field)}</Label>
                                                        <Input
                                                            className="mt-1"
                                                            value={value[field]}
                                                            onChange={(e) =>
                                                                resultForm.setData(
                                                                    'values',
                                                                    resultForm.data.values.map((item, i) =>
                                                                        i === index ? { ...item, [field]: e.target.value } : item,
                                                                    ),
                                                                )
                                                            }
                                                            required={field !== 'unit'}
                                                        />
                                                        <InputError message={resultErrors[`values.${index}.${field}`]} />
                                                    </div>
                                                ))}
                                                <Button
                                                    className="self-end"
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        resultForm.setData(
                                                            'values',
                                                            resultForm.data.values.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        ))}
                                    </fieldset>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div>
                                            <Label>Named doctor owner</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={resultForm.data.owner_id}
                                                onChange={(e) => resultForm.setData('owner_id', e.target.value)}
                                                required
                                            >
                                                <option value="">Choose owner</option>
                                                {owners.map((owner) => (
                                                    <option key={owner.id} value={owner.id}>
                                                        {owner.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={resultForm.errors.owner_id} />
                                        </div>
                                        <div>
                                            <Label>Operational backup</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={resultForm.data.backup_id}
                                                onChange={(e) => resultForm.setData('backup_id', e.target.value)}
                                                required
                                            >
                                                <option value="">Choose backup</option>
                                                {backups.map((backup) => (
                                                    <option key={backup.id} value={backup.id}>
                                                        {backup.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={resultForm.errors.backup_id} />
                                        </div>
                                        <div>
                                            <Label>Correction of result</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={resultForm.data.supersedes_id}
                                                onChange={(e) => resultForm.setData('supersedes_id', e.target.value)}
                                            >
                                                <option value="">Not a correction</option>
                                                {results
                                                    .filter((row) => !corrected.has(row.id))
                                                    .map((row) => (
                                                        <option key={row.id} value={row.id}>
                                                            Result #{row.id} · {row.investigation?.name}
                                                        </option>
                                                    ))}
                                            </select>
                                        </div>
                                    </div>
                                    {resultForm.data.supersedes_id && (
                                        <div>
                                            <Label>Correction reason</Label>
                                            <Input
                                                className="mt-1"
                                                value={resultForm.data.correction_reason}
                                                onChange={(e) => resultForm.setData('correction_reason', e.target.value)}
                                                required
                                            />
                                            <InputError message={resultForm.errors.correction_reason} />
                                        </div>
                                    )}
                                    {resultForm.data.source_flag === 'critical' && (
                                        <div className="border-destructive/50 bg-destructive/10 space-y-4 rounded-xl border p-4">
                                            <p className="text-sm font-medium">
                                                Do not use this form to initiate emergency escalation. Record only after the source-flagged critical
                                                result was escalated through the real approved channel.
                                            </p>
                                            <div className="grid gap-4 sm:grid-cols-3">
                                                <div>
                                                    <Label>Actual escalation time</Label>
                                                    <Input
                                                        className="mt-1"
                                                        type="datetime-local"
                                                        value={resultForm.data.escalated_at}
                                                        onChange={(e) => resultForm.setData('escalated_at', e.target.value)}
                                                        required
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Escalation channel</Label>
                                                    <Input
                                                        className="mt-1"
                                                        value={resultForm.data.escalation_channel}
                                                        onChange={(e) => resultForm.setData('escalation_channel', e.target.value)}
                                                        required
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Escalation evidence</Label>
                                                    <Input
                                                        className="mt-1"
                                                        value={resultForm.data.escalation_evidence}
                                                        onChange={(e) => resultForm.setData('escalation_evidence', e.target.value)}
                                                        required
                                                    />
                                                    <InputError message={resultForm.errors.escalation_evidence} />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    <Signature
                                        data={resultForm.data}
                                        setData={(field, value) => resultForm.setData(field, value as never)}
                                        errors={resultErrors}
                                        text="I transcribed this result from the identified source, assigned accountable ownership, and did not add an inferred flag, range, score or interpretation."
                                    />
                                    <Button disabled={resultForm.processing}>Sign and assign result</Button>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}

                <section className="space-y-3" aria-labelledby="result-history">
                    <h2 id="result-history" className="text-xl font-semibold">
                        Result ownership and follow-up
                    </h2>
                    {!canReadResults ? (
                        <p className="text-muted-foreground">Your authority does not include result contents.</p>
                    ) : results.length === 0 ? (
                        <p className="text-muted-foreground">No source results recorded.</p>
                    ) : (
                        results.map((row) => {
                            const superseded = corrected.has(row.id);
                            return (
                                <article className={`surface space-y-4 p-4 sm:p-6 ${superseded ? 'opacity-70' : ''}`} key={row.id}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 className="font-semibold break-words">
                                                {row.investigation?.name} · Result #{row.id}
                                            </h3>
                                            <p className="text-muted-foreground text-sm">
                                                Received {format(row.received_at)} by {row.receiver?.name}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Badge variant={row.source_flag === 'critical' ? 'destructive' : 'secondary'}>
                                                Source: {title(row.source_flag)}
                                            </Badge>
                                            <Badge variant={row.status === 'completed' ? 'success' : 'secondary'}>
                                                {superseded ? 'Superseded' : title(row.status)}
                                            </Badge>
                                            {row.published_at && <Badge variant="secondary">Published</Badge>}
                                        </div>
                                    </div>
                                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt className="text-muted-foreground text-xs">Current follow-up owner</dt>
                                            <dd className="break-words">{row.effective_owner?.name}</dd>
                                        </div>
                                        {row.effective_owner_id !== row.owner_id && (
                                            <div>
                                                <dt className="text-muted-foreground text-xs">Owner at signing</dt>
                                                <dd className="break-words">{row.owner?.name}</dd>
                                            </div>
                                        )}
                                        <div>
                                            <dt className="text-muted-foreground text-xs">Operational backup</dt>
                                            <dd>{row.backup?.name}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground text-xs">Source reference</dt>
                                            <dd className="break-words">{row.details.source_reference}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground text-xs">Result type</dt>
                                            <dd>{title(row.details.result_type)}</dd>
                                        </div>
                                    </dl>
                                    <div>
                                        <h4 className="font-medium">Source summary</h4>
                                        <p className="mt-1 text-sm break-words whitespace-pre-wrap">{row.details.summary}</p>
                                    </div>
                                    {row.details.values.length > 0 && (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[480px] text-left text-sm">
                                                <thead>
                                                    <tr className="border-b">
                                                        <th className="p-2">Source label</th>
                                                        <th className="p-2">Source value</th>
                                                        <th className="p-2">Unit</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {row.details.values.map((value, index) => (
                                                        <tr className="border-b" key={index}>
                                                            <td className="p-2 break-words">{value.label}</td>
                                                            <td className="p-2 break-words">{value.value}</td>
                                                            <td className="p-2 break-words">{value.unit || '—'}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                    {row.details.escalation_evidence && (
                                        <div className="border-destructive/40 rounded-xl border p-3 text-sm">
                                            <p className="font-medium">External escalation already completed</p>
                                            <p>
                                                {row.details.escalated_at && format(row.details.escalated_at)} · {row.details.escalation_channel}
                                            </p>
                                            <p className="break-words">{row.details.escalation_evidence}</p>
                                        </div>
                                    )}
                                    {row.acknowledgement && (
                                        <div className="glass-subtle rounded-xl p-3 text-sm">
                                            <h4 className="font-medium">Owner acknowledgement</h4>
                                            <p className="mt-1 whitespace-pre-wrap">{row.acknowledgement.review_summary}</p>
                                            <p className="mt-2">
                                                Patient communication: {title(row.acknowledgement.patient_communication)} ·{' '}
                                                {row.acknowledgement.communication_evidence}
                                            </p>
                                            {row.acknowledgement.follow_up_required === 'yes' && (
                                                <p className="mt-2">
                                                    Follow-up: {row.acknowledgement.follow_up_plan} · due{' '}
                                                    {row.acknowledgement.follow_up_due && format(row.acknowledgement.follow_up_due)}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    {row.completion && (
                                        <div className="glass-subtle rounded-xl p-3 text-sm">
                                            <h4 className="font-medium">Completed follow-up</h4>
                                            <p>{row.completion.outcome}</p>
                                            <p className="text-muted-foreground">Evidence: {row.completion.evidence}</p>
                                        </div>
                                    )}
                                    <ResultActions
                                        patientId={patient.id}
                                        row={row}
                                        canAcknowledge={canAcknowledge}
                                        canPublish={canPublish}
                                        currentUserId={currentUserId}
                                        superseded={superseded}
                                    />
                                </article>
                            );
                        })
                    )}
                </section>
                <section className="space-y-3" aria-labelledby="request-history">
                    <h2 id="request-history" className="text-xl font-semibold">
                        Recorded external requests
                    </h2>
                    {investigations.length === 0 ? (
                        <p className="text-muted-foreground">No investigation requests recorded.</p>
                    ) : (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {investigations.map((row) => (
                                <article className="surface space-y-2 p-4" key={row.id}>
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <h3 className="font-semibold break-words">{row.details.name}</h3>
                                        <Badge variant="secondary">{title(row.category)}</Badge>
                                    </div>
                                    <p className="text-sm break-words whitespace-pre-wrap">{row.details.clinical_question}</p>
                                    <p className="text-muted-foreground text-xs">
                                        {format(row.requested_at)} · {row.requester?.name} · destination {row.details.destination} · ref{' '}
                                        {row.details.source_reference}
                                    </p>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </main>
        </AppLayout>
    );
}
