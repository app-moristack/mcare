import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, CalendarDays, ClipboardList, ListTodo, Plus, Search, ShieldCheck, Users } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Named = { id: number; name: string };
type RecordRow = {
    id: number;
    version: number;
    status: string;
    name?: string;
    reference?: string;
    number?: string;
    title?: string;
    patient?: Named;
    provider?: Named;
    owner?: Named;
    backup?: Named;
    starts_at?: string;
    ends_at?: string;
    due_at?: string;
    due_date?: string;
    priority?: string;
    discipline?: string;
    source?: string;
    reason?: string;
    description?: string;
    total_minor?: number;
    payments?: { amount_minor: number }[];
    items?: { id: number; description: string; quantity: number; total_minor: number }[];
    [key: string]: unknown;
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
type Application = {
    id: number;
    status: string;
    discipline: string;
    credential_reference: string;
    credential_expires_at: string;
    user: Named & { email: string; mfa_confirmed_at: string | null };
};
type Member = { id: number; user_id: number; name: string; email: string; role: string; status: string; ends_at: string | null };
type Field = { key: string; label: string; type?: string; choices?: string; optional?: boolean };
const schemas: Record<string, Field[]> = {
    patients: [
        { key: 'name', label: 'Patient legal name' },
        { key: 'date_of_birth', label: 'Date of birth', type: 'date' },
        { key: 'phone', label: 'Phone (E.164)', optional: true },
        { key: 'email', label: 'Email', type: 'email', optional: true },
        { key: 'locality', label: 'Town / locality' },
        { key: 'address', label: 'Service address', type: 'textarea', optional: true },
        { key: 'authority_type', label: 'Coordination authority', choices: 'patient_request,representative,referral_authority' },
        { key: 'authority_evidence', label: 'Authority evidence / source', type: 'textarea' },
    ],
    referrals: [
        { key: 'patient_id', label: 'Patient', choices: 'patients' },
        { key: 'source', label: 'Source', choices: 'coordinator,clinic,patient,professional' },
        { key: 'reason', label: 'Requested help / referral summary', type: 'textarea' },
        { key: 'discipline', label: 'Requested discipline', choices: 'doctor,nurse,carer,physiotherapist' },
        { key: 'priority', label: 'Requested priority', choices: 'unknown,routine,urgent' },
        { key: 'owner_id', label: 'Accountable owner', choices: 'staff' },
        { key: 'backup_id', label: 'Backup owner', choices: 'staff' },
        { key: 'due_at', label: 'Action deadline (Mauritius)', type: 'datetime-local' },
    ],
    appointments: [
        { key: 'patient_id', label: 'Patient', choices: 'patients' },
        { key: 'referral_id', label: 'Triaged referral', choices: 'referrals' },
        { key: 'provider_id', label: 'Approved provider', choices: 'providers' },
        { key: 'service', label: 'Service / visit type' },
        { key: 'starts_at', label: 'Start (Mauritius)', type: 'datetime-local' },
        { key: 'ends_at', label: 'End (Mauritius)', type: 'datetime-local' },
        { key: 'location_type', label: 'Location type', choices: 'home,clinic' },
        { key: 'location', label: 'Visit address / location', type: 'textarea' },
    ],
    tasks: [
        { key: 'patient_id', label: 'Patient (optional)', choices: 'patients', optional: true },
        { key: 'title', label: 'Actionable title' },
        { key: 'description', label: 'Action required (minimum necessary information)', type: 'textarea' },
        { key: 'priority', label: 'Priority', choices: 'routine,high,critical' },
        { key: 'owner_id', label: 'Owner', choices: 'staff' },
        { key: 'backup_id', label: 'Backup', choices: 'staff' },
        { key: 'due_at', label: 'Due (Mauritius)', type: 'datetime-local' },
    ],
    invoices: [
        { key: 'patient_id', label: 'Patient', choices: 'patients' },
        { key: 'due_date', label: 'Due date', type: 'date' },
    ],
};
const paths: Record<string, Record<string, string[]>> = {
    patients: { active: ['inactive'], inactive: ['active'] },
    referrals: {
        received: ['review', 'cancelled'],
        review: ['awaiting_consent', 'awaiting_match', 'cancelled'],
        awaiting_consent: ['awaiting_match', 'cancelled'],
        awaiting_match: ['assessment_booked', 'cancelled'],
        assessment_booked: ['review', 'cancelled'],
    },
    appointments: { booked: ['confirmed', 'cancelled'], confirmed: ['cancelled'], arrived: ['completed', 'aborted'] },
    tasks: {
        new: ['acknowledged', 'cancelled'],
        acknowledged: ['in_progress', 'waiting', 'cancelled'],
        in_progress: ['waiting', 'completed', 'cancelled'],
        waiting: ['in_progress', 'cancelled'],
        completed: ['reopened'],
        reopened: ['acknowledged', 'cancelled'],
    },
    invoices: { draft: ['issued', 'void'], issued: ['void'] },
};
const title = (text: string) => text.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const date = (value: unknown) =>
    value ? new Date(String(value)).toLocaleString('en-GB', { timeZone: 'Indian/Mauritius', dateStyle: 'medium', timeStyle: 'short' }) : '—';
const money = (n: number) => new Intl.NumberFormat('en-MU', { style: 'currency', currency: 'MUR' }).format(n / 100);
function Status({ value }: { value: string }) {
    return (
        <Badge
            variant={
                ['active', 'approved', 'completed', 'paid', 'confirmed'].includes(value)
                    ? 'success'
                    : ['pending', 'waiting', 'part_paid', 'urgent', 'critical'].includes(value)
                      ? 'warning'
                      : 'secondary'
            }
        >
            {title(value)}
        </Badge>
    );
}
function FieldInput({ field, value, onChange, options }: { field: Field; value: string; onChange: (value: string) => void; options: Named[] }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={field.key}>{field.label}</Label>
            {field.choices ? (
                <select id={field.key} required={!field.optional} value={value} onChange={(e) => onChange(e.target.value)}>
                    <option value="">Select…</option>
                    {options.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.name}
                        </option>
                    ))}
                    {options.length === 0 &&
                        !['staff', 'patients', 'providers', 'referrals'].includes(field.choices) &&
                        field.choices.split(',').map((o) => (
                            <option key={o} value={o}>
                                {title(o)}
                            </option>
                        ))}
                </select>
            ) : field.type === 'textarea' ? (
                <textarea
                    id={field.key}
                    className="bg-card focus-visible:outline-primary min-h-24 w-full rounded-md border px-3 py-2"
                    required={!field.optional}
                    maxLength={5000}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            ) : (
                <Input
                    id={field.key}
                    required={!field.optional}
                    type={field.type ?? 'text'}
                    step={field.key === 'amount' ? '0.01' : undefined}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
        </div>
    );
}
function RecordForm({
    module,
    row,
    lookups,
    staff,
    onClose,
    payment = false,
    editDetails = false,
}: {
    module: string;
    row?: RecordRow;
    lookups: Record<string, Named[]>;
    staff: Named[];
    onClose: () => void;
    payment?: boolean;
    editDetails?: boolean;
}) {
    const [values, setValues] = useState<Record<string, string>>(
        editDetails && row
            ? Object.fromEntries(schemas.patients.map((f) => [f.key, String(row[f.key] ?? '').slice(0, f.type === 'date' ? 10 : 5000)]))
            : {},
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [requestId] = useState(() => crypto.randomUUID());
    const [items, setItems] = useState([{ description: '', quantity: '1', unit_price_minor: '' }]);
    const targets = row ? (paths[module]?.[row.status] ?? []) : [];
    const fields: Field[] = editDetails
        ? schemas.patients
        : payment
          ? [
                { key: 'amount', label: 'Reconciled amount (MUR)', type: 'number' },
                { key: 'reference', label: 'Bank / receipt reference' },
                { key: 'evidence', label: 'Reconciliation evidence', type: 'textarea' },
            ]
          : row
            ? [
                  { key: 'status', label: 'Next status', choices: targets.join(',') },
                  ...(module === 'appointments' && values.status === 'confirmed'
                      ? [{ key: 'confirmation_evidence', label: 'Patient confirmation and provider acceptance evidence', type: 'textarea' }]
                      : []),
                  ...(module === 'referrals' && values.status === 'awaiting_match'
                      ? [
                            { key: 'priority', label: 'Triaged priority', choices: 'routine,urgent' },
                            { key: 'triage_evidence', label: 'Clinical triage rationale', type: 'textarea' },
                        ]
                      : []),
                  ...(module === 'referrals' && values.status === 'cancelled'
                      ? [{ key: 'disposition', label: 'Cancellation / redirection reason', type: 'textarea' }]
                      : []),
                  ...(module === 'tasks' && values.status === 'waiting'
                      ? [
                            { key: 'waiting_reason', label: 'Waiting reason', type: 'textarea' },
                            { key: 'next_check_at', label: 'Next check (Mauritius)', type: 'datetime-local' },
                        ]
                      : []),
                  ...((['completed', 'cancelled', 'reopened'].includes(values.status) && module === 'tasks') ||
                  (values.status === 'completed' && module === 'appointments')
                      ? [{ key: 'completion_evidence', label: 'Action / completion evidence', type: 'textarea' }]
                      : []),
                  ...(['cancelled', 'aborted'].includes(values.status) && module === 'appointments'
                      ? [{ key: 'cancellation_reason', label: 'Cancellation / abort reason', type: 'textarea' }]
                      : []),
                  ...(values.status === 'void' ? [{ key: 'void_reason', label: 'Void reason', type: 'textarea' }] : []),
              ]
            : schemas[module];
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        const payload = {
            ...values,
            ...(editDetails ? { action: 'details' } : {}),
            ...(payment ? { amount_minor: Math.round(Number(values.amount) * 100) } : {}),
            request_id: requestId,
            ...(row ? { version: row.version } : {}),
            ...(!row && module === 'invoices'
                ? {
                      items: items.map((i) => ({
                          ...i,
                          quantity: Number(i.quantity),
                          unit_price_minor: Math.round(Number(i.unit_price_minor) * 100),
                      })),
                  }
                : {}),
        };
        const opts = {
            preserveScroll: true,
            onError: (v: Record<string, string>) => setErrors(v),
            onSuccess: onClose,
            onFinish: () => setBusy(false),
        };
        if (payment) router.post(`/workspace/invoices/${row?.id}/payments`, payload, opts);
        else if (row) router.patch(`/workspace/${module}/${row.id}`, payload, opts);
        else router.post(`/workspace/${module}`, payload, opts);
    };
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] max-w-2xl overflow-y-auto">
                <DialogTitle>
                    {payment ? 'Reconcile payment' : row ? 'Update status' : `New ${module === 'patients' ? 'patient' : module.slice(0, -1)}`}
                </DialogTitle>
                <DialogDescription>
                    {payment
                        ? 'Record a verified settlement. This is a manual ledger entry, not a payment gateway.'
                        : 'All dates and times use Indian/Mauritius. Changes are attributable and audited.'}
                </DialogDescription>
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid gap-5 sm:grid-cols-2">
                        {fields.map((field) => (
                            <div key={field.key} className={field.type === 'textarea' ? 'sm:col-span-2' : ''}>
                                <FieldInput
                                    field={field}
                                    value={values[field.key] ?? ''}
                                    onChange={(v) => setValues({ ...values, [field.key]: v })}
                                    options={field.choices === 'staff' ? staff : (lookups[field.choices ?? ''] ?? [])}
                                />
                            </div>
                        ))}
                    </div>
                    {!row && module === 'invoices' && (
                        <div className="space-y-4">
                            <Label>Invoice items (unit prices in MUR)</Label>
                            {items.map((item, index) => (
                                <div className="grid gap-3 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto]" key={index}>
                                    <Input
                                        aria-label={`Item ${index + 1} description`}
                                        placeholder="Description"
                                        required
                                        value={item.description}
                                        onChange={(e) => setItems(items.map((v, i) => (i === index ? { ...v, description: e.target.value } : v)))}
                                    />
                                    <Input
                                        aria-label={`Item ${index + 1} quantity`}
                                        type="number"
                                        min={1}
                                        required
                                        value={item.quantity}
                                        onChange={(e) => setItems(items.map((v, i) => (i === index ? { ...v, quantity: e.target.value } : v)))}
                                    />
                                    <Input
                                        aria-label={`Item ${index + 1} unit price in MUR`}
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        required
                                        placeholder="MUR"
                                        value={item.unit_price_minor}
                                        onChange={(e) =>
                                            setItems(items.map((v, i) => (i === index ? { ...v, unit_price_minor: e.target.value } : v)))
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        aria-label={`Remove item ${index + 1}`}
                                        disabled={items.length === 1}
                                        onClick={() => setItems(items.filter((_, i) => i !== index))}
                                    >
                                        ×
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={items.length >= 30}
                                onClick={() => setItems([...items, { description: '', quantity: '1', unit_price_minor: '' }])}
                            >
                                Add item
                            </Button>
                        </div>
                    )}
                    {Object.keys(errors).length > 0 && (
                        <div role="alert" className="bg-destructive/10 text-destructive rounded-md p-3 text-sm">
                            {Object.entries(errors).map(([key, v]) => (
                                <p key={key}>{v}</p>
                            ))}
                        </div>
                    )}
                    <div className="flex justify-end gap-3">
                        <Button variant="outline" type="button" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={busy || (!!row && !payment && !editDetails && targets.length === 0)}>{busy ? 'Saving…' : 'Save'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
function Review({ application, onClose }: { application: Application; onClose: () => void }) {
    const [status, setStatus] = useState('approved');
    const [evidence, setEvidence] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogTitle>Review {application.user.name}</DialogTitle>
                <DialogDescription>
                    Confirm identity, credential source and scope through your own review. Current credentials and any enabled workforce security
                    checks are required for activation.
                </DialogDescription>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setBusy(true);
                        router.patch(
                            `/workspace/team/applications/${application.id}`,
                            { status, review_evidence: evidence },
                            { onSuccess: onClose, onError: (errors) => setError(Object.values(errors).join(' ')), onFinish: () => setBusy(false) },
                        );
                    }}
                >
                    <Label htmlFor="review-status">Decision</Label>
                    <select id="review-status" className="w-full" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="approved">Approve provider</option>
                        <option value="rejected">Reject application</option>
                    </select>
                    <Label htmlFor="review-evidence">Verification evidence and rationale</Label>
                    <textarea
                        id="review-evidence"
                        required
                        value={evidence}
                        onChange={(e) => setEvidence(e.target.value)}
                        className="min-h-32 w-full rounded-md border p-3"
                    />
                    {error && (
                        <p role="alert" className="text-destructive">
                            {error}
                        </p>
                    )}
                    <Button disabled={busy}>Record decision</Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
export default function Operations({
    module,
    rows,
    summary,
    staff,
    lookups,
    applications,
    members,
    events,
    filters,
    upcoming,
    overdue,
}: {
    module: string;
    rows: Page<RecordRow> | null;
    summary: Record<string, number> | null;
    staff: Named[];
    lookups: Record<string, Named[]>;
    applications: Page<Application> | null;
    members: Member[];
    events: Page<{ id: number; event: string; actor_name: string; created_at: string; resource_type: string; resource_id: number }> | null;
    filters: { search: string; status: string };
    upcoming: RecordRow[];
    overdue: RecordRow[];
}) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<RecordRow>();
    const [paying, setPaying] = useState<RecordRow>();
    const [addingMember, setAddingMember] = useState(false);
    const [memberValues, setMemberValues] = useState({ email: '', role: 'coordinator', approval_evidence: '' });
    const [memberError, setMemberError] = useState('');
    const [suspending, setSuspending] = useState<Member>();
    const [suspendError, setSuspendError] = useState('');
    const [viewing, setViewing] = useState<RecordRow>();
    const [editingDetails, setEditingDetails] = useState<RecordRow>();
    const [reviewing, setReviewing] = useState<Application>();
    const [search, setSearch] = useState(filters.search);
    const heading =
        module === 'overview'
            ? 'Command centre'
            : module === 'team'
              ? 'Team & credentials'
              : module === 'audit'
                ? 'Access & audit history'
                : title(module);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Workspace', href: '/workspace/overview' },
                { title: heading, href: `/workspace/${module}` },
            ]}
        >
            <Head title={heading} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{heading}</h1>
                        <p className="text-muted-foreground mt-2 text-sm">MauriCare operations · Mauritius time · MUR</p>
                    </div>
                    {schemas[module] && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus />
                            New {module === 'patients' ? 'patient' : module.slice(0, -1)}
                        </Button>
                    )}
                </div>
                {summary && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {[
                                [Users, 'Patients', summary.patients, 'patients'],
                                [ClipboardList, 'Open referrals', summary.referrals, 'referrals'],
                                [CalendarDays, 'Upcoming visits', summary.appointments, 'appointments'],
                                [ListTodo, 'Overdue tasks', summary.overdue_tasks, 'tasks'],
                            ].map(([Icon, label, count, target]) => {
                                const I = Icon as typeof Users;
                                return (
                                    <Link
                                        href={`/workspace/${String(target)}`}
                                        key={String(label)}
                                        className="surface p-5 transition-shadow hover:shadow-md"
                                    >
                                        <I className="text-primary size-5" />
                                        <h2 className="mt-4 text-sm font-medium">{String(label)}</h2>
                                        <p className="mt-3 text-3xl font-semibold">{String(count)}</p>
                                    </Link>
                                );
                            })}
                        </div>
                        <div className="grid gap-6 lg:grid-cols-2">
                            <section className="surface p-5">
                                <h2 className="text-lg font-semibold">Upcoming visits</h2>
                                <div className="mt-5 space-y-3">
                                    {upcoming.length ? (
                                        upcoming.map((row) => (
                                            <div key={row.id} className="flex items-center justify-between gap-3 rounded-md border p-4">
                                                <div className="min-w-0">
                                                    <p className="font-medium">{row.patient?.name}</p>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {date(row.starts_at)} · {row.provider?.name}
                                                    </p>
                                                </div>
                                                <Status value={row.status} />
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-muted-foreground py-8 text-sm">No upcoming visits. Start with a patient and referral.</p>
                                    )}
                                </div>
                                <Link className="text-primary mt-5 inline-flex items-center gap-2 text-sm" href="/workspace/appointments">
                                    Open appointments
                                    <ArrowRight className="size-4" />
                                </Link>
                            </section>
                            <section className="surface p-5">
                                <h2 className="text-lg font-semibold">Needs attention</h2>
                                <div className="mt-5 space-y-3">
                                    {overdue.length ? (
                                        overdue.map((row) => (
                                            <div key={row.id} className="rounded-md border p-4">
                                                <p className="font-medium">{row.title}</p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Due {date(row.due_at)} · {row.owner?.name}
                                                </p>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-muted-foreground py-8 text-sm">No overdue tasks.</p>
                                    )}
                                </div>
                                <Link className="text-primary mt-5 inline-flex items-center gap-2 text-sm" href="/workspace/tasks">
                                    Open task queue
                                    <ArrowRight className="size-4" />
                                </Link>
                            </section>
                        </div>
                    </>
                )}
                {rows && (
                    <section className="surface overflow-hidden">
                        <div className="flex flex-wrap items-center justify-between gap-4 p-5">
                            <h2 className="text-lg font-semibold">
                                {title(module)} <span className="text-muted-foreground text-sm font-normal">({rows.total})</span>
                            </h2>
                            <form
                                className="flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(`/workspace/${module}`, { search, status: filters.status }, { preserveState: true });
                                }}
                            >
                                <Input
                                    aria-label="Search by patient name"
                                    placeholder={module === 'patients' ? 'Search patients…' : 'Search patient name…'}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                                <Button variant="outline" size="icon" aria-label="Search">
                                    <Search />
                                </Button>
                                <select
                                    aria-label="Filter by status"
                                    value={filters.status}
                                    onChange={(e) => router.get(`/workspace/${module}`, { search, status: e.target.value }, { preserveState: true })}
                                >
                                    <option value="">All statuses</option>
                                    {Object.keys(paths[module] ?? {})
                                        .concat(
                                            module === 'invoices'
                                                ? ['part_paid', 'paid', 'void']
                                                : module === 'appointments'
                                                  ? ['completed', 'cancelled', 'aborted']
                                                  : module === 'tasks'
                                                    ? ['cancelled']
                                                    : [],
                                        )
                                        .filter((v, i, a) => a.indexOf(v) === i)
                                        .map((v) => (
                                            <option key={v} value={v}>
                                                {title(v)}
                                            </option>
                                        ))}
                                </select>
                            </form>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {module === 'patients'
                                            ? 'Patient'
                                            : module === 'tasks'
                                              ? 'Task'
                                              : module === 'invoices'
                                                ? 'Invoice'
                                                : 'Patient'}
                                    </TableHead>
                                    <TableHead>
                                        {module === 'patients'
                                            ? 'Date of birth'
                                            : module === 'referrals'
                                              ? 'Discipline / priority'
                                              : module === 'appointments'
                                                ? 'Time / provider'
                                                : module === 'tasks'
                                                  ? 'Owner / deadline'
                                                  : 'Amount / outstanding'}
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <p className="font-medium">{row.name ?? row.title ?? row.number ?? row.patient?.name}</p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {module === 'patients'
                                                    ? row.reference
                                                    : module === 'tasks'
                                                      ? row.patient?.name
                                                      : module === 'invoices'
                                                        ? row.patient?.name
                                                        : `#${row.id}`}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {module === 'patients' ? (
                                                String(row.date_of_birth).slice(0, 10)
                                            ) : module === 'referrals' ? (
                                                <>
                                                    {title(row.discipline ?? '')}
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {row.priority} · Due {date(row.due_at)}
                                                    </p>
                                                </>
                                            ) : module === 'appointments' ? (
                                                <>
                                                    {date(row.starts_at)}
                                                    <p className="text-muted-foreground mt-1 text-xs">{row.provider?.name}</p>
                                                </>
                                            ) : module === 'tasks' ? (
                                                <>
                                                    {row.owner?.name}
                                                    <p className="text-muted-foreground mt-1 text-xs">{date(row.due_at)}</p>
                                                </>
                                            ) : (
                                                <>
                                                    {money(row.total_minor ?? 0)}
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {money(
                                                            (row.total_minor ?? 0) - (row.payments ?? []).reduce((sum, p) => sum + p.amount_minor, 0),
                                                        )}{' '}
                                                        due
                                                    </p>
                                                </>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Status value={row.status} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button variant="ghost" size="sm" onClick={() => setViewing(row)}>
                                                    Details
                                                </Button>
                                                {module === 'patients' && (
                                                    <Button variant="outline" size="sm" onClick={() => setEditingDetails(row)}>
                                                        Edit details
                                                    </Button>
                                                )}
                                                {(paths[module]?.[row.status]?.length ?? 0) > 0 && (
                                                    <Button variant="outline" size="sm" onClick={() => setEditing(row)}>
                                                        Update status
                                                    </Button>
                                                )}
                                                {module === 'invoices' && ['issued', 'part_paid'].includes(row.status) && (
                                                    <Button size="sm" onClick={() => setPaying(row)}>
                                                        Reconcile
                                                    </Button>
                                                )}
                                            </div>
                                            {module === 'appointments' && (
                                                <p className="text-muted-foreground mt-2 text-xs">Attendance is recorded in My visits.</p>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!rows.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={4} className="text-muted-foreground py-14 text-center">
                                            No {module} found.{' '}
                                            {filters.search || filters.status
                                                ? 'Try clearing the filters.'
                                                : 'Create your first record to get started.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                        <Pagination links={rows.links} />
                    </section>
                )}
                {module === 'team' && (
                    <div>
                        <Button
                            onClick={() => {
                                setAddingMember(true);
                                setMemberError('');
                            }}
                        >
                            <Plus />
                            Add operational staff
                        </Button>
                        <p className="text-muted-foreground mt-2 text-sm">
                            Approve an existing verified account for coordination or finance. Clinical providers use the application and credential
                            review below.
                        </p>
                    </div>
                )}
                {addingMember && (
                    <Dialog open onOpenChange={(v) => !v && setAddingMember(false)}>
                        <DialogContent>
                            <DialogTitle>Add operational staff</DialogTitle>
                            <DialogDescription>
                                The staff member must register and verify their email first. Record your approval evidence and grant only the role
                                they need.
                            </DialogDescription>
                            <form
                                className="space-y-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post('/workspace/team/memberships', memberValues, {
                                        onSuccess: () => {
                                            setAddingMember(false);
                                            setMemberValues({ email: '', role: 'coordinator', approval_evidence: '' });
                                        },
                                        onError: (errors) => setMemberError(Object.values(errors).join(' ')),
                                    });
                                }}
                            >
                                <Label htmlFor="member-email">Verified account email</Label>
                                <Input
                                    id="member-email"
                                    type="email"
                                    required
                                    value={memberValues.email}
                                    onChange={(e) => setMemberValues({ ...memberValues, email: e.target.value })}
                                />
                                <Label htmlFor="member-role">Role</Label>
                                <select
                                    id="member-role"
                                    className="w-full"
                                    value={memberValues.role}
                                    onChange={(e) => setMemberValues({ ...memberValues, role: e.target.value })}
                                >
                                    {['coordinator', 'finance', 'operational_admin'].map((role) => (
                                        <option key={role} value={role}>
                                            {title(role)}
                                        </option>
                                    ))}
                                </select>
                                <Label htmlFor="member-evidence">Approval evidence and scope</Label>
                                <textarea
                                    id="member-evidence"
                                    className="min-h-24 w-full rounded border p-3"
                                    required
                                    value={memberValues.approval_evidence}
                                    onChange={(e) => setMemberValues({ ...memberValues, approval_evidence: e.target.value })}
                                />
                                {memberError && (
                                    <p role="alert" className="text-destructive">
                                        {memberError}
                                    </p>
                                )}
                                <Button>Approve membership</Button>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
                {module === 'team' && (
                    <>
                        <section className="surface p-5">
                            <h2 className="text-lg font-semibold">Professional applications</h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Only applications addressed to this organisation are visible. Review credential evidence before activation.
                            </p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Applicant</TableHead>
                                        <TableHead>Discipline / credential</TableHead>
                                        <TableHead>MFA / status</TableHead>
                                        <TableHead>Action</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {applications?.data.map((a) => (
                                        <TableRow key={a.id}>
                                            <TableCell>
                                                {a.user.name}
                                                <p className="text-muted-foreground text-xs">{a.user.email}</p>
                                            </TableCell>
                                            <TableCell>
                                                {a.discipline}
                                                <p className="text-muted-foreground text-xs">
                                                    {a.credential_reference} · Expires {a.credential_expires_at}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                <p className="mb-2 text-xs">MFA {a.user.mfa_confirmed_at ? 'enrolled' : 'not enrolled'}</p>
                                                <Status value={a.status} />
                                            </TableCell>
                                            <TableCell>
                                                {a.status === 'pending' && (
                                                    <Button size="sm" variant="outline" onClick={() => setReviewing(a)}>
                                                        Review
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {!applications?.data.length && (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-muted-foreground py-10 text-center">
                                                No applications submitted to this organisation.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                            {applications && <Pagination links={applications.links} />}
                        </section>
                        <section className="surface p-5">
                            <h2 className="text-lg font-semibold">Organisation memberships</h2>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Member</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Expires</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {members.map((m) => (
                                        <TableRow key={m.id}>
                                            <TableCell>
                                                {m.name}
                                                <p className="text-muted-foreground text-xs">{m.email}</p>
                                            </TableCell>
                                            <TableCell>{title(m.role)}</TableCell>
                                            <TableCell>
                                                <Status value={m.status} />
                                            </TableCell>
                                            <TableCell>
                                                {m.ends_at ? date(m.ends_at) : 'No fixed expiry'}
                                                {m.status === 'active' && (
                                                    <Button
                                                        className="ml-3"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => {
                                                            setSuspending(m);
                                                            setSuspendError('');
                                                        }}
                                                    >
                                                        Suspend role
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </section>
                    </>
                )}
                {module === 'audit' && events && (
                    <section className="surface p-5">
                        <h2 className="text-lg font-semibold">Attributable events</h2>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>Actor</TableHead>
                                    <TableHead>Action</TableHead>
                                    <TableHead>Resource</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {events.data.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell>{date(e.created_at)}</TableCell>
                                        <TableCell>{e.actor_name ?? 'System'}</TableCell>
                                        <TableCell>{e.event}</TableCell>
                                        <TableCell>{e.resource_type ? `${e.resource_type} #${e.resource_id}` : '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination links={events.links} />
                    </section>
                )}
                {suspending && (
                    <Dialog open onOpenChange={(v) => !v && setSuspending(undefined)}>
                        <DialogContent>
                            <DialogTitle>
                                Suspend {suspending.name}'s {title(suspending.role)} role?
                            </DialogTitle>
                            <DialogDescription>
                                This removes access through this membership immediately. Other active roles are separate. Review and transfer any
                                assigned visits or tasks before offboarding.
                            </DialogDescription>
                            {suspendError && (
                                <p role="alert" className="text-destructive">
                                    {suspendError}
                                </p>
                            )}
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    router.patch(
                                        `/workspace/team/memberships/${suspending.id}`,
                                        { status: 'suspended' },
                                        {
                                            onSuccess: () => setSuspending(undefined),
                                            onError: (errors) => setSuspendError(Object.values(errors).join(' ')),
                                        },
                                    )
                                }
                            >
                                Suspend role
                            </Button>
                        </DialogContent>
                    </Dialog>
                )}
                {viewing && (
                    <Dialog open onOpenChange={(open) => !open && setViewing(undefined)}>
                        <DialogContent className="max-h-[85svh] max-w-2xl overflow-y-auto">
                            <DialogTitle>
                                {title(module)} #{viewing.id}
                            </DialogTitle>
                            <DialogDescription>Current record details within this organisation.</DialogDescription>
                            <dl className="divide-y">
                                {Object.entries(viewing)
                                    .filter(
                                        ([key, value]) =>
                                            value !== null &&
                                            !['organisation_id', 'request_id', 'version', 'user_id', 'id'].includes(key) &&
                                            typeof value !== 'object',
                                    )
                                    .map(([key, value]) => (
                                        <div key={key} className="grid gap-1 py-3 sm:grid-cols-[10rem_1fr]">
                                            <dt className="text-muted-foreground text-sm">{title(key)}</dt>
                                            <dd className="text-sm break-words whitespace-pre-wrap">
                                                {key.endsWith('_at') ? date(value) : key === 'total_minor' ? money(Number(value)) : String(value)}
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                            {viewing.items && (
                                <div>
                                    <h3 className="mb-3 font-semibold">Invoice items</h3>
                                    {viewing.items.map((item) => (
                                        <p key={item.id} className="border-t py-3 text-sm">
                                            {item.quantity} × {item.description}
                                            <span className="float-right">{money(item.total_minor)}</span>
                                        </p>
                                    ))}
                                </div>
                            )}
                            {viewing.payments && (
                                <div>
                                    <h3 className="mb-3 font-semibold">Reconciled payments</h3>
                                    {viewing.payments.map((payment, index) => (
                                        <p key={index} className="border-t py-3 text-sm">
                                            {money(payment.amount_minor)}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </DialogContent>
                    </Dialog>
                )}
                {editingDetails && (
                    <RecordForm
                        module="patients"
                        row={editingDetails}
                        editDetails
                        lookups={lookups}
                        staff={staff}
                        onClose={() => setEditingDetails(undefined)}
                    />
                )}
                {creating && <RecordForm module={module} lookups={lookups} staff={staff} onClose={() => setCreating(false)} />}{' '}
                {editing && <RecordForm module={module} row={editing} lookups={lookups} staff={staff} onClose={() => setEditing(undefined)} />}{' '}
                {paying && <RecordForm module="invoices" row={paying} payment lookups={lookups} staff={staff} onClose={() => setPaying(undefined)} />}{' '}
                {reviewing && <Review application={reviewing} onClose={() => setReviewing(undefined)} />}
                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                    <ShieldCheck className="size-4" />
                    Organisation-scoped workspace · Refresh to see the latest state · Clinical chart access is separate.
                </p>
            </main>
        </AppLayout>
    );
}
function Pagination({ links }: { links: Page<never>['links'] }) {
    return (
        <nav aria-label="Pagination" className="flex flex-wrap justify-end gap-2 border-t p-4">
            {links.map((link, i) =>
                link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll
                        className={`rounded-md border px-3 py-1.5 text-sm ${link.active ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-muted'}`}
                    >
                        {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                    </Link>
                ) : (
                    <span key={i} className="text-muted-foreground px-3 py-1.5 text-sm">
                        {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                    </span>
                ),
            )}
        </nav>
    );
}
