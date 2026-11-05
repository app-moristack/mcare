import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { date, title } from '@/pages/patients/fields';
import { Head, Link } from '@inertiajs/react';
export default function PublishedCare({
    patient: p,
    items,
}: {
    patient: { id: number; name: string; reference: string };
    items: {
        id: number;
        kind: string;
        record_id: number;
        original_id: number | null;
        revision_number: number | null;
        status: string;
        content: { summary: string; shared_goals?: string };
        published_at: string;
    }[];
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'My care', href: '/my-care' },
                { title: 'Published care', href: `/my-care/patients/${p.id}/clinical` },
            ]}
        >
            <Head title="My published care" />
            <main className="mx-auto w-full max-w-4xl min-w-0 space-y-5 p-3 sm:p-6">
                <header className="surface space-y-3 p-4 sm:p-6">
                    <h1 className="text-2xl font-semibold break-words">{p.name}: published care</h1>
                    <p className="text-muted-foreground text-sm">
                        Your care team's reviewed summaries and shared goals. Older plan versions are retained as history. Private professional
                        records are not included here.
                    </p>
                    <Button asChild variant="outline">
                        <Link href={`/my-care/patients/${p.id}`}>Back to my record</Link>
                    </Button>
                </header>
                {!items.length && (
                    <section className="surface text-muted-foreground p-6 text-sm">
                        Your team has not published any note summaries or shared plans yet.
                    </section>
                )}
                {items.map((v) => (
                    <article key={v.id} className="surface space-y-4 p-4 sm:p-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <h2 className="font-semibold">
                                {v.kind === 'notes'
                                    ? `${v.original_id ? 'Addendum' : 'Visit summary'} #${v.record_id}`
                                    : `Care plan · Version ${v.revision_number}`}
                            </h2>
                            <Badge variant={v.status === 'active' ? 'success' : 'secondary'}>{title(v.status)}</Badge>
                        </div>
                        {v.original_id && <p className="text-sm">Correction / addition to record #{v.original_id}</p>}
                        {v.kind === 'plans' && !['active', 'paused'].includes(v.status) && (
                            <p className="text-sm font-medium">Historical version — check your current plan with your care team.</p>
                        )}
                        {v.status === 'paused' && (
                            <p className="text-sm font-medium">This plan is paused. Contact your care team for the current arrangements.</p>
                        )}
                        <p className="text-muted-foreground text-sm">Published {date(v.published_at)}</p>
                        <p className="text-sm break-words whitespace-pre-wrap">{v.content.summary}</p>
                        {v.content.shared_goals && (
                            <div className="space-y-2">
                                <h3 className="font-medium">Shared goals</h3>
                                <p className="text-sm break-words whitespace-pre-wrap">{v.content.shared_goals}</p>
                            </div>
                        )}
                    </article>
                ))}
            </main>
        </AppLayout>
    );
}
