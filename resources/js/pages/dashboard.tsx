import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, CheckCircle2, Circle, ClipboardList, FileHeart, ShieldCheck, Users } from 'lucide-react';
type Profile = { public_id: string; onboarding_completed_at: string | null } | null;
export default function Dashboard({
    patientProfile,
    application,
    adminAccess,
}: {
    patientProfile: Profile;
    application: { status: string } | null;
    adminAccess: { roles: string[]; permissions: string[] } | null;
}) {
    const { auth } = usePage<SharedData>().props;
    const verified = !!auth.user.email_verified_at;
    const complete = !!patientProfile?.onboarding_completed_at;
    const tasks = [verified, complete, false];
    return (
        <AppLayout breadcrumbs={[{ title: 'Overview', href: '/dashboard' }]}>
            <Head title="Your overview" />
            <main className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-muted-foreground text-xs tracking-widest uppercase">Your MauriCare space</p>
                        <h1 className="mt-3 text-3xl font-semibold tracking-tight">Welcome, {auth.user.name.split(' ')[0]}.</h1>
                        <p className="text-muted-foreground mt-2">Let’s get your account ready for your care journey.</p>
                    </div>
                    <span className="bg-accent text-accent-foreground rounded-full px-4 py-2 text-xs font-medium">
                        {adminAccess
                            ? 'Administrator'
                            : application
                              ? 'Application pending'
                              : auth.user.account_kind === 'proxy'
                                ? 'Representative account'
                                : 'Personal account'}
                    </span>
                </div>
                {!verified && (
                    <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-950">
                        <div>
                            <h2 className="font-semibold">Verify your email address</h2>
                            <p className="mt-1 text-sm">Check your inbox before adding personal information.</p>
                        </div>
                        <Link href="/verify-email" className="text-sm font-semibold underline">
                            Verify email
                        </Link>
                    </div>
                )}
                {adminAccess ? (
                    <section className="bg-card rounded-lg border p-6 shadow-xs">
                        <ShieldCheck className="text-primary size-9" />
                        <h2 className="mt-4 text-2xl font-semibold">Administration workspace</h2>
                        <p className="text-muted-foreground mt-3">
                            Your administrator account has all registered roles and permissions for MauriCare. Clinical and operations modules will
                            appear as they are implemented.
                        </p>
                        <Link
                            href="/workspace/overview"
                            className="bg-primary text-primary-foreground mt-5 inline-flex items-center gap-3 rounded-md px-5 py-3 text-sm font-medium"
                        >
                            Open command centre <ArrowUpRight className="size-4" />
                        </Link>
                        <h3 className="mt-6 font-semibold">Assigned roles</h3>
                        <div className="mt-4 max-h-80 overflow-auto rounded-md border">
                            <Table aria-label="Assigned administrator roles">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Assignment</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {adminAccess.roles.map((role) => (
                                        <TableRow key={role}>
                                            <TableCell className="font-medium capitalize">{role.replaceAll('_', ' ')}</TableCell>
                                            <TableCell>
                                                <Badge variant="success">Assigned</Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <h3 className="mt-6 font-semibold">Current permissions</h3>
                        <ul className="mt-3 space-y-2 text-sm">
                            {adminAccess.permissions.map((permission) => (
                                <li key={permission}>{permission}</li>
                            ))}
                        </ul>
                    </section>
                ) : application ? (
                    <section className="bg-card rounded-lg border p-6 shadow-xs">
                        <ShieldCheck className="text-primary size-9" />
                        <h2 className="mt-4 text-2xl font-semibold">Your application is awaiting review</h2>
                        <Link href="/application" className="text-primary mt-4 inline-block font-medium underline">
                            Complete your professional application
                        </Link>
                        <p className="text-muted-foreground mt-3 max-w-xl leading-relaxed">
                            Thank you for applying to join MauriCare. Professional and partner access requires identity, credentials, organisation
                            approval. Optional authenticator security is available. Your account currently has no staff or patient-record access.
                        </p>
                    </section>
                ) : patientProfile ? (
                    <section className="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                        <div className="bg-primary rounded-lg p-7 text-white">
                            <span className="text-primary-foreground/80 text-xs tracking-widest uppercase">Getting started</span>
                            <h2 className="mt-4 text-2xl font-medium">A few steps to make it yours</h2>
                            <p className="text-primary-foreground/80 mt-3 text-sm">{tasks.filter(Boolean).length} of 3 steps complete</p>
                            <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-white/15">
                                <div className="h-full rounded-full bg-blue-300" style={{ width: `${(tasks.filter(Boolean).length / 3) * 100}%` }} />
                            </div>
                            <div className="mt-7 space-y-5">
                                {[
                                    ['Verify your email', verified],
                                    ['Complete your basic details', complete],
                                    ['Identity review — not yet available', false],
                                ].map(([label, done]) => (
                                    <div key={String(label)} className="flex items-center gap-3 text-sm">
                                        {done ? (
                                            <CheckCircle2 className="text-primary-foreground/80 size-5" />
                                        ) : (
                                            <Circle className="text-primary-foreground/50 size-5" />
                                        )}
                                        {label}
                                    </div>
                                ))}
                            </div>
                            <Link
                                href={verified ? '/onboarding' : '/verify-email'}
                                className="text-primary-foreground mt-8 inline-flex items-center gap-6 rounded-lg bg-white px-5 py-3 text-sm font-semibold"
                            >
                                {complete ? 'Review your details' : 'Continue setup'}
                                <ArrowUpRight className="size-4" />
                            </Link>
                        </div>
                        <div className="bg-card rounded-lg border p-6 shadow-xs">
                            <FileHeart className="text-primary size-9" />
                            <h2 className="mt-5 text-xl font-semibold">Your personal profile</h2>
                            <p className="text-muted-foreground mt-3 text-sm leading-relaxed">
                                Account verification and clinical identity are separate. Your identity status is{' '}
                                <strong>{auth.user.identity_status}</strong>.
                            </p>
                            <p className="text-muted-foreground mt-6 text-xs">Profile reference</p>
                            <p className="mt-2 font-mono text-xs break-all">{patientProfile.public_id}</p>
                            <p className="text-muted-foreground mt-6 text-xs">
                                Care time zone: Indian/Mauritius
                                <br />
                                Currency: MUR
                            </p>
                        </div>
                    </section>
                ) : (
                    <section className="bg-card rounded-lg border p-6 shadow-xs">
                        <Users className="text-primary" />
                        <h2 className="mt-4 text-xl font-semibold">Representative access needs separate approval</h2>
                        <p className="text-muted-foreground mt-2">
                            Creating this account does not grant access to another person’s records. Patient linking and authority review will be
                            added in a later phase.
                        </p>
                    </section>
                )}
                <section>
                    <h2 className="text-lg font-semibold">Your care, taking shape</h2>
                    <p className="text-muted-foreground mt-1 text-sm">These services are planned for the next build phases.</p>
                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        {[
                            [ClipboardList, 'Appointments', 'Your upcoming care visits.'],
                            [FileHeart, 'Health records', 'Your patient-visible care information.'],
                            [Users, 'Care team', 'Your approved care relationships.'],
                        ].map(([Icon, title, body]) => {
                            const I = Icon as typeof Users;
                            return (
                                <div key={String(title)} className="bg-card rounded-lg border p-5 shadow-xs">
                                    <I className="text-primary size-5" />
                                    <h3 className="mt-4 font-medium">{String(title)}</h3>
                                    <p className="text-muted-foreground mt-2 text-sm">{String(body)}</p>
                                    <span className="text-muted-foreground bg-muted mt-5 inline-block rounded-full px-3 py-1 text-xs">
                                        Coming later
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </section>
                <div className="flex flex-wrap justify-between gap-4 border-t pt-5 text-sm">
                    <Link href="/settings/profile" className="font-medium underline">
                        Account settings
                    </Link>
                </div>
            </main>
        </AppLayout>
    );
}
