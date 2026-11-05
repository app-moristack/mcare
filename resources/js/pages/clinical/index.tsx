import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { date, Errors, title } from '@/pages/patients/fields';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ClinicalEditor, { ClinicalRow, ClinicalSchema } from './editor';
type Permission = { read: boolean; write: boolean; publish: boolean; approve?: boolean };
export default function ClinicalRecords({
    patient: p,
    manage,
    permissions,
    noteTypes,
    rows,
    schema,
    episodes,
    visits,
    referrals,
    staff,
    reviewBackups,
}: {
    patient: { id: number; name: string; reference: string };
    manage: boolean;
    permissions: Record<string, Permission>;
    noteTypes: string[];
    rows: ClinicalRow[];
    schema: Record<string, ClinicalSchema>;
    episodes: { id: number; title: string; current_plan_id: number | null }[];
    visits: { id: number; service: string; starts_at: string }[];
    referrals: { id: number; discipline: string }[];
    staff: { id: number; name: string }[];
    reviewBackups: { id: number; name: string }[];
}) {
    const base = `/patient-records/${p.id}/clinical`;
    const [tab, setTab] = useState<'notes' | 'plans'>(manage || permissions.notes.read ? 'notes' : 'plans'),
        [active, setActive] = useState<number | null>(null),
        [creating, setCreating] = useState(false),
        [dialog, setDialog] = useState<{ record: ClinicalRow; action: string } | null>(null);
    const create = useForm({
        request_id: crypto.randomUUID(),
        note_type: noteTypes[0] ?? '',
        visibility: 'discipline_only',
        appointment_id: '',
        original_id: null as number | null,
        addendum_reason: '',
        episode_id: '',
        episode_title: '',
        referral_id: '',
        previous_id: null as number | null,
        revision_reason: '',
    });
    const action = useForm({
        request_id: crypto.randomUUID(),
        version: 0,
        password: '',
        declaration: false as boolean,
        reason: '',
        review_backup_id: '',
        summary: '',
        shared_goals: '',
    });
    const selected = rows.find((a) => a.id === active && a.kind === tab);
    const openCreate = (record?: ClinicalRow) => {
        create.setData({
            request_id: crypto.randomUUID(),
            note_type: record?.note_type ?? noteTypes[0] ?? '',
            visibility: record?.visibility ?? 'discipline_only',
            appointment_id: String(record?.appointment_id ?? ''),
            original_id: record?.kind === 'notes' ? record.id : null,
            addendum_reason: '',
            episode_id: record?.episode ? String(record.episode.id) : '',
            episode_title: '',
            referral_id: '',
            previous_id: record?.kind === 'plans' ? record.id : null,
            revision_reason: '',
        });
        setCreating(true);
    };
    const openAction = (record: ClinicalRow, kind: string) => {
        action.setData({
            request_id: crypto.randomUUID(),
            version: record.version,
            password: '',
            declaration: false,
            reason: '',
            review_backup_id: '',
            summary: '',
            shared_goals: '',
        });
        action.clearErrors();
        setDialog({ record, action: kind });
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: manage ? 'Patients' : 'My visits', href: manage ? '/workspace/patients' : '/my-visits' },
                { title: 'Notes and care plans', href: base },
            ]}
        >
            <Head title={`${p.name} · Clinical records`} />
            <main className="mx-auto w-full max-w-6xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface space-y-3 p-4 sm:p-6">
                    <h1 className="text-2xl font-semibold break-words">{p.name}: notes and care plans</h1>
                    <p className="text-muted-foreground font-mono text-xs break-all">{p.reference}</p>
                    <p className="text-muted-foreground text-sm">
                        Signed records are retained. Only separately reviewed summaries are published to the patient. A care-plan state change does
                        not complete visits, settle invoices or discharge an episode.
                    </p>
                    <Button asChild variant="outline">
                        <Link href={manage ? '/workspace/patients' : '/my-visits'}>Back to {manage ? 'patients' : 'my visits'}</Link>
                    </Button>
                </header>
                {!selected && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <nav aria-label="Clinical record type" className="flex flex-wrap gap-2">
                            {(['notes', 'plans'] as const)
                                .filter((k) => manage || permissions[k].read)
                                .map((k) => (
                                    <Button
                                        key={k}
                                        variant={tab === k ? 'default' : 'outline'}
                                        aria-pressed={tab === k}
                                        onClick={() => {
                                            setTab(k);
                                            setActive(null);
                                        }}
                                    >
                                        {k === 'notes' ? 'Professional notes' : 'Care plans'}
                                    </Button>
                                ))}
                        </nav>
                        {permissions[tab].write && <Button onClick={() => openCreate()}>New {tab === 'notes' ? 'note' : 'care plan'}</Button>}
                    </div>
                )}
                {selected?.content ? (
                    <ClinicalEditor
                        key={`${selected.kind}-${selected.id}`}
                        row={selected}
                        schema={schema[tab]}
                        base={base}
                        staff={staff}
                        close={() => {
                            setActive(null);
                            router.reload({ only: ['rows', 'episodes', 'permissions', 'noteTypes'] });
                        }}
                    />
                ) : (
                    <section className="space-y-4">
                        {!rows.some((r) => r.kind === tab) && (
                            <div className="surface text-muted-foreground p-6 text-sm">
                                No {tab === 'notes' ? 'professional notes' : 'care plans'} yet.
                            </div>
                        )}
                        {rows
                            .filter((r) => r.kind === tab)
                            .map((a) => (
                                <article key={`${a.kind}-${a.id}`} className="surface space-y-4 p-4 sm:p-6">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <h2 className="text-lg font-semibold">
                                            {tab === 'notes'
                                                ? `${a.original_id ? 'Addendum' : 'Note'} #${a.id} · ${title(a.note_type ?? '')}`
                                                : `${a.episode?.title ?? 'Care plan'} · Version ${a.revision_number}`}
                                        </h2>
                                        <Badge variant={['signed', 'active'].includes(a.status) ? 'success' : 'secondary'}>{title(a.status)}</Badge>
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {a.author.name} · Signed {date(a.signed_at)}
                                        {a.approved_at ? ` · Approved ${date(a.approved_at)}` : ''}
                                    </p>
                                    {a.original_id && <p className="text-sm">References original note #{a.original_id}</p>}
                                    {a.review_due_at && (
                                        <p className="text-sm">
                                            Review due {date(a.review_due_at)}
                                            {['active', 'paused'].includes(a.status) && new Date(a.review_due_at) < new Date()
                                                ? ' · Overdue — clinical review required'
                                                : ''}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {a.content ? (
                                            <Button variant="outline" onClick={() => setActive(a.id)}>
                                                {a.editable ? 'Continue draft' : 'Open signed version'}
                                            </Button>
                                        ) : (
                                            <span className="text-muted-foreground text-sm">Status only. Clinical content restricted.</span>
                                        )}
                                        {a.can_addendum && (
                                            <Button variant="outline" onClick={() => openCreate(a)}>
                                                Add attributed addendum
                                            </Button>
                                        )}
                                        {a.can_revise && (
                                            <Button variant="outline" onClick={() => openCreate(a)}>
                                                Propose replacement
                                            </Button>
                                        )}
                                        {a.can_approve && <Button onClick={() => openAction(a, 'approve')}>Review and approve</Button>}
                                        {a.can_transition && (
                                            <>
                                                <Button variant="outline" onClick={() => openAction(a, a.status === 'active' ? 'pause' : 'resume')}>
                                                    {a.status === 'active' ? 'Pause' : 'Resume'}
                                                </Button>
                                                <Button variant="outline" onClick={() => openAction(a, 'complete')}>
                                                    Complete plan
                                                </Button>
                                            </>
                                        )}
                                        {a.can_publish && (
                                            <Button variant="outline" onClick={() => openAction(a, 'publish')}>
                                                Publish patient summary
                                            </Button>
                                        )}
                                    </div>
                                </article>
                            ))}
                    </section>
                )}
                <Dialog open={creating} onOpenChange={setCreating}>
                    <DialogContent className="max-w-xl">
                        <DialogTitle>
                            {tab === 'notes'
                                ? create.data.original_id
                                    ? 'New addendum'
                                    : 'New note draft'
                                : create.data.previous_id
                                  ? 'New plan version'
                                  : 'New care-plan draft'}
                        </DialogTitle>
                        <DialogDescription>
                            {tab === 'notes'
                                ? 'The note is tied to your assigned visit and professional discipline.'
                                : 'Only one approved current plan is allowed per episode. A separate clinical lead approves the signed proposal.'}
                        </DialogDescription>
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                create.post(`${base}/${tab}`, { onSuccess: () => setCreating(false) });
                            }}
                        >
                            {tab === 'notes' ? (
                                <>
                                    <label htmlFor="note-type">Professional template</label>
                                    <select
                                        id="note-type"
                                        className="w-full"
                                        value={create.data.note_type}
                                        onChange={(e) => create.setData('note_type', e.target.value)}
                                    >
                                        {noteTypes.map((t) => (
                                            <option key={t} value={t}>
                                                {title(t)}
                                            </option>
                                        ))}
                                    </select>
                                    <label htmlFor="note-visit">Your assigned visit</label>
                                    <select
                                        id="note-visit"
                                        className="w-full"
                                        required
                                        value={create.data.appointment_id}
                                        onChange={(e) => create.setData('appointment_id', e.target.value)}
                                    >
                                        <option value="">Select…</option>
                                        {visits.map((v) => (
                                            <option value={v.id} key={v.id}>
                                                #{v.id} · {v.service} · {date(v.starts_at)}
                                            </option>
                                        ))}
                                    </select>
                                    <label htmlFor="note-visibility">Professional visibility</label>
                                    <select
                                        id="note-visibility"
                                        className="w-full"
                                        value={create.data.visibility}
                                        onChange={(e) => create.setData('visibility', e.target.value)}
                                    >
                                        <option value="discipline_only">Assigned discipline and clinical lead only</option>
                                        <option value="clinical_team">Authorised clinical team</option>
                                    </select>
                                    {create.data.original_id && (
                                        <>
                                            <label htmlFor="addendum-reason">Reason for addendum *</label>
                                            <textarea
                                                id="addendum-reason"
                                                required
                                                className="w-full"
                                                rows={3}
                                                value={create.data.addendum_reason}
                                                onChange={(e) => create.setData('addendum_reason', e.target.value)}
                                            />
                                        </>
                                    )}
                                </>
                            ) : (
                                <>
                                    <label htmlFor="plan-episode">Care episode</label>
                                    <select
                                        id="plan-episode"
                                        className="w-full"
                                        disabled={!!create.data.previous_id}
                                        value={create.data.episode_id}
                                        onChange={(e) => create.setData('episode_id', e.target.value)}
                                    >
                                        <option value="">Create new care episode</option>
                                        {episodes.map((ep) => (
                                            <option value={ep.id} key={ep.id}>
                                                {ep.title}
                                            </option>
                                        ))}
                                    </select>
                                    {!create.data.episode_id && (
                                        <>
                                            <label htmlFor="episode-title">Episode title *</label>
                                            <Input
                                                id="episode-title"
                                                required
                                                value={create.data.episode_title}
                                                onChange={(e) => create.setData('episode_title', e.target.value)}
                                            />
                                            <label htmlFor="episode-referral">Source referral (optional)</label>
                                            <select
                                                id="episode-referral"
                                                className="w-full"
                                                value={create.data.referral_id}
                                                onChange={(e) => create.setData('referral_id', e.target.value)}
                                            >
                                                <option value="">No linked referral</option>
                                                {referrals.map((v) => (
                                                    <option value={v.id} key={v.id}>
                                                        #{v.id} · {v.discipline}
                                                    </option>
                                                ))}
                                            </select>
                                        </>
                                    )}
                                    {create.data.previous_id && (
                                        <>
                                            <label htmlFor="revision-reason">Reason for replacement *</label>
                                            <textarea
                                                id="revision-reason"
                                                className="w-full"
                                                required
                                                rows={3}
                                                value={create.data.revision_reason}
                                                onChange={(e) => create.setData('revision_reason', e.target.value)}
                                            />
                                        </>
                                    )}
                                </>
                            )}
                            <Errors errors={create.errors} />
                            <Button disabled={create.processing}>Create draft</Button>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog
                    open={!!dialog}
                    onOpenChange={(open) => {
                        if (!open) {
                            setDialog(null);
                            action.setData('password', '');
                        }
                    }}
                >
                    <DialogContent>
                        <DialogTitle>
                            {title(dialog?.action ?? '')} {dialog?.action === 'publish' ? 'patient summary' : 'care plan'}
                        </DialogTitle>
                        <DialogDescription>
                            {dialog?.action === 'publish'
                                ? 'Publish only the reviewed plain-language text entered below. Private source fields are not automatically shared.'
                                : 'Confirm the clinical decision and reason. Approval assigns you the routine review task with a separate operational backup.'}
                        </DialogDescription>
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!dialog) return;
                                action.transform((d) => ({ ...d, action: dialog.action }));
                                const url =
                                    dialog.action === 'publish'
                                        ? `${base}/${dialog.record.kind}/${dialog.record.id}/publish`
                                        : `${base}/plans/${dialog.record.id}/transition`;
                                action.post(url, {
                                    onSuccess: () => {
                                        setDialog(null);
                                        action.reset();
                                    },
                                    onFinish: () => action.setData('password', ''),
                                });
                            }}
                        >
                            {dialog?.action === 'publish' ? (
                                <>
                                    <label htmlFor="public-summary">Reviewed patient summary *</label>
                                    <textarea
                                        id="public-summary"
                                        className="w-full"
                                        required
                                        rows={4}
                                        maxLength={5000}
                                        value={action.data.summary}
                                        onChange={(e) => action.setData('summary', e.target.value)}
                                    />
                                    {dialog.record.kind === 'plans' && (
                                        <>
                                            <label htmlFor="shared-goals">Reviewed goals to share with the patient</label>
                                            <textarea
                                                id="shared-goals"
                                                className="w-full"
                                                rows={3}
                                                maxLength={5000}
                                                value={action.data.shared_goals}
                                                onChange={(e) => action.setData('shared_goals', e.target.value)}
                                            />
                                        </>
                                    )}
                                </>
                            ) : (
                                <>
                                    <label htmlFor="plan-action-reason">Clinical decision and reason *</label>
                                    <textarea
                                        id="plan-action-reason"
                                        className="w-full"
                                        required
                                        rows={3}
                                        value={action.data.reason}
                                        onChange={(e) => action.setData('reason', e.target.value)}
                                    />
                                    {dialog?.action === 'approve' && (
                                        <>
                                            <label htmlFor="review-backup">Routine review backup *</label>
                                            <select
                                                id="review-backup"
                                                className="w-full"
                                                required
                                                value={action.data.review_backup_id}
                                                onChange={(e) => action.setData('review_backup_id', e.target.value)}
                                            >
                                                <option value="">Select a separate operational task owner</option>
                                                {reviewBackups.map((u) => (
                                                    <option key={u.id} value={u.id}>
                                                        {u.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </>
                                    )}
                                </>
                            )}
                            <label htmlFor="decision-password">Confirm password</label>
                            <Input
                                id="decision-password"
                                required
                                type="password"
                                autoComplete="current-password"
                                value={action.data.password}
                                onChange={(e) => action.setData('password', e.target.value)}
                            />
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    required
                                    checked={action.data.declaration}
                                    onChange={(e) => action.setData('declaration', e.target.checked)}
                                />
                                <span>I reviewed the record, authority and clinical scope and confirm this attributable decision.</span>
                            </label>
                            <Errors errors={action.errors} />
                            <Button disabled={action.processing}>Confirm</Button>
                        </form>
                    </DialogContent>
                </Dialog>
            </main>
        </AppLayout>
    );
}
