import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Collections from './collections';
import { AccessRule, date, Errors, Field, Fields, History, Schema, title } from './fields';
import AccessRules from './rules';
import ClinicalSummary from './summary';
type Patient = {
    id: number;
    name: string;
    reference: string;
    date_of_birth: string | null;
    phone: string | null;
    email: string | null;
    locality: string;
    address: string | null;
    version: number;
    status: string;
    identity_status: string;
    demographics: Record<string, string | Record<string, string>[]> | null;
};
export default function PatientRecord({
    patient: p,
    schema,
    manage,
    canRead,
    canWrite,
    rules,
    staff,
    history,
}: {
    patient: Patient;
    schema: Schema;
    manage: boolean;
    canRead: boolean;
    canWrite: boolean;
    rules: AccessRule[];
    staff: { id: number; name: string }[];
    history: History;
}) {
    const [tab, setTab] = useState('details');
    const defaults: Record<string, string> = {
        country: 'MU',
        preferred_language: 'en',
        dob_precision: p.date_of_birth ? 'exact' : 'unknown',
        record_status: 'provisional',
    };
    const form = useForm({
        name: p.name,
        date_of_birth: p.date_of_birth?.slice(0, 10) ?? '',
        phone: p.phone ?? '',
        email: p.email ?? '',
        locality: p.locality,
        version: p.version,
        content: {
            ...Object.fromEntries(schema.demographics.flatMap((s) => s.fields.map((f) => [f.key, p.demographics?.[f.key] ?? defaults[f.key] ?? '']))),
            ...Object.fromEntries(Object.keys(schema.demographicsChildren).map((k) => [k, p.demographics?.[k] ?? []])),
        } as Record<string, string | Record<string, string>[]>,
    });
    const base: Field[] = [
        { key: 'name', label: 'Display legal name', type: 'text', required: true, choices: null },
        { key: 'date_of_birth', label: 'Date of birth (see precision below)', type: 'date', required: false, choices: null },
        { key: 'phone', label: 'Phone (E.164, e.g. +230…)', type: 'tel', required: false, choices: null },
        { key: 'email', label: 'Email', type: 'email', required: false, choices: null },
        { key: 'locality', label: 'Town / locality', type: 'text', required: false, choices: null },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/workspace/patients' },
                { title: p.name, href: `/patient-records/${p.id}` },
            ]}
        >
            <Head title={p.name} />
            <main className="mx-auto w-full max-w-6xl min-w-0 space-y-5 p-3 sm:p-6">
                <Link className="text-primary text-sm" href="/workspace/patients">
                    Back to patients
                </Link>
                <header className="surface space-y-3 p-4 sm:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-muted-foreground text-xs">Patient record</p>
                            <h1 className="text-2xl font-semibold break-words">{p.name}</h1>
                        </div>
                        <Badge variant="secondary">Identity: {title(p.identity_status)}</Badge>
                    </div>
                    <p className="text-muted-foreground font-mono text-xs break-all">{p.reference}</p>
                    <p className="text-muted-foreground text-sm">
                        Emergency contacts and payers receive no clinical access by relationship alone. Dates and times use Mauritius time.
                    </p>
                </header>
                <Button asChild variant="outline">
                    <Link href={`/patient-records/${p.id}/assessments`}>Assessments and completion</Link>
                </Button>
                <Button asChild variant="outline">
                    <Link href={`/patient-records/${p.id}/clinical`}>Notes and care plans</Link>
                </Button>
                <nav aria-label="Patient sections" className="flex flex-wrap gap-2">
                    {['details', 'clinical', 'access', 'history']
                        .filter((t) => manage || !['access', 'history'].includes(t))
                        .map((t) => (
                            <Button key={t} variant={tab === t ? 'default' : 'outline'} onClick={() => setTab(t)} aria-pressed={tab === t}>
                                {title(t)}
                            </Button>
                        ))}
                </nav>
                {tab === 'details' && (
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.patch(`/patient-records/${p.id}`, { preserveScroll: true, onSuccess: () => form.setData('version', p.version + 1) });
                        }}
                    >
                        <section className="surface space-y-5 p-4 sm:p-6">
                            <h2 className="text-lg font-semibold">Core details</h2>
                            <Fields
                                fields={base}
                                values={Object.fromEntries(base.map((f) => [f.key, String(form.data[f.key as keyof typeof form.data] ?? '')]))}
                                change={(k, v) => form.setData(k as 'name', v)}
                                disabled={!manage}
                            />
                        </section>
                        {schema.demographics.map((s) => (
                            <section key={s.title} className="surface space-y-5 p-4 sm:p-6">
                                <h2 className="text-lg font-semibold">{s.title}</h2>
                                <Fields
                                    fields={s.fields}
                                    values={Object.fromEntries(s.fields.map((f) => [f.key, String(form.data.content[f.key] ?? '')]))}
                                    disabled={!manage}
                                    change={(k, v) => form.setData('content', { ...form.data.content, [k]: v })}
                                />
                                {s.title.startsWith('Emergency') && (
                                    <p className="text-muted-foreground text-xs">
                                        Emergency contact authority is contact only. Proxy sharing requires separate verified authority.
                                    </p>
                                )}
                            </section>
                        ))}
                        <Collections
                            schema={schema.demographicsChildren}
                            values={Object.fromEntries(
                                Object.keys(schema.demographicsChildren).map((k) => [k, form.data.content[k] as Record<string, string>[]]),
                            )}
                            disabled={!manage}
                            change={(k, v) => form.setData('content', { ...form.data.content, [k]: v })}
                        />
                        <Errors errors={form.errors} />
                        {manage && (
                            <div className="bg-card sticky bottom-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-3 backdrop-blur-xl">
                                <span className="text-muted-foreground hidden text-xs sm:block">
                                    Required fields marked *. Changes are attributed and audited.
                                </span>
                                <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save patient record'}</Button>
                            </div>
                        )}
                        <p className="text-muted-foreground text-xs">
                            Identity verification, document uploads, duplicate merging and clinical activation review are separate workflows.
                            Provisional status does not confirm identity.
                        </p>
                    </form>
                )}
                {tab === 'clinical' &&
                    (canRead ? (
                        <ClinicalSummary patientId={p.id} schema={schema} canWrite={canWrite} />
                    ) : (
                        <section className="surface p-6">
                            <h2 className="font-semibold">Clinical access required</h2>
                            <p className="text-muted-foreground mt-3 text-sm">
                                A current clinical credential, named assignment, scoped patient authority and valid access period are required.
                                Administrative access alone does not open the clinical summary.
                            </p>
                        </section>
                    ))}
                {tab === 'access' && manage && <AccessRules patientId={p.id} rules={rules} staff={staff} />}
                {tab === 'history' && manage && (
                    <section className="surface p-4 sm:p-6">
                        <h2 className="text-lg font-semibold">Recent access and changes</h2>
                        <div className="mt-4 divide-y">
                            {history.map((h) => (
                                <div className="grid gap-1 py-3 text-sm sm:grid-cols-3" key={h.id}>
                                    <span className="break-words">{title(h.event)}</span>
                                    <span>{h.actor ?? 'System'}</span>
                                    <time className="text-muted-foreground">{date(h.created_at)}</time>
                                </div>
                            ))}
                        </div>
                        <p className="text-muted-foreground text-xs">Latest 100 events for this patient record.</p>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}
