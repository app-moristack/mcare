import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { date, Errors } from '@/pages/patients/fields';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type ChecklistResponse = { status: 'completed' | 'exception'; reason: string | null };
export type ChecklistOption = { id: number; title: string; version: number };

export type VisitChecklist = {
    id: number;
    checklist_version_id: number;
    title: string;
    version: number;
    steps: string[];
    attached_at: string;
    submitted_at: string | null;
    responses: ChecklistResponse[] | null;
    can_submit: boolean;
};

const dialogClass = 'max-h-[85dvh] w-[calc(100%-1.5rem)] overflow-y-auto sm:max-w-xl';
const inputClass = 'border-input bg-background w-full min-w-0 rounded-md border px-3 py-2 text-base';

export function AttachVisitChecklist({
    visitId,
    visitVersion,
    options,
    onClose,
}: {
    visitId: number;
    visitVersion: number;
    options: ChecklistOption[];
    onClose: () => void;
}) {
    const form = useForm({ request_id: crypto.randomUUID(), visit_version: visitVersion, checklist_version_id: '' });
    return (
        <Dialog open onOpenChange={(open) => !open && !form.processing && onClose()}>
            <DialogContent className={dialogClass}>
                <DialogTitle>Attach a visit checklist</DialogTitle>
                <DialogDescription>The visit retains the exact version you attach.</DialogDescription>
                <form
                    className="min-w-0 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/workspace/appointments/${visitId}/checklist`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <Errors errors={form.errors} />
                    <label className="block min-w-0 space-y-1 text-sm font-medium">
                        <span>Published checklist version</span>
                        <select
                            className={inputClass}
                            required
                            value={form.data.checklist_version_id}
                            onChange={(event) => form.setData('checklist_version_id', event.target.value)}
                        >
                            <option value="" disabled>
                                Select a checklist
                            </option>
                            {options.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.title} · Version {option.version}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit" disabled={form.processing}>
                        Attach checklist
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function VisitChecklistButton({
    checklist,
    visitId,
    visitVersion,
    provider = false,
}: {
    checklist: VisitChecklist;
    visitId: number;
    visitVersion: number;
    provider?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        request_id: '',
        visit_version: visitVersion,
        responses: [] as { status: '' | ChecklistResponse['status']; reason: string }[],
        password: '',
        declaration: false as boolean,
    });
    const canSubmit = provider && checklist.can_submit && !checklist.submitted_at;
    const changeResponse = (index: number, response: (typeof form.data.responses)[number]) => {
        form.setData(
            'responses',
            form.data.responses.map((previous, position) => (position === index ? response : previous)),
        );
    };

    return (
        <>
            <Button
                type="button"
                size={provider ? 'default' : 'sm'}
                variant="outline"
                onClick={() => {
                    form.clearErrors();
                    form.setData({
                        request_id: crypto.randomUUID(),
                        visit_version: visitVersion,
                        responses: checklist.steps.map(() => ({ status: '', reason: '' })),
                        password: '',
                        declaration: false as boolean,
                    });
                    setOpen(true);
                }}
            >
                {canSubmit ? 'Record checklist' : 'View checklist'}
            </Button>
            <Dialog open={open} onOpenChange={(next) => !next && !form.processing && setOpen(false)}>
                <DialogContent className={dialogClass}>
                    <DialogTitle className="break-words">{checklist.title}</DialogTitle>
                    <DialogDescription className="break-words">
                        Version {checklist.version} · Attached {date(checklist.attached_at)}
                    </DialogDescription>
                    <div>
                        <Badge variant={checklist.submitted_at ? 'success' : 'secondary'}>
                            {checklist.submitted_at ? `Signed ${date(checklist.submitted_at)}` : 'Awaiting submission'}
                        </Badge>
                    </div>
                    {canSubmit ? (
                        <form
                            className="min-w-0 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(`/my-visits/${visitId}/checklist`, {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                    onFinish: () => form.setData('password', ''),
                                });
                            }}
                        >
                            <Errors errors={form.errors} />
                            {checklist.steps.map((step, index) => {
                                const response = form.data.responses[index] ?? { status: '', reason: '' };
                                return (
                                    <fieldset className="min-w-0 space-y-2 rounded-md border p-3" key={index}>
                                        <legend className="px-1 text-sm font-medium">Step {index + 1}</legend>
                                        <p className="text-sm break-words whitespace-pre-wrap">{step}</p>
                                        <label className="block min-w-0 space-y-1 text-sm font-medium">
                                            <span>Step {index + 1} outcome</span>
                                            <select
                                                className={inputClass}
                                                required
                                                value={response.status}
                                                onChange={(event) =>
                                                    changeResponse(index, { status: event.target.value as ChecklistResponse['status'], reason: '' })
                                                }
                                            >
                                                <option value="" disabled>
                                                    Select an outcome
                                                </option>
                                                <option value="completed">Completed</option>
                                                <option value="exception">Exception</option>
                                            </select>
                                        </label>
                                        {response.status === 'exception' && (
                                            <label className="block min-w-0 space-y-1 text-sm font-medium">
                                                <span>Reason for step {index + 1} exception</span>
                                                <textarea
                                                    className={inputClass}
                                                    rows={3}
                                                    maxLength={2000}
                                                    required
                                                    value={response.reason}
                                                    onChange={(event) => changeResponse(index, { ...response, reason: event.target.value })}
                                                />
                                            </label>
                                        )}
                                    </fieldset>
                                );
                            })}
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
                                <span>I confirm these steps and exceptions reflect what happened on this visit.</span>
                            </label>
                            <Button type="submit" disabled={form.processing}>
                                Sign and submit checklist
                            </Button>
                        </form>
                    ) : (
                        <ol className="list-decimal space-y-3 ps-5 text-sm">
                            {checklist.steps.map((step, index) => {
                                const response = checklist.responses?.[index];
                                return (
                                    <li className="space-y-1 break-words" key={index}>
                                        <p className="whitespace-pre-wrap">{step}</p>
                                        {response && (
                                            <Badge variant={response.status === 'completed' ? 'success' : 'warning'}>
                                                {response.status === 'completed' ? 'Completed' : 'Exception'}
                                            </Badge>
                                        )}
                                        {response?.reason && <p className="text-muted-foreground whitespace-pre-wrap">{response.reason}</p>}
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
