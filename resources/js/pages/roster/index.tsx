import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Named = { id: number; name: string };
type Entry = {
    id: number;
    kind: string;
    starts_at: string;
    ends_at: string;
    locality: string | null;
    notes: string | null;
    status: string;
    version: number;
    review_evidence: string | null;
    cancellation_reason: string | null;
    provider?: Named;
    reviewer?: Named;
};
type Visit = {
    id: number;
    version: number;
    service: string;
    starts_at: string;
    ends_at: string;
    status: string;
    offer_status: string;
    offer_evidence: string | null;
    patient: Named & { reference?: string };
    provider?: Named;
};
type MissedCase = {
    id: number;
    version: number;
    status: string;
    welfare_status: string;
    welfare_evidence: string | null;
    replacement_status: string;
    replacement_evidence: string | null;
    resolution_evidence: string | null;
    missed_reason: string;
    due_at: string;
    patient: Named;
    appointment: { id: number; starts_at: string; service: string };
    replacement: { id: number; starts_at: string; status: string; offer_status: string } | null;
    owner: Named;
    backup: Named;
};
const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const date = (value: string) => new Date(value).toLocaleString('en-MU', { timeZone: 'Indian/Mauritius', dateStyle: 'medium', timeStyle: 'short' });
const Errors = ({ values }: { values: Record<string, string> }) =>
    Object.keys(values).length ? (
        <div role="alert" className="border-destructive/40 bg-destructive/10 text-destructive rounded-xl border p-3 text-sm">
            <p className="font-medium">Please correct this submission.</p>
            <ul className="mt-1 list-disc pl-5">
                {Object.entries(values).map(([key, value]) => (
                    <li key={key}>{value}</li>
                ))}
            </ul>
        </div>
    ) : null;

function Respond({ visit }: { visit: Visit }) {
    const form = useForm({ version: visit.version, decision: 'accept', evidence: '', declaration: false as boolean });
    return (
        <form
            className="mt-3 space-y-3 rounded-xl border p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/my-visits/${visit.id}/respond`, { preserveScroll: true });
            }}
        >
            <p className="text-sm font-medium">Respond to this offer</p>
            <select
                className="w-full"
                aria-label="Offer response"
                value={form.data.decision}
                onChange={(event) => form.setData('decision', event.target.value)}
            >
                <option value="accept">Accept visit</option>
                <option value="decline">Decline visit</option>
            </select>
            <textarea
                className="w-full"
                rows={2}
                required
                maxLength={2000}
                placeholder="Availability check or decline reason *"
                value={form.data.evidence}
                onChange={(event) => form.setData('evidence', event.target.value)}
            />
            <label className="flex items-start gap-2 text-sm">
                <input
                    className="mt-1"
                    type="checkbox"
                    checked={form.data.declaration}
                    onChange={(event) => form.setData('declaration', event.target.checked)}
                />{' '}
                I checked the time, service and location against my approved roster.
            </label>
            <Errors values={form.errors} />
            <Button disabled={form.processing}>{form.processing ? 'Recording…' : 'Record response'}</Button>
        </form>
    );
}

function CancelEntry({ entry }: { entry: Entry }) {
    const form = useForm({ version: entry.version, reason: '' });
    return (
        <form
            className="mt-3 flex flex-col gap-2 sm:flex-row"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/my-roster/${entry.id}/cancel`, { preserveScroll: true });
            }}
        >
            <input
                className="min-w-0 flex-1"
                required
                maxLength={2000}
                placeholder="Cancellation reason *"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <Button variant="outline" disabled={form.processing}>
                Cancel period
            </Button>
            <Errors values={form.errors} />
        </form>
    );
}

function ReviewEntry({ entry, staff }: { entry: Entry; staff: Named[] }) {
    const form = useForm({ version: entry.version, decision: 'approve', review_evidence: '', backup_id: '', due_at: '' });
    return (
        <form
            className="mt-3 grid gap-3 rounded-xl border p-3 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/workspace/roster/${entry.id}/review`, { preserveScroll: true });
            }}
        >
            <select
                className="w-full"
                aria-label="Roster decision"
                value={form.data.decision}
                onChange={(event) => form.setData('decision', event.target.value)}
            >
                <option value="approve">Approve</option>
                <option value="reject">Reject</option>
            </select>
            <textarea
                className="w-full sm:col-span-2"
                required
                rows={2}
                maxLength={3000}
                placeholder="Review evidence *"
                value={form.data.review_evidence}
                onChange={(event) => form.setData('review_evidence', event.target.value)}
            />
            {form.data.decision === 'approve' && entry.kind !== 'availability' && (
                <>
                    <select
                        className="w-full"
                        aria-label="Operational backup"
                        value={form.data.backup_id}
                        onChange={(event) => form.setData('backup_id', event.target.value)}
                    >
                        <option value="">Backup if visits are affected</option>
                        {staff.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </select>
                    <input
                        className="w-full"
                        type="datetime-local"
                        aria-label="Reassignment deadline"
                        value={form.data.due_at}
                        onChange={(event) => form.setData('due_at', event.target.value)}
                    />
                </>
            )}
            <div className="sm:col-span-2">
                <Errors values={form.errors} />
                <Button disabled={form.processing}>{form.processing ? 'Recording…' : 'Record review'}</Button>
            </div>
        </form>
    );
}

