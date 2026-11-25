import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { date, Errors } from '@/pages/patients/fields';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type ChecklistVersion = {
    id: number;
    version: number;
    status: 'draft' | 'published';
    steps: string[];
    published_at: string | null;
};

type ChecklistTemplate = { id: number; title: string; versions: ChecklistVersion[] };

const base = '/workspace/checklists';
const maxSteps = 40;
const dialogClass = 'max-h-[85dvh] w-[calc(100%-1.5rem)] overflow-y-auto sm:max-w-xl';

export default function Checklists({ templates }: { templates: ChecklistTemplate[] }) {
    const [draftDialog, setDraftDialog] = useState<{ template: ChecklistTemplate | null } | null>(null);
    const [publishDialog, setPublishDialog] = useState<{ template: ChecklistTemplate; version: ChecklistVersion } | null>(null);
    const draft = useForm({ request_id: '', title: '', steps: [''] });
    const publish = useForm({ password: '', declaration: false as boolean });

    function openDraft(template: ChecklistTemplate | null, steps: string[] = ['']) {
        draft.clearErrors();
        draft.setData({ request_id: crypto.randomUUID(), title: template?.title ?? '', steps: [...steps] });
        setDraftDialog({ template });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Checklists', href: base }]}>
            <Head title="Operational checklists" />
            <main className="mx-auto w-full max-w-5xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface flex flex-wrap items-start justify-between gap-3 p-4 sm:p-6">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold break-words">Operational checklists</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Publish standard operating procedures and retain earlier versions for reference.
                        </p>
                    </div>
                    <Button onClick={() => openDraft(null)}>New checklist</Button>
                </header>
                {templates.length === 0 && <p className="surface text-muted-foreground p-4 text-sm sm:p-6">No checklists yet.</p>}
                {templates.map((template) => {
                    const versions = [...template.versions].sort((first, second) => second.version - first.version);
                    const current = versions.find((version) => version.status === 'published');
                    const source = current ?? versions[0];

                    return (
                        <section className="surface min-w-0 space-y-4 p-4 sm:p-6" key={template.id}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="text-lg font-semibold break-words">{template.title}</h2>
                                    <p className="text-muted-foreground text-sm">
                                        {current ? `Current publication is version ${current.version}.` : 'Awaiting first publication.'}
                                    </p>
                                </div>
                                <Button variant="outline" onClick={() => openDraft(template, source?.steps)}>
                                    New draft version
                                </Button>
                            </div>
                            {versions.map((version) => (
                                <article className="min-w-0 space-y-3 rounded-lg border p-3" key={version.id}>
                                    <details className="min-w-0 space-y-3" open={source?.id === version.id}>
                                        <summary className="cursor-pointer">
                                            <span className="inline-flex max-w-full flex-wrap items-center gap-2 align-middle">
                                                <span className="font-medium">Version {version.version}</span>
                                                <Badge variant={current?.id === version.id ? 'success' : 'secondary'}>
                                                    {current?.id === version.id
                                                        ? 'Current publication'
                                                        : version.status === 'published'
                                                          ? 'Published'
                                                          : 'Draft'}
                                                </Badge>
                                            </span>
                                        </summary>
                                        {version.published_at && (
                                            <p className="text-muted-foreground text-sm">
                                                Published <time dateTime={version.published_at}>{date(version.published_at)}</time>
                                            </p>
                                        )}
                                        <ol className="list-decimal space-y-2 ps-5 text-sm">
                                            {version.steps.map((step, index) => (
                                                <li className="break-words whitespace-pre-wrap" key={index}>
                                                    {step}
                                                </li>
                                            ))}
                                        </ol>
                                    </details>
                                    {version.status === 'draft' &&
                                        (version.version > (current?.version ?? 0) ? (
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    publish.reset();
                                                    publish.clearErrors();
                                                    setPublishDialog({ template, version });
                                                }}
                                            >
                                                Publish version {version.version}
                                            </Button>
                                        ) : (
                                            <p className="text-muted-foreground text-sm">A later version has been published.</p>
                                        ))}
                                </article>
                            ))}
                        </section>
                    );
                })}

                <Dialog open={!!draftDialog} onOpenChange={(open) => !open && !draft.processing && setDraftDialog(null)}>
                    <DialogContent className={dialogClass}>
                        <DialogTitle>{draftDialog?.template ? 'New draft version' : 'New operational checklist'}</DialogTitle>
                        <DialogDescription className="break-words">
                            {draftDialog?.template ? draftDialog.template.title : 'Give the checklist a title and add its steps in order.'}
                        </DialogDescription>
                        {draftDialog && (
                            <form
                                className="min-w-0 space-y-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    const template = draftDialog.template;
                                    draft.transform(({ title, ...payload }) => (template ? payload : { ...payload, title }));
                                    draft.post(template ? `${base}/${template.id}/versions` : base, {
                                        preserveScroll: true,
                                        onSuccess: () => setDraftDialog(null),
                                    });
                                }}
                            >
                                <Errors errors={draft.errors} />
                                {!draftDialog.template && (
                                    <label className="block space-y-1 text-sm font-medium">
                                        <span>Checklist title</span>
                                        <Input
                                            required
                                            maxLength={160}
                                            value={draft.data.title}
                                            onChange={(event) => draft.setData('title', event.target.value)}
                                        />
                                    </label>
                                )}
                                <fieldset className="min-w-0 space-y-4">
                                    <legend className="mb-2 font-medium">Checklist steps</legend>
                                    {draft.data.steps.map((step, index) => (
                                        <div className="min-w-0 space-y-2" key={index}>
                                            <label className="block space-y-1 text-sm font-medium">
                                                <span>Step {index + 1}</span>
                                                <textarea
                                                    className="border-input bg-background min-h-20 w-full min-w-0 rounded-md border p-2 text-base"
                                                    required
                                                    maxLength={1000}
                                                    value={step}
                                                    onChange={(event) =>
                                                        draft.setData(
                                                            'steps',
                                                            draft.data.steps.map((text, position) =>
                                                                position === index ? event.target.value : text,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </label>
                                            {draft.data.steps.length > 1 && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    aria-label={`Remove step ${index + 1}`}
                                                    onClick={() =>
                                                        draft.setData(
                                                            'steps',
                                                            draft.data.steps.filter((_, position) => position !== index),
                                                        )
                                                    }
                                                >
                                                    Remove step
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </fieldset>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={draft.data.steps.length >= maxSteps}
                                        onClick={() => draft.setData('steps', [...draft.data.steps, ''])}
                                    >
                                        Add step
                                    </Button>
                                    <Button type="submit" disabled={draft.processing}>
                                        Save draft
                                    </Button>
                                </div>
                                {draft.data.steps.length >= maxSteps && (
                                    <p className="text-muted-foreground text-sm">Maximum of {maxSteps} steps reached.</p>
                                )}
                            </form>
                        )}
                    </DialogContent>
                </Dialog>

                <Dialog open={!!publishDialog} onOpenChange={(open) => !open && !publish.processing && setPublishDialog(null)}>
                    <DialogContent className={dialogClass}>
                        <DialogTitle>Publish checklist version</DialogTitle>
                        {publishDialog && (
                            <>
                                <DialogDescription className="break-words">
                                    {publishDialog.template.title} · Version {publishDialog.version.version}. Publication locks the steps.
                                </DialogDescription>
                                <form
                                    className="min-w-0 space-y-4"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        publish.post(`${base}/${publishDialog.template.id}/versions/${publishDialog.version.id}/publish`, {
                                            preserveScroll: true,
                                            onSuccess: () => setPublishDialog(null),
                                            onFinish: () => publish.setData('password', ''),
                                        });
                                    }}
                                >
                                    <Errors errors={publish.errors} />
                                    <label className="block space-y-1 text-sm font-medium">
                                        <span>Current password</span>
                                        <Input
                                            type="password"
                                            autoComplete="current-password"
                                            required
                                            value={publish.data.password}
                                            onChange={(event) => publish.setData('password', event.target.value)}
                                        />
                                    </label>
                                    <label className="flex items-start gap-2 text-sm">
                                        <input
                                            className="mt-1"
                                            type="checkbox"
                                            required
                                            checked={publish.data.declaration}
                                            onChange={(event) => publish.setData('declaration', event.target.checked)}
                                        />
                                        <span>I reviewed this version and confirm it is ready to publish.</span>
                                    </label>
                                    <Button type="submit" disabled={publish.processing}>
                                        Publish version
                                    </Button>
                                </form>
                            </>
                        )}
                    </DialogContent>
                </Dialog>
            </main>
        </AppLayout>
    );
}
