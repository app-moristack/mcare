import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { date, Errors } from '@/pages/patients/fields';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import IncidentReport, {
    incidentCategories,
    incidentControlClass,
    incidentDialogClass,
    type IncidentPerson,
    incidentStatus,
    type IncidentVisit,
    type VisitIncident,
} from './report';

type Reassignment = {
    id: number;
    previous_reviewer: IncidentPerson;
    reviewer: IncidentPerson;
    changed_by: IncidentPerson;
    reason: string;
    changed_at: string;
};

type Incident = VisitIncident & {
    category: keyof typeof incidentCategories;
    severity: 'routine' | 'urgent';
    visit_id: number | null;
    reporter: IncidentPerson;
    reviewer: IncidentPerson | null;
    description: string;
    resolution: string | null;
    reassignments?: Reassignment[];
    version: number;
};

type IncidentPage = { data: Incident[]; links: { url: string | null; label: string; active: boolean }[] };
type Action = { incident: Incident; mode: 'assign' | 'reassign' | 'resolve' };
const base = '/workspace/incidents';

const reviewActions = {
    assign: { label: 'Assign incident reviewer', submit: 'Assign reviewer' },
    reassign: { label: 'Reassign incident reviewer', submit: 'Sign and reassign' },
    resolve: { label: 'Resolve incident', submit: 'Sign and resolve' },
};