function MarkMissed({ visit, staff }: { visit: Visit; staff: Named[] }) {
    const form = useForm({ request_id: crypto.randomUUID(), version: visit.version, missed_reason: '', owner_id: '', backup_id: '', due_at: '' });
    return (
        <form
            className="mt-3 grid gap-3 rounded-xl border p-3 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/workspace/appointments/${visit.id}/missed`, { preserveScroll: true });
            }}
        >
            <textarea
                className="w-full sm:col-span-2"
                required
                rows={2}
                maxLength={3000}
                placeholder="Evidence that the visit was missed *"
                value={form.data.missed_reason}
                onChange={(event) => form.setData('missed_reason', event.target.value)}
            />
            <select className="w-full" required value={form.data.owner_id} onChange={(event) => form.setData('owner_id', event.target.value)}>
                <option value="">Welfare owner *</option>
                {staff.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name}
                    </option>
                ))}
            </select>
            <select className="w-full" required value={form.data.backup_id} onChange={(event) => form.setData('backup_id', event.target.value)}>
                <option value="">Distinct backup *</option>
                {staff.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name}
                    </option>
                ))}
            </select>
            <label className="text-sm">
                Welfare/replacement deadline *
                <input
                    className="mt-1 w-full"
                    type="datetime-local"
                    required
                    value={form.data.due_at}
                    onChange={(event) => form.setData('due_at', event.target.value)}
                />
            </label>
            <div>
                <Errors values={form.errors} />
                <Button disabled={form.processing}>{form.processing ? 'Opening…' : 'Open missed-visit case'}</Button>
            </div>
        </form>
    );
}

function CaseActions({ record }: { record: MissedCase }) {
    const welfare = useForm({ version: record.version, welfare_status: 'contacted', welfare_evidence: '', checked_at: '' });
    const waiver = useForm({ version: record.version, replacement_evidence: '' });
    const resolve = useForm({ version: record.version, resolution_evidence: '' });
    return (
        <div className="mt-4 space-y-3">
            {record.welfare_status === 'pending' && (
                <form
                    className="space-y-3 rounded-xl border p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        welfare.post(`/workspace/missed-visits/${record.id}/welfare`, { preserveScroll: true });
                    }}
                >
                    <p className="text-sm font-medium">Record real-world welfare action</p>
                    <select
                        className="w-full"
                        value={welfare.data.welfare_status}
                        onChange={(event) => welfare.setData('welfare_status', event.target.value)}
                    >
                        <option value="contacted">Contacted</option>
                        <option value="escalated">Escalated externally</option>
                        <option value="not_required">Not required</option>
                    </select>
                    <input
                        className="w-full"
                        type="datetime-local"
                        required
                        value={welfare.data.checked_at}
                        onChange={(event) => welfare.setData('checked_at', event.target.value)}
                    />
                    <textarea
                        className="w-full"
                        rows={2}
                        required
                        maxLength={4000}
                        placeholder="Channel, outcome and external evidence *"
                        value={welfare.data.welfare_evidence}
                        onChange={(event) => welfare.setData('welfare_evidence', event.target.value)}
                    />
                    <Errors values={welfare.errors} />
                    <Button disabled={welfare.processing}>Record welfare outcome</Button>
                </form>
            )}
            {record.welfare_status !== 'pending' && record.replacement_status === 'required' && (
                <form
                    className="space-y-3 rounded-xl border p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        waiver.post(`/workspace/missed-visits/${record.id}/waive-replacement`, { preserveScroll: true });
                    }}
                >
                    <p className="text-sm font-medium">Waive replacement only when justified</p>
                    <textarea
                        className="w-full"
                        rows={2}
                        required
                        maxLength={4000}
                        placeholder="Patient/clinical decision evidence *"
                        value={waiver.data.replacement_evidence}
                        onChange={(event) => waiver.setData('replacement_evidence', event.target.value)}
                    />
                    <Errors values={waiver.errors} />
                    <Button variant="outline" disabled={waiver.processing}>
                        Record replacement waiver
                    </Button>
                </form>
            )}
            {record.welfare_status !== 'pending' && ['waived', 'booked'].includes(record.replacement_status) && (
                <form
                    className="space-y-3 rounded-xl border p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        resolve.post(`/workspace/missed-visits/${record.id}/resolve`, { preserveScroll: true });
                    }}
                >
                    <p className="text-sm font-medium">Resolve accountable case</p>
                    <textarea
                        className="w-full"
                        rows={2}
                        required
                        maxLength={4000}
                        placeholder="Resolution evidence *"
                        value={resolve.data.resolution_evidence}
                        onChange={(event) => resolve.setData('resolution_evidence', event.target.value)}
                    />
                    <Errors values={resolve.errors} />
                    <Button disabled={resolve.processing}>Resolve case</Button>
                </form>
            )}
        </div>
    );
}

