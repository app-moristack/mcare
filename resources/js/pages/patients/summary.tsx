import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { date, Errors, Fields, Schema, title } from './fields';
export type Content = Record<string, string | Record<string, string>[]>;
export function SummaryDisplay({ content }: { content: Content }) {
    return (
        <dl className="space-y-3">
            {Object.entries(content).map(([key, value]) => (
                <div key={key} className="min-w-0 border-b pb-3">
                    <dt className="text-sm font-medium">{title(key)}</dt>
                    <dd className="text-muted-foreground mt-1 text-sm break-words whitespace-pre-wrap">
                        {Array.isArray(value)
                            ? value.length
                                ? value.map((row, i) => (
                                      <div key={i} className="mt-2 rounded-lg border p-3">
                                          {Object.entries(row)
                                              .filter(([, v]) => v)
                                              .map(([k, v]) => (
                                                  <p key={k}>
                                                      {title(k)}: {v}
                                                  </p>
                                              ))}
                                      </div>
                                  ))
                                : 'None recorded'
                            : value
                              ? key === 'allergy_status' || key === 'sex_for_clinical_use'
                                  ? title(value)
                                  : value
                              : 'Not recorded'}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
export default function ClinicalSummary({ patientId, schema, canWrite }: { patientId: number; schema: Schema; canWrite: boolean }) {
    const [loaded, setLoaded] = useState(false);
    const [error, setError] = useState('');
    const [updated, setUpdated] = useState<string | null>(null);
    const defaults: Content = {
        ...Object.fromEntries(schema.clinical.flatMap((s) => s.fields.map((f) => [f.key, f.key === 'allergy_status' ? 'not_assessed' : '']))),
        ...Object.fromEntries(Object.keys(schema.children).map((k) => [k, []])),
    };
    const f = useForm({ content: defaults, version: 0, reason: '' });
    useEffect(() => {
        const controller = new AbortController();
        fetch(`/patient-records/${patientId}/summary`, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async (r) => {
                if (!r.ok) throw new Error(r.status === 403 ? 'Your clinical access is no longer current.' : 'Could not load the clinical summary.');
                return r.json();
            })
            .then((d) => {
                f.setData({ content: { ...defaults, ...d.content }, version: d.version, reason: '' });
                setUpdated(d.updated_at);
                setLoaded(true);
            })
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => controller.abort(); /* The record is deliberately fetched again when the section is reopened. */
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [patientId]);
    if (error)
        return (
            <section className="surface p-5" role="alert">
                {error}
            </section>
        );
    if (!loaded)
        return (
            <section className="surface p-5" role="status">
                Loading authorised summary…
            </section>
        );
    if (!canWrite)
        return (
            <section className="surface space-y-4 p-4 sm:p-6">
                <h2 className="text-lg font-semibold">Clinical summary</h2>
                <p className="text-muted-foreground text-sm">Last reconciled: {date(updated)}</p>
                <SummaryDisplay content={f.data.content} />
            </section>
        );
    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                f.patch(`/patient-records/${patientId}/summary`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        f.setData({ ...f.data, version: f.data.version + 1, reason: '' });
                        setUpdated(new Date().toISOString());
                    },
                });
            }}
        >
            <section className="surface p-4 sm:p-6">
                <h2 className="text-lg font-semibold">Clinical summary</h2>
                <p className="text-muted-foreground mt-2 text-sm">
                    Patient-visible reconciled history. This is not a prescription, medication administration record or signed nursing assessment.
                    Last reviewed: {date(updated)}.
                </p>
            </section>
            {schema.clinical.map((s) => (
                <section className="surface space-y-4 p-4 sm:p-6" key={s.title}>
                    <h3 className="font-semibold">{s.title}</h3>
                    <Fields
                        fields={s.fields}
                        values={Object.fromEntries(s.fields.map((x) => [x.key, String(f.data.content[x.key] ?? '')]))}
                        change={(k, v) => f.setData('content', { ...f.data.content, [k]: v })}
                    />
                </section>
            ))}
            {Object.entries(schema.children).map(([key, fields]) => {
                const rows = f.data.content[key] as Record<string, string>[];
                return (
                    <section className="surface space-y-4 p-4 sm:p-6" key={key}>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="font-semibold">{title(key)}</h3>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={rows.length >= 50}
                                onClick={() =>
                                    f.setData('content', { ...f.data.content, [key]: [...rows, Object.fromEntries(fields.map((x) => [x.key, '']))] })
                                }
                            >
                                Add {title(key)}
                            </Button>
                        </div>
                        {rows.length === 0 && <p className="text-muted-foreground text-sm">No entries recorded. This does not confirm absence.</p>}
                        {rows.map((row, i) => (
                            <div key={i} className="space-y-4 rounded-xl border p-3 sm:p-4">
                                <Fields
                                    prefix={`${key}-${i}`}
                                    fields={fields}
                                    values={row}
                                    change={(k, v) =>
                                        f.setData('content', { ...f.data.content, [key]: rows.map((x, n) => (n === i ? { ...x, [k]: v } : x)) })
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => f.setData('content', { ...f.data.content, [key]: rows.filter((_, n) => n !== i) })}
                                >
                                    Remove entry {i + 1}
                                </Button>
                            </div>
                        ))}
                    </section>
                );
            })}
            <section className="surface space-y-3 p-4">
                <label htmlFor="summary-reason">Reconciliation / change reason *</label>
                <Input id="summary-reason" required value={f.data.reason} onChange={(e) => f.setData('reason', e.target.value)} maxLength={2000} />
                <Errors errors={f.errors} />
                <Button disabled={f.processing}>Save summary revision</Button>
            </section>
        </form>
    );
}
