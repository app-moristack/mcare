import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
export default function CarePortal({
    organisations,
    patients,
    appointments,
    invoices,
}: {
    organisations: { id: number; name: string }[];
    patients: { id: number; reference: string; organisation_id: number; status: string }[];
    appointments: {
        data: { id: number; service: string; starts_at: string; status: string; location: string; provider: { name: string } }[];
        links: { url: string | null; label: string }[];
    };
    invoices: {
        id: number;
        number: string;
        status: string;
        due_date: string;
        total_minor: number;
        payments: { amount_minor: number }[];
        items: { id: number; description: string; quantity: number; total_minor: number }[];
    }[];
}) {
    const form = useForm<{ organisation_id: string; authority_acceptance: boolean }>({ organisation_id: '', authority_acceptance: false });
    const money = (n: number) => new Intl.NumberFormat('en-MU', { style: 'currency', currency: 'MUR' }).format(n / 100);
    return (
        <AppLayout breadcrumbs={[{ title: 'My care', href: '/my-care' }]}>
            <Head title="My care" />
            <main className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">My care</h1>
                    <p className="text-muted-foreground mt-2">
                        Your care requests, appointments and issued bills. Times are shown in Mauritius time.
                    </p>
                </div>
                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="surface p-5">
                        <h2 className="text-lg font-semibold">Connect with a care organisation</h2>
                        <p className="text-muted-foreground mt-2 text-sm">
                            Complete{' '}
                            <Link href="/onboarding" className="text-primary underline">
                                your basic details
                            </Link>{' '}
                            first. This request shares your demographics for coordination; it does not grant clinical-chart or family access.
                        </p>
                        <form
                            className="mt-5 space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/my-care/request');
                            }}
                        >
                            <Label htmlFor="care-organisation">Care organisation</Label>
                            <select
                                id="care-organisation"
                                required
                                className="w-full"
                                value={form.data.organisation_id}
                                onChange={(e) => form.setData('organisation_id', e.target.value)}
                            >
                                <option value="">Select an organisation</option>
                                {organisations.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </select>
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    required
                                    checked={form.data.authority_acceptance}
                                    onChange={(e) => form.setData('authority_acceptance', e.target.checked)}
                                    className="mt-1"
                                />
                                <span>
                                    I request care coordination and agree to share my account’s basic profile details with this organisation for that
                                    purpose.
                                </span>
                            </label>
                            {Object.values(form.errors).map((error, i) => (
                                <p key={i} role="alert" className="text-destructive text-sm">
                                    {error}
                                </p>
                            ))}
                            <Button disabled={form.processing}>Send care request</Button>
                        </form>
                    </section>
                    <section className="surface p-5">
                        <h2 className="text-lg font-semibold">My organisation records</h2>
                        {patients.length ? (
                            patients.map((p) => (
                                <div key={p.id} className="mt-4 rounded-md border p-4">
                                    <p className="font-medium">{organisations.find((o) => o.id === p.organisation_id)?.name}</p>
                                    <p className="text-muted-foreground mt-2 font-mono text-xs break-all">{p.reference}</p>
                                    <Badge variant="secondary" className="mt-3">
                                        {p.status}
                                    </Badge>
                                </div>
                            ))
                        ) : (
                            <p className="text-muted-foreground py-8 text-sm">
                                No organisation record linked yet. Records are never linked automatically by name or phone.
                            </p>
                        )}
                    </section>
                </div>
                <section className="surface p-5">
                    <h2 className="text-lg font-semibold">Appointments</h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Service</TableHead>
                                <TableHead>Time</TableHead>
                                <TableHead>Provider / location</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {appointments.data.map((v) => (
                                <TableRow key={v.id}>
                                    <TableCell>{v.service}</TableCell>
                                    <TableCell>{new Date(v.starts_at).toLocaleString('en-GB', { timeZone: 'Indian/Mauritius' })}</TableCell>
                                    <TableCell>
                                        {v.provider.name}
                                        <p className="text-muted-foreground text-xs">{v.location}</p>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary">{v.status}</Badge>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {!appointments.data.length && (
                                <TableRow>
                                    <TableCell colSpan={4} className="text-muted-foreground py-10 text-center">
                                        No appointments yet.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                    <div className="mt-4 flex justify-end gap-3">
                        {appointments.links
                            .filter((l) => l.url)
                            .map((l, i) => (
                                <Link className="rounded-md border px-3 py-1 text-sm" key={i} href={l.url!}>
                                    {l.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                                </Link>
                            ))}
                    </div>
                </section>
                <section className="surface p-5">
                    <h2 className="text-lg font-semibold">Bills & payment history</h2>
                    {invoices.length ? (
                        invoices.map((invoice) => (
                            <details key={invoice.id} className="mt-4 rounded-md border p-4">
                                <summary className="cursor-pointer font-medium">
                                    {invoice.number} · {money(invoice.total_minor)} · {invoice.status}
                                </summary>
                                <div className="mt-4 space-y-3 text-sm">
                                    {invoice.items.map((i) => (
                                        <p key={i.id}>
                                            {i.quantity} × {i.description} <span className="float-right">{money(i.total_minor)}</span>
                                        </p>
                                    ))}
                                    <p className="border-t pt-3 font-medium">
                                        Outstanding: {money(invoice.total_minor - invoice.payments.reduce((n, p) => n + p.amount_minor, 0))}
                                    </p>
                                    <p className="text-muted-foreground">
                                        Due {invoice.due_date}. Payments appear after finance reconciliation. Online payment is not available.
                                    </p>
                                </div>
                            </details>
                        ))
                    ) : (
                        <p className="text-muted-foreground py-8 text-sm">No issued invoices.</p>
                    )}
                </section>
            </main>
        </AppLayout>
    );
}
