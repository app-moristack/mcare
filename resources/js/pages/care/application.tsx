import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
export default function Application({
    application,
    organisations,
}: {
    application: {
        status: string;
        organisation_id: number | null;
        discipline: string | null;
        credential_reference: string | null;
        credential_expires_at: string | null;
    };
    organisations: { id: number; name: string }[];
}) {
    const form = useForm({
        organisation_id: String(application.organisation_id ?? ''),
        discipline: application.discipline ?? '',
        credential_reference: application.credential_reference ?? '',
        credential_expires_at: application.credential_expires_at ?? '',
    });
    return (
        <AppLayout breadcrumbs={[{ title: 'Professional application', href: '/application' }]}>
            <Head title="Professional application" />
            <main className="mx-auto w-full max-w-2xl p-5 md:p-8">
                <h1 className="text-2xl font-semibold">Professional application</h1>
                <p className="text-muted-foreground mt-2">Current status: {application.status}. Applying never grants patient or clinical access.</p>
                <form
                    className="surface mt-6 space-y-5 p-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/application');
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="organisation">Organisation</Label>
                        <select
                            id="organisation"
                            required
                            value={form.data.organisation_id}
                            onChange={(e) => form.setData('organisation_id', e.target.value)}
                        >
                            <option value="">Select organisation</option>
                            {organisations.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="discipline">Discipline</Label>
                        <select id="discipline" required value={form.data.discipline} onChange={(e) => form.setData('discipline', e.target.value)}>
                            <option value="">Select discipline</option>
                            {['doctor', 'nurse', 'carer', 'physiotherapist'].map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="credential">Professional registration / credential reference</Label>
                        <Input
                            id="credential"
                            required
                            value={form.data.credential_reference}
                            onChange={(e) => form.setData('credential_reference', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="expiry">Credential expiry / re-review date</Label>
                        <Input
                            id="expiry"
                            type="date"
                            required
                            value={form.data.credential_expires_at}
                            onChange={(e) => form.setData('credential_expires_at', e.target.value)}
                        />
                    </div>
                    {Object.values(form.errors).map((error, i) => (
                        <p key={i} role="alert" className="text-destructive">
                            {error}
                        </p>
                    ))}
                    <Button disabled={form.processing || application.status !== 'pending'}>Submit for review</Button>
                    <p className="text-muted-foreground text-sm">
                        The reviewer must verify your credential source and evidence separately.{' '}
                        <Link href="/security/mfa" className="text-primary underline">
                            Optional authenticator setup
                        </Link>{' '}
                        is available.
                    </p>
                </form>
            </main>
        </AppLayout>
    );
}