function ReviewDialog({ action, reviewers, onClose }: { action: Action; reviewers: IncidentPerson[]; onClose: () => void }) {
    const { incident, mode } = action;
    const assigning = mode === 'assign';
    const reassigning = mode === 'reassign';
    const signing = !assigning;
    const explanation = reassigning ? 'reason' : 'resolution';
    const form = useForm({
        version: incident.version,
        reviewer_id: '',
        resolution: '',
        reason: '',
        password: '',
        declaration: false as boolean,
    });
    return (
        <Dialog open onOpenChange={(open) => !open && !form.processing && onClose()}>
            <DialogContent className={incidentDialogClass}>
                <DialogTitle>{reviewActions[mode].label}</DialogTitle>
                <DialogDescription className="break-words">
                    Incident #{incident.id} · {incidentCategories[incident.category]}
                    {reassigning && incident.reviewer ? ` · Current reviewer ${incident.reviewer.name}` : ''}
                </DialogDescription>
                <form
                    className="min-w-0 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform(({ version, reviewer_id, reason, resolution, password, declaration }) => {
                            if (assigning) return { version, reviewer_id };
                            const signature = { password, declaration };
                            return reassigning ? { version, reviewer_id, reason, ...signature } : { version, resolution, ...signature };
                        });
                        form.post(`${base}/${incident.id}/${mode}`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                            onFinish: () => form.setData('password', ''),
                        });
                    }}
                >
                    <Errors errors={form.errors} />
                    {mode !== 'resolve' && (
                        <label className="block min-w-0 space-y-1 text-sm font-medium">
                            <span>{reassigning ? 'New independent reviewer' : 'Independent reviewer'}</span>
                            <select
                                className={incidentControlClass}
                                required
                                value={form.data.reviewer_id}
                                onChange={(event) => form.setData('reviewer_id', event.target.value)}
                            >
                                <option disabled value="">
                                    Select a reviewer
                                </option>
                                {reviewers.map((reviewer) => (
                                    <option key={reviewer.id} value={reviewer.id}>
                                        {reviewer.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {signing && (
                        <>
                            <label className="block min-w-0 space-y-1 text-sm font-medium">
                                <span>{reassigning ? 'Reason for reassignment' : 'Resolution and action taken'}</span>
                                <textarea
                                    className={incidentControlClass}
                                    rows={5}
                                    required
                                    maxLength={4000}
                                    value={form.data[explanation]}
                                    onChange={(event) => form.setData(explanation, event.target.value)}
                                />
                            </label>
                            <label className="block space-y-1 text-sm font-medium">
                                <span>Current password</span>
                                <Input
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                />
                            </label>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    className="mt-1"
                                    type="checkbox"
                                    required
                                    checked={form.data.declaration}
                                    onChange={(event) => form.setData('declaration', event.target.checked)}
                                />
                                <span>
                                    {reassigning
                                        ? 'I confirm the new reviewer and the reason for this change.'
                                        : 'I reviewed this incident and confirm the recorded resolution.'}
                                </span>
                            </label>
                        </>
                    )}
                    <Button type="submit" disabled={form.processing}>
                        {reviewActions[mode].submit}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Incidents({
    incidents,
    reviewers,
    visits,
    currentUserId,
}: {
    incidents: IncidentPage;
    reviewers: IncidentPerson[];
    visits: IncidentVisit[];
    currentUserId: number;
}) {
    const [reporting, setReporting] = useState(false);
    const [action, setAction] = useState<Action | null>(null);
    const canReport = reviewers.some((reviewer) => reviewer.id !== currentUserId);

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: base }]}>
            <Head title="Operational incidents" />
            <main className="mx-auto w-full max-w-5xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface flex min-w-0 flex-wrap items-start justify-between gap-3 p-4 sm:p-6">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">Operational incidents</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Assign reports for independent review and record their resolution.</p>
                    </div>
                    <Button onClick={() => setReporting(true)} disabled={!canReport}>
                        Report incident
                    </Button>
                    {!canReport && <p className="text-muted-foreground text-sm">An independent reviewer is required to report an incident.</p>}
                </header>
                {!incidents.data.length && <p className="surface text-muted-foreground p-4 text-sm sm:p-6">No incidents reported.</p>}
                {incidents.data.map((incident) => {
                    const eligibleReviewers = reviewers.filter((reviewer) => reviewer.id !== incident.reporter.id);
                    const canReassign = incident.status === 'assigned' && eligibleReviewers.some((reviewer) => reviewer.id !== incident.reviewer?.id);
                    return (
                        <article className="surface min-w-0 space-y-3 p-4 sm:p-6" key={incident.id}>
                            <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
                                <h2 className="text-lg font-semibold break-words">
                                    Incident #{incident.id} · {incidentCategories[incident.category]}
                                </h2>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant={incident.severity === 'urgent' ? 'warning' : 'secondary'}>
                                        {incident.severity === 'urgent' ? 'Urgent' : 'Routine'}
                                    </Badge>
                                    <Badge variant={incident.status === 'resolved' ? 'success' : 'secondary'}>
                                        {incidentStatus[incident.status]}
                                    </Badge>
                                </div>
                            </div>
                            <dl className="grid min-w-0 gap-3 text-sm sm:grid-cols-2">
                                <div className="min-w-0">
                                    <dt className="text-muted-foreground">Reported by</dt>
                                    <dd className="break-words">
                                        {incident.reporter.name} · {date(incident.reported_at)}
                                    </dd>
                                </div>
                                <div className="min-w-0">
                                    <dt className="text-muted-foreground">Independent reviewer</dt>
                                    <dd className="break-words">{incident.reviewer?.name ?? 'Awaiting assignment'}</dd>
                                </div>
                                {incident.visit_id && (
                                    <div>
                                        <dt className="text-muted-foreground">Related visit</dt>
                                        <dd>#{incident.visit_id}</dd>
                                    </div>
                                )}
                            </dl>
                            <details className="min-w-0 space-y-3 text-sm">
                                <summary className="cursor-pointer font-medium">Read report{incident.resolved_at ? ' and resolution' : ''}</summary>
                                <p className="break-words whitespace-pre-wrap">{incident.description}</p>
                                {incident.resolved_at && (
                                    <div className="space-y-1">
                                        <h3 className="font-medium">Resolved {date(incident.resolved_at)}</h3>
                                        <p className="break-words whitespace-pre-wrap">{incident.resolution}</p>
                                    </div>
                                )}
                            </details>
                            {!!incident.reassignments?.length && (
                                <details className="min-w-0 space-y-3 text-sm">
                                    <summary className="cursor-pointer font-medium">Reviewer change history</summary>
                                    <ol className="space-y-3">
                                        {incident.reassignments.map((change) => (
                                            <li className="min-w-0 space-y-1 rounded-md border p-3" key={change.id}>
                                                <p className="font-medium break-words">
                                                    {change.previous_reviewer.name} → {change.reviewer.name}
                                                </p>
                                                <p className="text-muted-foreground break-words">
                                                    Changed by {change.changed_by.name} ·{' '}
                                                    <time dateTime={change.changed_at}>{date(change.changed_at)}</time>
                                                </p>
                                                <p className="break-words whitespace-pre-wrap">{change.reason}</p>
                                            </li>
                                        ))}
                                    </ol>
                                </details>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {incident.status === 'reported' && (
                                    <Button
                                        variant="outline"
                                        disabled={eligibleReviewers.length === 0}
                                        onClick={() => setAction({ incident, mode: 'assign' })}
                                    >
                                        Assign reviewer
                                    </Button>
                                )}
                                {canReassign && (
                                    <Button variant="outline" onClick={() => setAction({ incident, mode: 'reassign' })}>
                                        Reassign reviewer
                                    </Button>
                                )}
                                {incident.status === 'assigned' && incident.reviewer?.id === currentUserId && (
                                    <Button variant="outline" onClick={() => setAction({ incident, mode: 'resolve' })}>
                                        Resolve incident
                                    </Button>
                                )}
                            </div>
                        </article>
                    );
                })}
                {incidents.links.length > 3 && (
                    <nav className="flex flex-wrap justify-end gap-2" aria-label="Incidents pages">
                        {incidents.links
                            .filter((link) => link.url)
                            .map((link) => (
                                <Button asChild key={link.label} variant={link.active ? 'default' : 'outline'} size="sm">
                                    <Link href={link.url!} aria-current={link.active ? 'page' : undefined}>
                                        {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                                    </Link>
                                </Button>
                            ))}
                    </nav>
                )}
                {reporting && (
                    <IncidentReport visits={visits} reviewers={reviewers} currentUserId={currentUserId} onClose={() => setReporting(false)} />
                )}
                {action && (
                    <ReviewDialog
                        action={action}
                        reviewers={reviewers.filter(
                            (reviewer) =>
                                reviewer.id !== action.incident.reporter.id &&
                                (action.mode !== 'reassign' || reviewer.id !== action.incident.reviewer?.id),
                        )}
                        onClose={() => setAction(null)}
                    />
                )}
            </main>
        </AppLayout>
    );
}
