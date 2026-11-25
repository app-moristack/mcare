import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { date, Errors } from '@/pages/patients/fields';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export type IncidentPerson = { id: number; name: string };
export type IncidentVisit = { id: number; service: string; starts_at: string };
export type IncidentStatus = 'reported' | 'assigned' | 'resolved';
export type VisitIncident = { id: number; status: IncidentStatus; reported_at: string; resolved_at: string | null };

export const incidentStatus: Record<IncidentStatus, string> = {
    reported: 'Awaiting assignment',
    assigned: 'Assigned for review',
    resolved: 'Resolved',
};

export const incidentCategories = {
    service_delivery: 'Service delivery',
    safety: 'Safety',
    privacy: 'Privacy',
    other: 'Other',
};

export const incidentDialogClass = 'max-h-[85dvh] w-[calc(100%-1.5rem)] overflow-y-auto sm:max-w-xl';
export const incidentControlClass = 'border-input bg-background w-full min-w-0 rounded-md border px-3 py-2 text-base';

export default function IncidentReport({
    visitId,
    visits = [],
    reviewers = [],
    currentUserId,
    onClose,
}: {
    visitId?: number;
    visits?: IncidentVisit[];
    reviewers?: IncidentPerson[];
    currentUserId?: number;
    onClose: () => void;
}) {
    const [requestId] = useState(() => crypto.randomUUID());
    const form = useForm({
        request_id: requestId,
        category: '',
        severity: 'routine',
        description: '',
        visit_id: '',
        reviewer_id: '',
    });
    const staffReport = visitId === undefined;

    return (
        <Dialog open onOpenChange={(open) => !open && !form.processing && onClose()}>
            <DialogContent className={incidentDialogClass}>
                <DialogTitle>Report an operational incident</DialogTitle>
                <DialogDescription>
                    This records an incident for review in MauriCare. If anyone is in immediate danger, follow your emergency procedure now.
                </DialogDescription>
                <form
                    className="min-w-0 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform(({ visit_id, reviewer_id, ...report }) => (staffReport ? { ...report, visit_id, reviewer_id } : report));
                        form.post(staffReport ? '/workspace/incidents' : `/my-visits/${visitId}/incidents`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <Errors errors={form.errors} />
                    {staffReport && (
                        <>
                            <label className="block min-w-0 space-y-1 text-sm font-medium">
                                <span>Related visit (optional)</span>
                                <select
                                    className={incidentControlClass}
                                    value={form.data.visit_id}
                                    onChange={(event) => form.setData('visit_id', event.target.value)}
                                >
                                    <option value="">No linked visit</option>
                                    {visits.map((visit) => (
                                        <option key={visit.id} value={visit.id}>
                                            #{visit.id} · {visit.service} · {date(visit.starts_at)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block min-w-0 space-y-1 text-sm font-medium">
                                <span>Independent reviewer</span>
                                <select
                                    className={incidentControlClass}
                                    required
                                    value={form.data.reviewer_id}
                                    onChange={(event) => form.setData('reviewer_id', event.target.value)}
                                >
                                    <option value="" disabled>
                                        Select a reviewer
                                    </option>
                                    {reviewers
                                        .filter((reviewer) => reviewer.id !== currentUserId)
                                        .map((reviewer) => (
                                            <option key={reviewer.id} value={reviewer.id}>
                                                {reviewer.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                        </>
                    )}
                    <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                        <label className="block min-w-0 space-y-1 text-sm font-medium">
                            <span>Category</span>
                            <select
                                required
                                className={incidentControlClass}
                                value={form.data.category}
                                onChange={(event) => form.setData('category', event.target.value)}
                            >
                                <option value="" disabled>
                                    Select a category
                                </option>
                                {Object.entries(incidentCategories).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block min-w-0 space-y-1 text-sm font-medium">
                            <span>Review priority</span>
                            <select
                                required
                                className={incidentControlClass}
                                value={form.data.severity}
                                onChange={(event) => form.setData('severity', event.target.value)}
                            >
                                <option value="routine">Routine</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </label>
                    </div>
                    <label className="block min-w-0 space-y-1 text-sm font-medium">
                        <span>What happened?</span>
                        <textarea
                            className={incidentControlClass}
                            rows={5}
                            required
                            maxLength={4000}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </label>
                    <p className="text-muted-foreground text-sm">Include only the information needed to review the incident.</p>
                    <Button type="submit" disabled={form.processing}>
                        Record incident
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
