import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import Collections from '@/pages/patients/collections';
import { Errors, Field, Fields, Section, date } from '@/pages/patients/fields';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ClinicalRequestError, saveClinical } from './api';
export type ClinicalContent = Record<string, string | Record<string, string>[]>;
export type ClinicalSchema = { sections: Section[]; children: Record<string, Field[]> };
export type ClinicalRow = {
    id: number;
    kind: 'notes' | 'plans';
    status: string;
    version: number;
    content?: ClinicalContent;
    content_hash?: string;
    author: { id: number; name: string };
    editable: boolean;
    signed_at: string | null;
    approved_at?: string | null;
    review_due_at?: string | null;
    revision_number?: number;
    episode?: { id: number; title: string; current_plan_id: number | null };
    previous_id?: number | null;
    revision_reason?: string | null;
    note_type?: string;
    visibility?: string;
    original_id?: number | null;
    appointment_id?: number;
    addendum_reason?: string | null;
    can_addendum?: boolean;
    can_revise?: boolean;
    can_approve?: boolean;
    can_transition?: boolean;
    can_publish?: boolean;
    events?: { id: number; action: string; reason: string; created_at: string; actor: string }[];
};
export default function ClinicalEditor({
    row: a,
    schema,
    base,
    staff,
    close,
}: {
    row: ClinicalRow;
    schema: ClinicalSchema;
    base: string;
    staff: { id: number; name: string }[];
    close: () => void;
}) {
    const initial = {
        ...Object.fromEntries(schema.sections.flatMap((s) => s.fields.map((f) => [f.key, '']))),
        ...Object.fromEntries(Object.keys(schema.children).map((k) => [k, []])),
        ...a.content,
    } as ClinicalContent;
    const [content, setContent] = useState(initial),
        [version, setVersion] = useState(a.version),
        [busy, setBusy] = useState(false),
        [errors, setErrors] = useState<Record<string, string>>({}),
        [message, setMessage] = useState(a.editable ? 'Draft saved' : 'Signed content is read-only'),
        [paused, setPaused] = useState(false),
        [signing, setSigning] = useState(false),
        [password, setPassword] = useState(''),
        [declaration, setDeclaration] = useState(false);
    const lastSaved = useRef(JSON.stringify(initial)),
        currentVersion = useRef(a.version),
        inFlight = useRef(false),
        latest = useRef(content);
    latest.current = content;
    const options = { responsible_id: staff.map((u) => ({ value: String(u.id), label: u.name })) };
    const save = useCallback(
        async (action: 'save' | 'sign', secret = '', confirmed = false) => {
            if (inFlight.current || !a.editable) return false;
            inFlight.current = true;
            setBusy(true);
            setErrors({});
            setMessage('Saving…');
            const snapshot = latest.current;
            try {
                const result = await saveClinical(`${base}/${a.kind}/${a.id}`, {
                    version: currentVersion.current,
                    action,
                    content: snapshot,
                    password: secret,
                    declaration: confirmed,
                });
                currentVersion.current = result.version;
                setVersion(result.version);
                lastSaved.current = JSON.stringify(snapshot);
                setPaused(false);
                setMessage(action === 'sign' ? 'Signed and locked' : 'Draft saved');
                if (action === 'sign') {
                    setPassword('');
                    close();
                }
                return true;
            } catch (e) {
                setErrors(e instanceof ClinicalRequestError ? e.errors : { request: 'Could not save. Your edits remain on this screen.' });
                setPaused(true);
                setMessage('Autosave paused. Review the error, then save or reload.');
                return false;
            } finally {
                if (action === 'sign') setPassword('');
                inFlight.current = false;
                setBusy(false);
            }
        },
        [a.editable, a.id, a.kind, base, close],
    );
    useEffect(() => {
        if (!a.editable || busy || paused || JSON.stringify(content) === lastSaved.current) return;
        setMessage('Unsaved changes — saving soon…');
        const timer = setTimeout(() => void save('save'), 1800);
        return () => clearTimeout(timer);
    }, [content, busy, paused, a.editable, save]);
    useEffect(() => {
        const dirty = () => a.editable && JSON.stringify(latest.current) !== lastSaved.current;
        const unload = (e: BeforeUnloadEvent) => {
            if (dirty()) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', unload);
        const stop = router.on('before', (e) => {
            if (dirty() && !window.confirm('This draft has unsaved changes. Leave without saving?')) e.preventDefault();
        });
        return () => {
            stop();
            window.removeEventListener('beforeunload', unload);
        };
    }, [a.editable]);
    return (
        <div className="space-y-4">
            <section className="surface space-y-3 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <h2 className="text-xl font-semibold">
                        {a.kind === 'notes' ? 'Professional note' : 'Care plan'} #{a.id}
                    </h2>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={async () => {
                            if (!a.editable || JSON.stringify(content) === lastSaved.current || (await save('save'))) close();
                        }}
                    >
                        Back to records
                    </Button>
                </div>
                <p className="text-muted-foreground text-sm">
                    {a.author.name} · Revision {version} · Signed {date(a.signed_at)}
                    {a.episode ? ` · ${a.episode.title}` : ''}
                </p>
                {a.original_id && (
                    <p className="text-sm">
                        Addendum to note #{a.original_id}: {a.addendum_reason}
                    </p>
                )}
                {a.previous_id && (
                    <p className="text-sm">
                        Replaces plan #{a.previous_id}: {a.revision_reason}
                    </p>
                )}
                {a.content_hash && <p className="text-muted-foreground font-mono text-[10px] break-all">Content integrity: {a.content_hash}</p>}
                <p role="status" className="text-muted-foreground text-sm">
                    {message}
                    {a.editable ? ' · Autosaves after you stop typing. Required fields are checked when signing.' : ''}
                </p>
            </section>
            {schema.sections.map((s) => (
                <section key={s.title} className="surface space-y-4 p-4 sm:p-6">
                    <h3 className="text-lg font-semibold">{s.title}</h3>
                    <Fields
                        prefix={`clinical-${a.kind}-${a.id}`}
                        options={options}
                        disabled={!a.editable}
                        fields={s.fields.map((f) => ({ ...f, required: false, label: f.label + (f.required ? ' *' : '') }))}
                        values={Object.fromEntries(s.fields.map((f) => [f.key, String(content[f.key] ?? '')]))}
                        change={(k, v) => setContent({ ...content, [k]: v })}
                    />
                </section>
            ))}
            <Collections
                options={options}
                schema={Object.fromEntries(Object.entries(schema.children).map(([k, fields]) => [k, fields.map((f) => ({ ...f, required: false }))]))}
                disabled={!a.editable}
                values={Object.fromEntries(Object.keys(schema.children).map((k) => [k, content[k] as Record<string, string>[]]))}
                change={(k, v) => setContent({ ...content, [k]: v })}
            />
            {!!a.events?.length && (
                <section className="surface space-y-3 p-4 sm:p-6">
                    <h3 className="text-lg font-semibold">Clinical decision history</h3>
                    {a.events.map((event) => (
                        <article className="space-y-1 rounded-xl border p-3" key={event.id}>
                            <p className="text-sm font-medium">
                                {event.action} · {event.actor}
                            </p>
                            <p className="text-muted-foreground text-xs">{date(event.created_at)}</p>
                            <p className="text-sm break-words whitespace-pre-wrap">{event.reason}</p>
                        </article>
                    ))}
                </section>
            )}
            <Errors errors={errors} />
            {a.editable && (
                <div className="bg-card sticky bottom-3 flex flex-wrap justify-end gap-2 rounded-2xl border p-3 backdrop-blur-xl">
                    <Button variant="outline" disabled={busy} onClick={() => void save('save')}>
                        Save draft
                    </Button>
                    <Button disabled={busy} onClick={() => setSigning(true)}>
                        {a.kind === 'notes' ? 'Sign note' : 'Sign proposal for review'}
                    </Button>
                </div>
            )}
            <Dialog
                open={signing}
                onOpenChange={(open) => {
                    setSigning(open);
                    if (!open) setPassword('');
                }}
            >
                <DialogContent>
                    <DialogTitle>{a.kind === 'notes' ? 'Sign professional note' : 'Sign care-plan proposal'}</DialogTitle>
                    <DialogDescription>
                        {a.kind === 'notes'
                            ? 'The signed original cannot be overwritten. Corrections use an attributed addendum.'
                            : 'The proposal will be locked for separate clinical lead approval. It becomes current only after approval.'}
                    </DialogDescription>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save('sign', password, declaration);
                        }}
                    >
                        <label htmlFor="clinical-password">Confirm password</label>
                        <Input
                            id="clinical-password"
                            type="password"
                            autoComplete="current-password"
                            required
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                        <label className="flex items-start gap-3 text-sm">
                            <input type="checkbox" required checked={declaration} onChange={(e) => setDeclaration(e.target.checked)} />
                            <span>
                                I confirm this is my attributable professional record, based on the stated encounter, sources, patient agreement and
                                scope.
                            </span>
                        </label>
                        <Errors errors={errors} />
                        <Button disabled={busy}>Confirm signature</Button>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
