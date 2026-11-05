import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Clock, MapPin } from 'lucide-react';
import { useState } from 'react';
type Visit = {
    id: number;
    service: string;
    status: string;
    starts_at: string;
    ends_at: string;
    location: string;
    patient: { id: number; name: string; reference: string };
    attendance: { kind: string; occurred_at: string }[];
};
export default function Visits({ visits }: { visits: { data: Visit[]; links: { url: string | null; label: string }[] } }) {
    const [active, setActive] = useState<Visit>();
    const [kind, setKind] = useState('arrival');
    const [reason, setReason] = useState('');
    const [capture, setCapture] = useState<{ status: string; latitude?: number; longitude?: number; accuracy?: number }>({ status: 'unavailable' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [eventId, setEventId] = useState('');
    const locate = () => {
        setCapture({ status: 'unavailable' });
        if (!navigator.geolocation) return;
        setBusy(true);
        navigator.geolocation.getCurrentPosition(
            (p) => {
                setCapture({ status: 'obtained', latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy });
                setBusy(false);
            },
            (e) => {
                setCapture({ status: e.code === 1 ? 'denied' : e.code === 3 ? 'timeout' : 'unavailable' });
                setBusy(false);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    };
    return (
        <AppLayout breadcrumbs={[{ title: 'My visits', href: '/my-visits' }]}>
            <Head title="My visits" />
            <main className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">My visits</h1>
                    <p className="text-muted-foreground mt-2">
                        Only your assigned visits. Location is captured on arrival/departure only, never in the background.
                    </p>
                </div>
                <div className="grid gap-5 lg:grid-cols-2">
                    {visits.data.map((v) => (
                        <section key={v.id} className="surface p-5">
                            <div className="flex flex-wrap justify-between gap-4">
                                <div>
                                    <h2 className="text-lg font-semibold">{v.patient.name}</h2>
                                    <Link className="text-primary text-sm" href={`/patient-records/${v.patient.id}`}>
                                        Open authorised record
                                    </Link>
                                    <Link className="text-primary ml-3 text-sm" href={`/patient-records/${v.patient.id}/clinical`}>
                                        Notes and care plans
                                    </Link>
                                    <Link className="text-primary ml-3 text-sm" href={`/patient-records/${v.patient.id}/assessments`}>
                                        Assessments
                                    </Link>
                                    <p className="text-muted-foreground mt-1 text-xs break-all">{v.patient.reference}</p>
                                </div>
                                <Badge variant="secondary">{v.status}</Badge>
                            </div>
                            <p className="mt-5 font-medium">{v.service}</p>
                            <p className="mt-3 flex items-center gap-2 text-sm">
                                <Clock className="text-muted-foreground size-4" />
                                {new Date(v.starts_at).toLocaleString('en-GB', { timeZone: 'Indian/Mauritius' })}
                            </p>
                            <p className="mt-2 flex items-center gap-2 text-sm">
                                <MapPin className="text-muted-foreground size-4" />
                                {v.location}
                            </p>
                            <div className="text-muted-foreground mt-5 space-y-2 text-sm">
                                {v.attendance.map((a) => (
                                    <p key={a.kind}>
                                        {a.kind}: {new Date(a.occurred_at).toLocaleString('en-GB', { timeZone: 'Indian/Mauritius' })}
                                    </p>
                                ))}
                            </div>
                            {(v.status === 'confirmed' || (v.status === 'arrived' && !v.attendance.some((a) => a.kind === 'departure'))) && (
                                <Button
                                    className="mt-5"
                                    onClick={() => {
                                        setActive(v);
                                        setKind(v.status === 'confirmed' ? 'arrival' : 'departure');
                                        setEventId(crypto.randomUUID());
                                        setCapture({ status: 'unavailable' });
                                        setReason('');
                                        setError('');
                                    }}
                                >
                                    Record {v.status === 'confirmed' ? 'arrival' : 'departure'}
                                </Button>
                            )}
                        </section>
                    ))}
                </div>
                {!visits.data.length && (
                    <div className="surface text-muted-foreground p-12 text-center">
                        No assigned visits. Your organisation coordinates your schedule after approval.
                    </div>
                )}
                <div className="flex justify-end gap-2">
                    {visits.links
                        .filter((l) => l.url)
                        .map((l, i) => (
                            <Link key={i} href={l.url!} className="rounded border px-3 py-1">
                                {l.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                            </Link>
                        ))}
                </div>
                {active && (
                    <Dialog open onOpenChange={(v) => !v && setActive(undefined)}>
                        <DialogContent>
                            <DialogTitle>Record {kind}</DialogTitle>
                            <DialogDescription>
                                {active.patient.name} · {active.service}. The server records the actual event time. If location cannot be obtained,
                                explain why and continue.
                            </DialogDescription>
                            <form
                                className="space-y-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    setBusy(true);
                                    router.post(
                                        `/my-visits/${active.id}/attendance`,
                                        {
                                            request_id: eventId,
                                            kind,
                                            capture_status: capture.status,
                                            latitude: capture.latitude ?? null,
                                            longitude: capture.longitude ?? null,
                                            accuracy: capture.accuracy ?? null,
                                            exception_reason: reason,
                                        },
                                        {
                                            onSuccess: () => setActive(undefined),
                                            onError: (errors) => setError(Object.values(errors).join(' ')),
                                            onFinish: () => setBusy(false),
                                        },
                                    );
                                }}
                            >
                                <Button type="button" variant="outline" disabled={busy} onClick={locate}>
                                    <MapPin />
                                    Capture this event’s location
                                </Button>
                                <p className="text-muted-foreground text-sm">
                                    Location: {capture.status}
                                    {capture.accuracy !== undefined ? ` (accuracy ${Math.round(capture.accuracy)} m)` : ''}
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor="exception">
                                        Location exception reason {capture.status === 'obtained' ? '(optional)' : '(required)'}
                                    </Label>
                                    <textarea
                                        id="exception"
                                        required={capture.status !== 'obtained'}
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        className="min-h-24 rounded-md border p-3"
                                    />
                                </div>
                                {error && (
                                    <p role="alert" className="text-destructive">
                                        {error}
                                    </p>
                                )}
                                <Button disabled={busy}>Save {kind}</Button>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </main>
        </AppLayout>
    );
}
