import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { date, Errors } from '@/pages/patients/fields';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ClinicalRow } from './editor';

const fields = {
    outcomes: 'Goals, outcomes and ongoing risks',
    patient_discussion: 'Patient discussion and decision evidence',
    medications: 'Medication reconciliation evidence',
    results_review: 'Results and outstanding work review',
    summary: 'Discharge summary for the patient',
    next_steps: 'Follow-up, warning signs and who to contact',
};

type Content = Record<keyof typeof fields, string>;

export type Episode = {
    id: number;
    title: string;
    current_plan_id: number | null;
    discharged_at: string | null;
    discharge: { signed_by: number; signed_at: string; content: Content } | null;
};

const empty = {
    outcomes: '',
    patient_discussion: '',
    medications: '',
    results_review: '',
    summary: '',
    next_steps: '',
};

export default function EpisodeDischarges({
    base,
    episodes,
    plans,
    canSign,
}: {
    base: string;
    episodes: Episode[];
    plans: ClinicalRow[];
    canSign: boolean;
}) {
    const [selected, setSelected] = useState<Episode | null>(null);
    const form = useForm({ ...empty, request_id: '', plan_id: 0, plan_version: 0, password: '', declaration: false as boolean });

    if (!episodes.length) return null;

    return (
        <section className="surface min-w-0 space-y-4 p-4 sm:p-6">
            <h2 className="font-semibold">Care episodes</h2>
            {episodes.map((episode) => {
                const plan = plans
                    .filter((row) => row.episode?.id === episode.id && row.status === 'completed')
                    .sort((a, b) => (b.revision_number ?? 0) - (a.revision_number ?? 0))[0];
                return (
                    <article className="min-w-0 space-y-2 rounded-lg border p-3" key={episode.id}>
                        <h3 className="font-medium break-words">{episode.title}</h3>
                        {episode.discharge ? (
                            <details className="text-sm">
                                <summary className="cursor-pointer">Discharged {date(episode.discharge.signed_at)}</summary>
                                <dl className="mt-3 space-y-3">
                                    {Object.entries(fields).map(([key, label]) => (
                                        <div key={key}>
                                            <dt className="font-medium">{label}</dt>
                                            <dd className="break-words whitespace-pre-wrap">{episode.discharge?.content[key as keyof Content]}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </details>
                        ) : canSign && plan && episode.current_plan_id === null ? (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    form.clearErrors();
                                    form.setData({
                                        ...empty,
                                        request_id: crypto.randomUUID(),
                                        plan_id: plan.id,
                                        plan_version: plan.version,
                                        password: '',
                                        declaration: false as boolean,
                                    });
                                    setSelected(episode);
                                }}
                            >
                                Review and sign discharge
                            </Button>
                        ) : (
                            <p className="text-muted-foreground text-sm">Episode open</p>
                        )}
                    </article>
                );
            })}
            <Dialog open={selected !== null} onOpenChange={(open) => !open && !form.processing && setSelected(null)}>
                <DialogContent className="max-h-[85dvh] w-[calc(100%-1.5rem)] overflow-y-auto sm:max-w-xl">
                    <DialogTitle>Sign discharge</DialogTitle>
                    <DialogDescription className="break-words">
                        {selected?.title}. The final two text fields are shared with the patient when you sign.
                    </DialogDescription>
                    <form
                        className="min-w-0 space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`${base}/episodes/${selected?.id}/discharge`, {
                                preserveScroll: true,
                                onSuccess: () => setSelected(null),
                                onFinish: () => form.setData('password', ''),
                            });
                        }}
                    >
                        <Errors errors={form.errors} />
                        {Object.entries(fields).map(([key, label]) => (
                            <label className="block space-y-1 text-sm font-medium" key={key}>
                                <span>{label}</span>
                                <textarea
                                    className="border-input bg-background w-full rounded-md border p-2 text-base"
                                    rows={3}
                                    maxLength={5000}
                                    required
                                    value={form.data[key as keyof Content]}
                                    onChange={(event) => form.setData(key as keyof Content, event.target.value)}
                                />
                            </label>
                        ))}
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
                            I confirm this discharge and the patient-facing summary are accurate.
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            Sign and publish discharge
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