export default function Roster({
    mode,
    entries,
    visits,
    cases,
    missableVisits,
    staff,
}: {
    mode: 'provider' | 'workspace';
    entries: Entry[];
    visits: Visit[];
    cases: MissedCase[];
    missableVisits: Visit[];
    providers: Named[];
    staff: Named[];
}) {
    const entry = useForm({
        request_id: crypto.randomUUID(),
        kind: 'availability',
        starts_at: '',
        ends_at: '',
        locality: '',
        notes: '',
        declaration: false as boolean,
    });
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: mode === 'provider' ? 'My roster' : 'Roster & missed visits',
                    href: mode === 'provider' ? '/my-roster' : '/workspace/roster',
                },
            ]}
        >
            <Head title={mode === 'provider' ? 'My roster' : 'Roster & missed visits'} />
            <main className="mx-auto w-full max-w-6xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface space-y-2 p-4 sm:p-6">
                    <p className="text-muted-foreground text-xs">{mode === 'provider' ? 'Provider workspace' : 'Care operations'}</p>
                    <h1 className="text-2xl font-semibold">
                        {mode === 'provider' ? 'My availability, leave and travel' : 'Provider roster and missed visits'}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        All times display in Mauritius time. Roster approval never changes visits automatically; each offer, welfare action and
                        replacement remains explicit and audited.
                    </p>
                </header>
                {mode === 'provider' && (
                    <div className="grid items-start gap-5 lg:grid-cols-2">
                        <form
                            className="surface space-y-4 p-4 sm:p-6"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                entry.post('/my-roster', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        entry.setData({
                                            request_id: crypto.randomUUID(),
                                            kind: 'availability',
                                            starts_at: '',
                                            ends_at: '',
                                            locality: '',
                                            notes: '',
                                            declaration: false,
                                        }),
                                });
                            }}
                        >
                            <h2 className="text-lg font-semibold">Submit roster period</h2>
                            <select className="w-full" value={entry.data.kind} onChange={(event) => entry.setData('kind', event.target.value)}>
                                <option value="availability">Available</option>
                                <option value="leave">Leave</option>
                                <option value="travel">Travel / unavailable locality</option>
                            </select>
                            <label className="text-sm">
                                Starts *
                                <input
                                    className="mt-1 w-full"
                                    type="datetime-local"
                                    required
                                    value={entry.data.starts_at}
                                    onChange={(event) => entry.setData('starts_at', event.target.value)}
                                />
                            </label>
                            <label className="text-sm">
                                Ends *
                                <input
                                    className="mt-1 w-full"
                                    type="datetime-local"
                                    required
                                    value={entry.data.ends_at}
                                    onChange={(event) => entry.setData('ends_at', event.target.value)}
                                />
                            </label>
                            <input
                                className="w-full"
                                maxLength={255}
                                placeholder="Available locality (optional)"
                                value={entry.data.locality}
                                onChange={(event) => entry.setData('locality', event.target.value)}
                            />
                            <textarea
                                className="w-full"
                                rows={3}
                                maxLength={2000}
                                required={entry.data.kind !== 'availability'}
                                placeholder="Minimum necessary context"
                                value={entry.data.notes}
                                onChange={(event) => entry.setData('notes', event.target.value)}
                            />
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    className="mt-1"
                                    type="checkbox"
                                    checked={entry.data.declaration}
                                    onChange={(event) => entry.setData('declaration', event.target.checked)}
                                />{' '}
                                I confirm this period is accurate and understand operational review is required.
                            </label>
                            <Errors values={entry.errors} />
                            <Button disabled={entry.processing}>{entry.processing ? 'Submitting…' : 'Submit period'}</Button>
                        </form>
                        <section className="surface p-4 sm:p-6">
                            <h2 className="text-lg font-semibold">Visit offers</h2>
                            <div className="mt-4 space-y-3">
                                {visits.length === 0 && <p className="text-muted-foreground text-sm">No current visit offers.</p>}
                                {visits.map((visit) => (
                                    <article className="rounded-xl border p-4" key={visit.id}>
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <h3 className="font-medium">
                                                {visit.patient.name} · {visit.service}
                                            </h3>
                                            <Badge variant="secondary">{title(visit.offer_status)}</Badge>
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {date(visit.starts_at)}–{date(visit.ends_at)}
                                        </p>
                                        {visit.offer_status === 'pending' && visit.status === 'booked' && <Respond visit={visit} />}
                                    </article>
                                ))}
                            </div>
                        </section>
                    </div>
                )}
                <section className="surface p-4 sm:p-6">
                    <h2 className="text-lg font-semibold">{mode === 'provider' ? 'My roster periods' : 'Roster review queue'}</h2>
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        {entries.length === 0 && <p className="text-muted-foreground text-sm">No roster periods.</p>}
                        {entries.map((item) => (
                            <article className="rounded-xl border p-4" key={item.id}>
                                <div className="flex flex-wrap justify-between gap-2">
                                    <h3 className="font-medium">
                                        {item.provider?.name ? `${item.provider.name} · ` : ''}
                                        {title(item.kind)}
                                    </h3>
                                    <Badge
                                        variant={
                                            item.status === 'approved'
                                                ? 'success'
                                                : item.status === 'rejected' || item.status === 'cancelled'
                                                  ? 'warning'
                                                  : 'secondary'
                                        }
                                    >
                                        {title(item.status)}
                                    </Badge>
                                </div>
                                <p className="mt-2 text-sm">
                                    {date(item.starts_at)}–{date(item.ends_at)}
                                </p>
                                {item.locality && <p className="text-muted-foreground text-sm">Locality: {item.locality}</p>}
                                {item.notes && <p className="mt-2 text-sm break-words">{item.notes}</p>}
                                {item.review_evidence && <p className="text-muted-foreground mt-2 text-xs">Review: {item.review_evidence}</p>}
                                {mode === 'provider' && ['pending', 'approved'].includes(item.status) && new Date(item.ends_at) > new Date() && (
                                    <CancelEntry entry={item} />
                                )}
                                {mode === 'workspace' && item.status === 'pending' && <ReviewEntry entry={item} staff={staff} />}
                            </article>
                        ))}
                    </div>
                </section>
                {mode === 'workspace' && (
                    <>
                        <section className="surface p-4 sm:p-6">
                            <h2 className="text-lg font-semibold">Past confirmed visits without attendance</h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Opening a case creates assigned welfare and replacement work. Verify the operational facts first.
                            </p>
                            <div className="mt-4 space-y-4">
                                {missableVisits.length === 0 && <p className="text-muted-foreground text-sm">No eligible visits.</p>}
                                {missableVisits.map((visit) => (
                                    <article className="rounded-xl border p-4" key={visit.id}>
                                        <h3 className="font-medium">
                                            {visit.patient.name} · {visit.service}
                                        </h3>
                                        <p className="text-muted-foreground text-xs">
                                            {date(visit.starts_at)} · {visit.provider?.name}
                                        </p>
                                        <MarkMissed visit={visit} staff={staff} />
                                    </article>
                                ))}
                            </div>
                        </section>
                        <section className="surface p-4 sm:p-6">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">Missed-visit cases</h2>
                                    <p className="text-muted-foreground text-sm">
                                        Replacement visits are offered from the appointments page and linked to the open case.
                                    </p>
                                </div>
                                <Button asChild variant="outline">
                                    <Link href="/workspace/appointments">Offer replacement appointment</Link>
                                </Button>
                            </div>
                            <div className="mt-4 space-y-4">
                                {cases.length === 0 && <p className="text-muted-foreground text-sm">No missed-visit cases.</p>}
                                {cases.map((record) => (
                                    <article className="rounded-xl border p-4" key={record.id}>
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <h3 className="font-medium">
                                                Case #{record.id} · {record.patient.name}
                                            </h3>
                                            <Badge variant={record.status === 'resolved' ? 'success' : 'warning'}>{title(record.status)}</Badge>
                                        </div>
                                        <p className="mt-2 text-sm">{record.missed_reason}</p>
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            Owner {record.owner.name} · backup {record.backup.name} · due {date(record.due_at)}
                                        </p>
                                        <p className="mt-2 text-sm">
                                            Welfare: {title(record.welfare_status)} · replacement: {title(record.replacement_status)}
                                        </p>
                                        {record.replacement && (
                                            <p className="text-muted-foreground text-xs">
                                                Replacement #{record.replacement.id}: {date(record.replacement.starts_at)} ·{' '}
                                                {title(record.replacement.offer_status)} / {title(record.replacement.status)}
                                            </p>
                                        )}
                                        {record.status === 'open' && <CaseActions record={record} />}
                                    </article>
                                ))}
                            </div>
                        </section>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
