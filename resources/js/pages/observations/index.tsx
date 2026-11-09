import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

type TypeInfo = { label: string; unit: string };
type Measurement = { type: string; value: string; unit: string; method?: string };
type Row = {
    id: number;
    appointment_id: number;
    supersedes_id: number | null;
    observed_at: string;
    measurements: Measurement[];
    context?: string;
    correction_reason?: string;
    content_hash: string;
    author?: { id: number; name: string };
    appointment?: { id: number; service: string };
};
type TrendPoint = { rowId: number; time: number; value: number };

const formatMauritius = (value: string | number) =>
    new Date(value).toLocaleString('en-GB', {
        timeZone: 'Indian/Mauritius',
        dateStyle: 'medium',
        timeStyle: 'short',
    });

function TrendChart({ id, points, label, unit }: { id: string; points: TrendPoint[]; label: string; unit: string }) {
    const width = 640;
    const height = 180;
    const xPadding = 28;
    const yPadding = 24;
    const values = points.map((point) => point.value);
    const times = points.map((point) => point.time);
    const minimum = Math.min(...values);
    const maximum = Math.max(...values);
    const valuePadding = maximum === minimum ? Math.max(Math.abs(maximum) * 0.05, 1) : (maximum - minimum) * 0.12;
    const low = minimum - valuePadding;
    const high = maximum + valuePadding;
    const firstTime = Math.min(...times);
    const lastTime = Math.max(...times);
    const coordinates = points.map((point, index) => ({
        ...point,
        x: firstTime === lastTime ? width / 2 : xPadding + ((point.time - firstTime) / (lastTime - firstTime)) * (width - xPadding * 2),
        y: height - yPadding - ((point.value - low) / (high - low)) * (height - yPadding * 2),
        index,
    }));

    return (
        <div className="overflow-x-auto" aria-label={`${label} trend chart`}>
            <svg className="text-primary h-44 min-w-[420px]" viewBox={`0 0 ${width} ${height}`} role="img" aria-labelledby={`trend-title-${id}`}>
                <title id={`trend-title-${id}`}>{`${label} values over time in ${unit}`}</title>
                {[yPadding, height / 2, height - yPadding].map((y) => (
                    <line key={y} x1={xPadding} x2={width - xPadding} y1={y} y2={y} className="stroke-border" strokeWidth="1" />
                ))}
                {coordinates.length > 1 && (
                    <polyline
                        points={coordinates.map((point) => `${point.x},${point.y}`).join(' ')}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="3"
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                )}
                {coordinates.map((point) => (
                    <circle key={point.rowId} cx={point.x} cy={point.y} r="5" fill="currentColor">
                        <title>{`${formatMauritius(point.time)}: ${point.value} ${unit}`}</title>
                    </circle>
                ))}
                <text x={xPadding} y={15} className="fill-muted-foreground text-[11px]">
                    {maximum} {unit}
                </text>
                <text x={xPadding} y={height - 5} className="fill-muted-foreground text-[11px]">
                    {minimum} {unit}
                </text>
            </svg>
        </div>
    );
}

