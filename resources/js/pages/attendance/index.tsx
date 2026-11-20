import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Named = { id: number; name: string };
type Correction = {
    id: number;
    version: number;
    proposed_occurred_at: string;
    reason: string;
    evidence: string;
    status: string;
    review_evidence: string | null;
    requester: Named;
    reviewer: Named | null;
};
type Event = {
    id: number;
    kind: string;
    occurred_at: string;
    received_at: string;
    capture_status: string;
    exception_reason: string;
    review: { decision: string; evidence: string; reviewed_at: string; reviewer: Named } | null;
    corrections: Correction[];
    appointment: { id: number; service: string; patient: Named & { reference: string }; provider: Named };
};
const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const date = (value: string) => new Date(value).toLocaleString('en-MU', { timeZone: 'Indian/Mauritius', dateStyle: 'medium', timeStyle: 'short' });
function Errors({ values }: { values: Record<string, string> }) {
    if (!Object.keys(values).length) return null;
    return (
        <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-xl border p-3 text-sm" role="alert">
            <p className="font-medium">Please correct this submission.</p>
            <ul className="mt-1 list-disc pl-5">
                {Object.entries(values).map(([key, value]) => (
                    <li key={key}>{value}</li>
                ))}
            </ul>
        </div>
    );
}
function EventReview({ event }: { event: Event }) {
    const form = useForm({ request_id: crypto.randomUUID(), decision: 'confirmed_exception', evidence: '' });
    return (
        <form
            className="mt-4 space-y-3 rounded-xl border p-3"
            onSubmit={(submit: FormEvent) => {
                submit.preventDefault();
                form.post(`/workspace/attendance-events/${event.id}/review`, { preserveScroll: true });
            }}
        >
            <p className="text-sm font-medium">Independent exception review</p>
            <select className="w-full" value={form.data.decision} onChange={(change) => form.setData('decision', change.target.value)}>
                <option value="confirmed_exception">Confirm evidenced exception</option>
                <option value="correction_required">Require provider correction</option>
            </select>
            <textarea
                className="w-full"
                rows={3}
                required
                maxLength={3000}
                placeholder="Evidence checked and decision basis *"
                value={form.data.evidence}
                onChange={(change) => form.setData('evidence', change.target.value)}
            />
            <Errors values={form.errors} />
            <Button disabled={form.processing}>{form.processing ? 'Recording…' : 'Record review'}</Button>
        </form>
    );
}
function CorrectionReview({ correction }: { correction: Correction }) {
    const form = useForm({ version: correction.version, decision: 'approve', review_evidence: '' });
    return (
        <form
            className="mt-3 space-y-3 rounded-xl border p-3"
            onSubmit={(submit: FormEvent) => {
                submit.preventDefault();
                form.post(`/workspace/attendance-corrections/${correction.id}/review`, { preserveScroll: true });
            }}
        >
            <p className="text-sm font-medium">Review proposed effective time: {date(correction.proposed_occurred_at)}</p>
            <select className="w-full" value={form.data.decision} onChange={(change) => form.setData('decision', change.target.value)}>
                <option value="approve">Approve correction</option>
                <option value="reject">Reject correction</option>
            </select>
            <textarea
                className="w-full"
                rows={3}
                required
                maxLength={3000}
                placeholder="Independent review evidence *"
                value={form.data.review_evidence}
                onChange={(change) => form.setData('review_evidence', change.target.value)}
            />
            <Errors values={form.errors} />
            <Button disabled={form.processing}>{form.processing ? 'Recording…' : 'Record correction decision'}</Button>
        </form>
    );
}
export default function AttendanceReview({ events }: { events: Event[] }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Attendance review', href: '/workspace/attendance-review' }]}>
            <Head title="Attendance review" />
            <main className="mx-auto w-full max-w-6xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface space-y-2 p-4 sm:p-6">
                    <p className="text-muted-foreground text-xs">Care operations</p>
                    <h1 className="text-2xl font-semibold">Attendance exceptions and corrections</h1>
                    <p className="text-muted-foreground text-sm">
                        Source events are immutable. Confirm a real location exception or require a provider correction; approved corrections become
                        the effective time without replacing the source record.
                    </p>
                </header>
                <section className="grid gap-4 lg:grid-cols-2">
                    {events.length === 0 && <div className="surface text-muted-foreground p-6 text-sm">No location exceptions require display.</div>}
                    {events.map((event) => (
                        <article className="surface min-w-0 p-4 sm:p-6" key={event.id}>
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <h2 className="font-semibold break-words">
                                        {event.appointment.patient.name} · {title(event.kind)}
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        Visit #{event.appointment.id} · {event.appointment.provider.name} · {event.appointment.service}
                                    </p>
                                </div>
                                <Badge
                                    variant={event.review?.decision === 'confirmed_exception' ? 'success' : event.review ? 'warning' : 'secondary'}
                                >
                                    {event.review ? title(event.review.decision) : 'Awaiting review'}
                                </Badge>
                            </div>
                            <p className="mt-3 text-sm">
                                Source time: {date(event.occurred_at)} · location {title(event.capture_status)}
                            </p>
                            <p className="mt-2 text-sm break-words">Exception: {event.exception_reason}</p>
                            {event.review ? (
                                <p className="text-muted-foreground mt-3 text-xs">
                                    Reviewed by {event.review.reviewer.name}: {event.review.evidence}
                                </p>
                            ) : (
                                <EventReview event={event} />
                            )}
                            <div className="mt-4 space-y-3">
                                {event.corrections.map((correction) => (
                                    <div className="rounded-xl border p-3" key={correction.id}>
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <p className="text-sm font-medium">Correction by {correction.requester.name}</p>
                                            <Badge
                                                variant={
                                                    correction.status === 'approved'
                                                        ? 'success'
                                                        : correction.status === 'rejected'
                                                          ? 'warning'
                                                          : 'secondary'
                                                }
                                            >
                                                {title(correction.status)}
                                            </Badge>
                                        </div>
                                        <p className="mt-2 text-sm">Proposed: {date(correction.proposed_occurred_at)}</p>
                                        <p className="mt-1 text-xs break-words">Reason: {correction.reason}</p>
                                        <p className="mt-1 text-xs break-words">Evidence: {correction.evidence}</p>
                                        {correction.review_evidence && (
                                            <p className="text-muted-foreground mt-2 text-xs">Review: {correction.review_evidence}</p>
                                        )}
                                        {correction.status === 'pending' && <CorrectionReview correction={correction} />}
                                    </div>
                                ))}
                            </div>
                        </article>
                    ))}
                </section>
            </main>
        </AppLayout>
    );
}
