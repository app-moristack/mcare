import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { AccessRule, date, History, title } from './fields';
import { RuleList } from './rules';
import { Content, SummaryDisplay } from './summary';
export default function PatientAccess({
    patient: p,
    rules,
    history,
    summary,
}: {
    patient: { id: number; name: string; reference: string };
    rules: AccessRule[];
    history: History;
    summary: { content: Content; version: number; updated_at: string } | null;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'My care', href: '/my-care' },
                { title: 'My record and access', href: `/my-care/patients/${p.id}` },
            ]}
        >
            <Head title="My record and access" />
            <main className="mx-auto w-full max-w-5xl min-w-0 space-y-5 p-3 sm:p-6">
                <Link className="text-primary text-sm" href="/my-care">
                    Back to my care
                </Link>
                <header className="surface space-y-3 p-4 sm:p-6">
                    <h1 className="text-2xl font-semibold break-words">{p.name}: my record and access</h1>
                    <p className="text-muted-foreground font-mono text-xs break-all">{p.reference}</p>
                    <p className="text-muted-foreground text-sm">
                        See who has recorded authority, revoke a grant or block a professional. Blocks take precedence over clinical summary grants.
                        Contact your care team to arrange safe ongoing care after restricting access.
                    </p>
                </header>
                <Button asChild>
                    <Link href={`/my-care/patients/${p.id}/assessments`}>My basic assessments</Link>
                </Button>
                <Button asChild variant="outline">
                    <Link href={`/my-care/patients/${p.id}/clinical`}>Published summaries and care plans</Link>
                </Button>
                <div className="grid items-start gap-5 lg:grid-cols-2">
                    <section className="surface space-y-5 p-4 sm:p-6">
                        <h2 className="text-lg font-semibold">My clinical summary</h2>
                        {summary ? (
                            <>
                                <p className="text-muted-foreground text-xs">
                                    Last reviewed {date(summary.updated_at)} · Revision {summary.version}
                                </p>
                                <SummaryDisplay content={summary.content} />
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">No clinical summary recorded yet.</p>
                        )}
                    </section>
                    <section className="surface space-y-5 p-4 sm:p-6">
                        <h2 className="text-lg font-semibold">Professionals and access rules</h2>
                        <RuleList rules={rules} base={`/my-care/patients/${p.id}`} patient />
                    </section>
                </div>
                <section className="surface p-4 sm:p-6">
                    <h2 className="text-lg font-semibold">Recent record access and changes</h2>
                    <div className="mt-4 divide-y">
                        {history.map((h) => (
                            <div className="grid gap-1 py-3 text-sm sm:grid-cols-3" key={h.id}>
                                <span className="break-words">{title(h.event)}</span>
                                <span>{h.actor ?? 'System'}</span>
                                <time className="text-muted-foreground">{date(h.created_at)}</time>
                            </div>
                        ))}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Latest 100 events. Private nursing assessments and staff-only records are not included here.
                    </p>
                </section>
            </main>
        </AppLayout>
    );
}
