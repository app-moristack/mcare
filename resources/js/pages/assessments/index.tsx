import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import Collections from '@/pages/patients/collections';
import { date, Errors, Field, Fields, Section, title } from '@/pages/patients/fields';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
type Content = Record<string, string | Record<string, string>[]>;
type FormSchema = { sections: Section[]; children: Record<string, Field[]> };
type Assessment = {
    id: number;
    kind: 'basic' | 'nursing';
    status: string;
    version: number;
    schema_version: string;
    previous_id: number | null;
    appointment_id: number | null;
    content?: Content;
    author: { id: number; name: string };
    submitted_at: string | null;
    signed_at: string | null;
    cosigned_at: string | null;
    review_due: string | null;
    content_hash?: string;
    amendment_reason?: string;
    editable: boolean;
    can_sign: boolean;
    can_submit: boolean;
    can_amend: boolean;
    can_cosign: boolean;
    confirmation_method: string | null;
};
function Editor({ record: a, schema, base, own, close }: { record: Assessment; schema: FormSchema; base: string; own: boolean; close: () => void }) {
    const defaults: Content = {
        ...Object.fromEntries(schema.sections.flatMap((s) => s.fields.map((f) => [f.key, '']))),
        ...Object.fromEntries(Object.keys(schema.children).map((k) => [k, []])),
    };
    const f = useForm({
        content: { ...defaults, ...a.content },
        version: a.version,
        action: 'save',
        declaration: false as boolean,
        password: '',
        confirmation_evidence: '',
    });
    const [confirmation, setConfirmation] = useState<'submit' | 'sign' | 'cosign' | null>(null);
    const perform = (action: string) => {
        f.transform((d) => ({ ...d, action }));
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmation(null);
                close();
            },
            onFinish: () => f.setData('password', ''),
        };
        if (action === 'cosign') f.post(`${base}/${a.id}/cosign`, options);
        else f.patch(`${base}/${a.id}`, options);
    };
    return (
        <div className="assessment-record space-y-4">
            <section className="surface space-y-3 p-4 sm:p-6">
                <div className="flex flex-wrap justify-between gap-3">
                    <h2 className="text-xl font-semibold">
                        {title(a.kind)} assessment #{a.id}
                    </h2>
                    <Button type="button" variant="outline" onClick={close}>
                        Back to versions
                    </Button>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary">{title(a.status)}</Badge>
                    <Badge variant="outline">Revision {a.version}</Badge>
                </div>
                <p className="text-muted-foreground text-sm">
                    Recorded by {a.author.name} · Schema {a.schema_version}
                    {a.appointment_id ? ` · Visit #${a.appointment_id}` : ''}
                </p>
                {a.previous_id && (
                    <p className="text-sm">
                        Amends #{a.previous_id}: {a.amendment_reason}
                    </p>
                )}
                {a.signed_at && (
                    <p className="text-sm">
                        Nurse signed {date(a.signed_at)} · Co-signed {date(a.cosigned_at)}
                    </p>
                )}
                {a.submitted_at && (
                    <p className="text-sm">
                        Confirmed and submitted {date(a.submitted_at)} · {title(a.confirmation_method ?? '')}
                    </p>
                )}
                {a.content_hash && <p className="text-muted-foreground font-mono text-[10px] break-all">Content integrity: {a.content_hash}</p>}
                <p className="text-muted-foreground text-sm">
                    Save incomplete drafts at any time. Submission/signing validates required fields. This form does not dispatch emergency help,
                    calculate clinical scores or send referrals. Record actual escalation separately.
                </p>
            </section>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    perform('save');
                }}
            >
                {schema.sections.map((s) => (
                    <section key={s.title} className="surface space-y-4 p-4 sm:p-6">
                        <h3 className="text-lg font-semibold">{s.title}</h3>
                        <Fields
                            prefix={`assessment-${a.id}`}
                            fields={s.fields.map((x) => ({ ...x, required: false, label: x.label + (x.required ? ' *' : '') }))}
                            values={Object.fromEntries(s.fields.map((x) => [x.key, String(f.data.content[x.key] ?? '')]))}
                            disabled={!a.editable}
                            change={(k, v) => f.setData('content', { ...f.data.content, [k]: v })}
                        />
                    </section>
                ))}
                <Collections
                    schema={Object.fromEntries(
                        Object.entries(schema.children).map(([key, fields]) => [key, fields.map((f) => ({ ...f, required: false }))]),
                    )}
                    values={Object.fromEntries(Object.keys(schema.children).map((k) => [k, f.data.content[k] as Record<string, string>[]]))}
                    disabled={!a.editable}
                    change={(k, v) => f.setData('content', { ...f.data.content, [k]: v })}
                />
                <Errors errors={f.errors} />
                {(a.editable || a.can_cosign) && (
                    <div className="bg-card sticky bottom-3 flex flex-wrap justify-end gap-2 rounded-2xl border p-3 backdrop-blur-xl">
                        {a.editable && (
                            <Button disabled={f.processing} variant="outline">
                                Save draft
                            </Button>
                        )}
                        {a.can_submit && (
                            <Button type="button" onClick={() => setConfirmation('submit')}>
                                Confirm and submit
                            </Button>
                        )}
                        {a.can_sign && (
                            <Button type="button" onClick={() => setConfirmation('sign')}>
                                Sign nursing assessment
                            </Button>
                        )}
                        {a.can_cosign && (
                            <Button type="button" onClick={() => setConfirmation('cosign')}>
                                Clinical lead co-sign
                            </Button>
                        )}
                    </div>
                )}
                {!a.can_submit && a.editable && a.kind === 'basic' && (
                    <p className="text-muted-foreground text-sm">The linked patient must confirm and submit this assisted draft from My care.</p>
                )}
            </form>
            <Dialog
                open={!!confirmation}
                onOpenChange={(v) => {
                    if (!v) {
                        setConfirmation(null);
                        f.setData('password', '');
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>
                        {confirmation === 'submit'
                            ? 'Confirm patient submission'
                            : confirmation === 'cosign'
                              ? 'Clinical lead co-signature'
                              : 'Nurse signature'}
                    </DialogTitle>
                    <DialogDescription>
                        {confirmation === 'submit'
                            ? 'This locks this submission. Corrections create a separate version.'
                            : 'Signing locks the assessment content. Corrections require a new amendment. A separate clinical lead must complete any required co-signature.'}
                    </DialogDescription>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (confirmation) perform(confirmation);
                        }}
                    >
                        {!own && (
                            <div className="space-y-2">
                                <label htmlFor="assessment-password">Confirm your password</label>
                                <Input
                                    id="assessment-password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    value={f.data.password}
                                    onChange={(e) => f.setData('password', e.target.value)}
                                />
                            </div>
                        )}
                        {confirmation === 'submit' && !own && (
                            <div className="space-y-2">
                                <label htmlFor="patient-confirmation">Actual patient verbal confirmation / assisted evidence *</label>
                                <textarea
                                    id="patient-confirmation"
                                    rows={3}
                                    className="w-full"
                                    required
                                    value={f.data.confirmation_evidence}
                                    onChange={(e) => f.setData('confirmation_evidence', e.target.value)}
                                />
                            </div>
                        )}
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="checkbox"
                                required
                                checked={f.data.declaration}
                                onChange={(e) => f.setData('declaration', e.target.checked)}
                            />
                            <span>
                                {confirmation === 'submit'
                                    ? 'I confirm this reflects the actual patient response accurately to the best of their knowledge.'
                                    : confirmation === 'cosign'
                                      ? 'I have reviewed the assessment, discrepancies and follow-up, and confirm my clinical co-signature.'
                                      : 'I personally performed this assessment and confirm the identity checks, agreement, findings and documented follow-up.'}
                            </span>
                        </label>
                        <Errors errors={f.errors} />
                        <Button disabled={f.processing}>{f.processing ? 'Saving…' : 'Confirm'}</Button>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
export default function Assessments({
    patient: p,
    own,
    manage,
    permissions,
    rows,
    schema,
    visits,
    cosignRequired,
}: {
    patient: { id: number; name: string; reference: string };
    own: boolean;
    manage: boolean;
    permissions: Record<string, { read: boolean; write: boolean }>;
    rows: Assessment[];
    schema: Record<string, FormSchema>;
    visits: { id: number; service: string; starts_at: string; status: string }[];
    cosignRequired: boolean;
}) {
    const base = own ? `/my-care/patients/${p.id}/assessments` : `/patient-records/${p.id}/assessments`;
    const [active, setActive] = useState<number | null>(null),
        [createOpen, setCreateOpen] = useState(false),
        [previous, setPrevious] = useState<Assessment | null>(null);
    const create = useForm({
        kind: 'basic',
        appointment_id: '',
        previous_id: null as number | null,
        amendment_reason: '',
        request_id: crypto.randomUUID(),
    });
    const current = rows.find((a) => a.id === active);
    const basic = rows.find((a) => a.kind === 'basic' && a.status === 'submitted');
    const nursing = rows.find((a) => a.kind === 'nursing' && a.status === 'signed');
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: own ? 'My care' : manage ? 'Patients' : 'My visits',
                    href: own ? '/my-care' : manage ? '/workspace/patients' : '/my-visits',
                },
                { title: 'Assessments', href: base },
            ]}
        >
            <Head title={`${p.name} · Assessments`} />
            <main className="mx-auto w-full max-w-6xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface space-y-3 p-4 sm:p-6">
                    <h1 className="text-2xl font-semibold break-words">{p.name}: assessments</h1>
                    <p className="text-muted-foreground font-mono text-xs break-all">{p.reference}</p>
                    <p className="text-muted-foreground text-sm">
                        {own
                            ? 'Your basic assessment responses and retained submission versions.'
                            : 'Basic patient intake and private nursing assessments. Operational access shows completion only; contents require category-specific clinical authority.'}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={own ? `/my-care/patients/${p.id}` : manage ? '/workspace/patients' : '/my-visits'}>
                                Back to {own ? 'my record' : manage ? 'patients' : 'my visits'}
                            </Link>
                        </Button>
                        {Object.values(permissions).some((x) => x.write) && (
                            <Button
                                onClick={() => {
                                    setPrevious(null);
                                    create.reset();
                                    create.setData({
                                        kind: permissions.basic.write ? 'basic' : 'nursing',
                                        appointment_id: '',
                                        previous_id: null,
                                        amendment_reason: '',
                                        request_id: crypto.randomUUID(),
                                    });
                                    setCreateOpen(true);
                                }}
                            >
                                New assessment
                            </Button>
                        )}
                    </div>
                </header>
                {current && current.content ? (
                    <Editor
                        key={`${current.id}-${current.version}`}
                        record={current}
                        schema={schema[current.kind]}
                        base={base}
                        own={own}
                        close={() => setActive(null)}
                    />
                ) : (
                    <>
                        <section className="grid gap-4 sm:grid-cols-2">
                            {(own ? ['basic'] : ['basic', 'nursing']).map((kind) => {
                                const latest = rows.find((a) => a.kind === kind);
                                return (
                                    <article className="surface space-y-2 p-4" key={kind}>
                                        <h2 className="font-semibold">{title(kind)} completion</h2>
                                        <p>{latest ? `${title(latest.status)} · #${latest.id}` : 'Not started'}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {kind === 'nursing' && cosignRequired
                                                ? 'Nurse signature and separate clinical lead co-signature required.'
                                                : 'Submission locks the version; corrections are retained separately.'}
                                        </p>
                                    </article>
                                );
                            })}
                        </section>
                        {!own && permissions.basic.read && permissions.nursing.read && (
                            <section className="surface space-y-3 p-4 sm:p-6">
                                <h2 className="text-lg font-semibold">Clinical comparison</h2>
                                <p className="text-muted-foreground text-sm">
                                    Latest submitted basic #{basic?.id ?? '—'} / fully signed nursing #{nursing?.id ?? '—'}. Values are shown for
                                    clinical review; differences are not automatically judged.
                                </p>
                                {basic?.content && (
                                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                                        {['conditions_status', 'medicines_status', 'allergies_status'].map((k) => (
                                            <div key={k}>
                                                <dt className="text-muted-foreground">Patient {title(k)}</dt>
                                                <dd>{title(String(basic.content?.[k] ?? 'Not recorded'))}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                {nursing?.content && (
                                    <div className="space-y-3 text-sm break-words whitespace-pre-wrap">
                                        <p>
                                            <strong>Nursing comparison:</strong> {String(nursing.content.comparison_review ?? 'Not recorded')}
                                        </p>
                                        <p>
                                            <strong>Unresolved concerns:</strong> {String(nursing.content.unresolved_concerns ?? 'Not recorded')}
                                        </p>
                                        <p>
                                            <strong>Escalation / follow-up:</strong> {String(nursing.content.escalation_evidence ?? 'None recorded')}
                                        </p>
                                        <p>
                                            <strong>Review due:</strong> {nursing.review_due?.slice(0, 10)}
                                        </p>
                                    </div>
                                )}
                            </section>
                        )}
                        <section className="surface space-y-3 p-4 sm:p-6">
                            <h2 className="text-lg font-semibold">Retained versions</h2>
                            {!rows.length && <p className="text-muted-foreground py-5 text-sm">No assessments yet.</p>}
                            {rows.map((a) => (
                                <article key={a.id} className="space-y-3 rounded-xl border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <h3 className="font-medium">
                                            {title(a.kind)} #{a.id}
                                            {a.previous_id ? ` · Amendment of #${a.previous_id}` : ''}
                                        </h3>
                                        <Badge variant={a.status === 'signed' || a.status === 'submitted' ? 'success' : 'secondary'}>
                                            {title(a.status)}
                                        </Badge>
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {a.author.name} · Revision {a.version} · Submitted {date(a.submitted_at)} · Signed {date(a.signed_at)}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {a.content ? (
                                            <Button variant="outline" onClick={() => setActive(a.id)}>
                                                {a.editable ? 'Continue draft' : a.can_cosign ? 'Review and co-sign' : 'Open version'}
                                            </Button>
                                        ) : (
                                            <span className="text-muted-foreground text-sm">
                                                Completion status only. Clinical content restricted.
                                            </span>
                                        )}
                                        {a.can_amend && (
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    setPrevious(a);
                                                    create.setData({
                                                        kind: a.kind,
                                                        appointment_id: String(a.appointment_id ?? ''),
                                                        previous_id: a.id,
                                                        amendment_reason: '',
                                                        request_id: crypto.randomUUID(),
                                                    });
                                                    setCreateOpen(true);
                                                }}
                                            >
                                                Create amendment
                                            </Button>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </section>
                    </>
                )}
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent>
                        <DialogTitle>{previous ? 'Create retained amendment' : 'New assessment draft'}</DialogTitle>
                        <DialogDescription>
                            {previous
                                ? 'The original remains unchanged. Record why a correction is needed.'
                                : 'Start a draft, then complete the required fields before submission or signing.'}
                        </DialogDescription>
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                create.post(base, {
                                    onSuccess: () => {
                                        setCreateOpen(false);
                                        setActive(null);
                                    },
                                });
                            }}
                        >
                            <div className="space-y-2">
                                <label htmlFor="assessment-kind">Assessment type</label>
                                <select
                                    className="w-full"
                                    id="assessment-kind"
                                    disabled={!!previous}
                                    value={create.data.kind}
                                    onChange={(e) => create.setData('kind', e.target.value)}
                                >
                                    {Object.keys(schema)
                                        .filter((k) => permissions[k].write)
                                        .map((k) => (
                                            <option value={k} key={k}>
                                                {title(k)}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            {create.data.kind === 'nursing' && (
                                <div className="space-y-2">
                                    <label htmlFor="assessment-visit">Your assigned visit *</label>
                                    <select
                                        id="assessment-visit"
                                        className="w-full"
                                        required
                                        value={create.data.appointment_id}
                                        onChange={(e) => create.setData('appointment_id', e.target.value)}
                                    >
                                        <option value="">Choose a visit</option>
                                        {visits.map((v) => (
                                            <option key={v.id} value={v.id}>
                                                #{v.id} · {v.service} · {date(v.starts_at)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            {previous && (
                                <div className="space-y-2">
                                    <label htmlFor="amendment-reason">Reason for correction *</label>
                                    <textarea
                                        id="amendment-reason"
                                        className="w-full"
                                        rows={3}
                                        required
                                        value={create.data.amendment_reason}
                                        onChange={(e) => create.setData('amendment_reason', e.target.value)}
                                    />
                                </div>
                            )}
                            <Errors errors={create.errors} />
                            <Button disabled={create.processing}>Create draft</Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </main>
        </AppLayout>
    );
}