export default function Observations({
    patient,
    rows,
    types,
    canWrite,
    visits,
}: {
    patient: { id: number; name: string; reference: string };
    rows: Row[];
    types: Record<string, TypeInfo>;
    canWrite: boolean;
    visits: { id: number; service: string; starts_at: string; status: string }[];
}) {
    const typeEntries = useMemo(() => Object.entries(types), [types]);
    const [selected, setSelected] = useState<string[]>([]);
    const form = useForm({
        request_id: crypto.randomUUID(),
        appointment_id: '',
        observed_at: '',
        measurements: [] as { type: string; value: string; method: string }[],
        context: '',
        supersedes_id: '',
        correction_reason: '',
        password: '',
        declaration: false as boolean,
    });
    const corrected = useMemo(() => new Set(rows.map((row) => row.supersedes_id).filter((id): id is number => id !== null)), [rows]);
    const effectiveRows = useMemo(
        () =>
            rows
                .filter((row) => !corrected.has(row.id))
                .toSorted((left, right) => new Date(left.observed_at).getTime() - new Date(right.observed_at).getTime()),
        [corrected, rows],
    );
    const trends = useMemo(
        () =>
            typeEntries
                .map(([type, info]) => ({
                    type,
                    ...info,
                    points: effectiveRows.flatMap((row) => {
                        const measurement = row.measurements.find((item) => item.type === type);
                        const value = Number(measurement?.value);
                        return measurement && Number.isFinite(value) ? [{ rowId: row.id, time: new Date(row.observed_at).getTime(), value }] : [];
                    }),
                }))
                .filter((series) => series.points.length > 0),
        [effectiveRows, typeEntries],
    );
    const errors = form.errors as Record<string, string | undefined>;

    const toggle = (type: string) => {
        const next = selected.includes(type) ? selected.filter((item) => item !== type) : [...selected, type];
        setSelected(next);
        form.setData(
            'measurements',
            next.map((item) => form.data.measurements.find((measurement) => measurement.type === item) ?? { type: item, value: '', method: '' }),
        );
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/patient-records/${patient.id}/observations`, {
            preserveScroll: true,
            onSuccess: () => {
                setSelected([]);
                form.reset();
                form.setData('request_id', crypto.randomUUID());
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patient record', href: `/patient-records/${patient.id}` },
                { title: 'Observations', href: `/patient-records/${patient.id}/observations` },
            ]}
        >
            <Head title={`${patient.name} observations`} />
            <main className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold break-words">{patient.name}: observations</h1>
                        <p className="text-muted-foreground text-sm">
                            Chronological measured values in Mauritius time. No normal ranges, scores, alerts, or automated clinical interpretation
                            are applied.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={`/patient-records/${patient.id}`}>Patient record</Link>
                    </Button>
                </div>

                {canWrite && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Record and sign an observation set</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {visits.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No eligible assigned visit is available for recording observations.</p>
                            ) : (
                                <form className="space-y-5" onSubmit={submit}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label htmlFor="observation-visit">Assigned visit</Label>
                                            <select
                                                id="observation-visit"
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={form.data.appointment_id}
                                                onChange={(event) => form.setData('appointment_id', event.target.value)}
                                                required
                                            >
                                                <option value="">Choose visit</option>
                                                {visits.map((visit) => (
                                                    <option value={visit.id} key={visit.id}>
                                                        {visit.service} · {formatMauritius(visit.starts_at)} · {visit.status}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={form.errors.appointment_id} />
                                        </div>
                                        <div>
                                            <Label htmlFor="observed-at">Actual observation time (Mauritius)</Label>
                                            <Input
                                                id="observed-at"
                                                className="mt-1"
                                                type="datetime-local"
                                                value={form.data.observed_at}
                                                onChange={(event) => form.setData('observed_at', event.target.value)}
                                                required
                                            />
                                            <InputError message={form.errors.observed_at} />
                                        </div>
                                    </div>
                                    <fieldset>
                                        <legend className="font-medium">Measured types</legend>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            {typeEntries.map(([key, info]) => (
                                                <label
                                                    className="glass-subtle flex min-h-11 cursor-pointer items-center gap-3 rounded-xl p-3 text-sm"
                                                    key={key}
                                                >
                                                    <input type="checkbox" checked={selected.includes(key)} onChange={() => toggle(key)} />
                                                    <span>
                                                        {info.label} ({info.unit})
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                        <InputError message={form.errors.measurements} />
                                    </fieldset>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {form.data.measurements.map((measurement, index) => (
                                            <div className="glass-subtle rounded-xl p-3" key={measurement.type}>
                                                <Label htmlFor={`value-${measurement.type}`}>
                                                    {types[measurement.type].label} ({types[measurement.type].unit})
                                                </Label>
                                                <Input
                                                    id={`value-${measurement.type}`}
                                                    className="mt-1"
                                                    inputMode="decimal"
                                                    value={measurement.value}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'measurements',
                                                            form.data.measurements.map((item, itemIndex) =>
                                                                itemIndex === index ? { ...item, value: event.target.value } : item,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                                <InputError message={errors[`measurements.${index}.value`]} />
                                                <Label className="mt-3 block" htmlFor={`method-${measurement.type}`}>
                                                    Method, site, position or device context
                                                </Label>
                                                <Input
                                                    id={`method-${measurement.type}`}
                                                    className="mt-1"
                                                    value={measurement.method}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'measurements',
                                                            form.data.measurements.map((item, itemIndex) =>
                                                                itemIndex === index ? { ...item, method: event.target.value } : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                                <InputError message={errors[`measurements.${index}.method`]} />
                                            </div>
                                        ))}
                                    </div>
                                    <div>
                                        <Label htmlFor="observation-context">Set context</Label>
                                        <textarea
                                            id="observation-context"
                                            className="glass-input mt-1 min-h-24 w-full"
                                            value={form.data.context}
                                            onChange={(event) => form.setData('context', event.target.value)}
                                            maxLength={2000}
                                        />
                                        <InputError message={form.errors.context} />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label htmlFor="supersedes">Append-only correction of</Label>
                                            <select
                                                id="supersedes"
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={form.data.supersedes_id}
                                                onChange={(event) => {
                                                    form.setData('supersedes_id', event.target.value);
                                                    if (!event.target.value) form.setData('correction_reason', '');
                                                }}
                                            >
                                                <option value="">Not a correction</option>
                                                {rows
                                                    .filter((row) => !corrected.has(row.id))
                                                    .map((row) => (
                                                        <option value={row.id} key={row.id}>
                                                            Set #{row.id} · {formatMauritius(row.observed_at)}
                                                        </option>
                                                    ))}
                                            </select>
                                            <InputError message={form.errors.supersedes_id} />
                                        </div>
                                        <div>
                                            <Label htmlFor="correction-reason">Correction reason</Label>
                                            <Input
                                                id="correction-reason"
                                                className="mt-1"
                                                value={form.data.correction_reason}
                                                onChange={(event) => form.setData('correction_reason', event.target.value)}
                                                disabled={!form.data.supersedes_id}
                                                required={Boolean(form.data.supersedes_id)}
                                            />
                                            <InputError message={form.errors.correction_reason} />
                                        </div>
                                    </div>
                                    <div>
                                        <Label htmlFor="observation-password">Confirm your password</Label>
                                        <Input
                                            id="observation-password"
                                            className="mt-1 max-w-md"
                                            type="password"
                                            value={form.data.password}
                                            onChange={(event) => form.setData('password', event.target.value)}
                                            required
                                        />
                                        <InputError message={form.errors.password} />
                                    </div>
                                    <label className="flex min-h-11 items-start gap-3 text-sm">
                                        <input
                                            className="mt-1"
                                            type="checkbox"
                                            checked={form.data.declaration}
                                            onChange={(event) => form.setData('declaration', event.target.checked)}
                                        />
                                        <span>
                                            I personally recorded these measured values and confirm their time, visit and context. Signing makes this
                                            set immutable; corrections create a linked new set.
                                        </span>
                                    </label>
                                    <InputError message={form.errors.declaration} />
                                    <Button disabled={form.processing || selected.length === 0}>Sign observation set</Button>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}

                <section aria-labelledby="observation-trends" className="space-y-4">
                    <div>
                        <h2 id="observation-trends" className="text-xl font-semibold">
                            Measurement trends
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Each scale is based only on the recorded values shown. Superseded sets are excluded.
                        </p>
                    </div>
                    {trends.length > 0 ? (
                        <div className="grid gap-4 xl:grid-cols-2">
                            {trends.map((trend) => {
                                const latest = trend.points.at(-1);
                                return (
                                    <Card key={trend.type}>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="flex flex-wrap items-baseline justify-between gap-2 text-base">
                                                <span>{trend.label}</span>
                                                {latest && (
                                                    <span className="text-muted-foreground text-sm font-normal">
                                                        Latest recorded: {latest.value} {trend.unit}
                                                    </span>
                                                )}
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <TrendChart id={trend.type} points={trend.points} label={trend.label} unit={trend.unit} />
                                            <p className="text-muted-foreground mt-2 text-xs">
                                                {trend.points.length} retained effective value{trend.points.length === 1 ? '' : 's'}.
                                            </p>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : (
                        <Card>
                            <CardContent className="text-muted-foreground py-6 text-sm">No effective values are available to chart.</CardContent>
                        </Card>
                    )}
                </section>

                <Card>
                    <CardHeader>
                        <CardTitle>Retained observation sets</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="p-3">Time</th>
                                        <th className="p-3">Values</th>
                                        <th className="p-3">Context</th>
                                        <th className="p-3">Visit / author</th>
                                        <th className="p-3">Record</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr className={`border-b align-top ${corrected.has(row.id) ? 'opacity-60' : ''}`} key={row.id}>
                                            <td className="p-3 whitespace-nowrap">{formatMauritius(row.observed_at)}</td>
                                            <td className="p-3">
                                                {row.measurements.map((measurement) => (
                                                    <div className="whitespace-nowrap" key={measurement.type}>
                                                        <strong>{types[measurement.type]?.label ?? measurement.type}:</strong> {measurement.value}{' '}
                                                        {measurement.unit}
                                                        {measurement.method ? ` · ${measurement.method}` : ''}
                                                    </div>
                                                ))}
                                            </td>
                                            <td className="max-w-xs p-3 break-words">
                                                {row.context || '—'}
                                                {row.correction_reason && (
                                                    <div className="mt-2 text-xs">
                                                        <strong>Correction reason:</strong> {row.correction_reason}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="p-3">
                                                {row.appointment?.service}
                                                <br />
                                                <span className="text-muted-foreground">{row.author?.name}</span>
                                            </td>
                                            <td className="p-3">
                                                #{row.id}
                                                {row.supersedes_id && <div>Corrects #{row.supersedes_id}</div>}
                                                {corrected.has(row.id) && <div>Superseded</div>}
                                                <div className="text-muted-foreground mt-1 font-mono text-[10px]">
                                                    {row.content_hash.slice(0, 12)}…
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {!rows.length && <p className="text-muted-foreground py-6 text-sm">No observation sets recorded.</p>}
                    </CardContent>
                </Card>
            </main>
        </AppLayout>
    );
}
