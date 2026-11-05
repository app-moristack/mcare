import { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarDays, FileHeart, HeartPulse, ShieldCheck, Users } from 'lucide-react';
export default function Welcome() {
    const { auth } = usePage<SharedData>().props;
    return (
        <>
            <Head title="Care, connected" />
            <div className="min-h-screen bg-[#f8f9fb] text-[#111827]">
                <header className="mx-auto flex max-w-7xl items-center justify-between px-6 py-7 lg:px-12">
                    <Link href="/" className="flex items-center gap-2 text-2xl font-semibold">
                        <HeartPulse className="size-8 text-blue-700" />
                        MauriCare
                    </Link>
                    <nav className="flex items-center gap-5 text-sm">
                        <span className="hidden text-blue-800/60 sm:block">Made for Mauritius</span>
                        <Link href={auth.user ? '/dashboard' : '/login'} className="font-semibold">
                            {auth.user ? 'My dashboard' : 'Log in'} <span aria-hidden>↗</span>
                        </Link>
                    </nav>
                </header>
                <main className="mx-auto max-w-7xl px-6 lg:px-12">
                    <section className="grid items-center gap-12 py-14 lg:grid-cols-2 lg:py-24">
                        <div>
                            <div className="mb-7 flex items-center gap-2 text-xs font-semibold tracking-[0.2em] uppercase">
                                <span className="size-2 rounded-full bg-blue-600" />
                                Care, connected.
                            </div>
                            <h1 className="text-5xl leading-[1.08] font-medium tracking-tight sm:text-7xl">
                                Your health.
                                <br />
                                Your people.
                                <br />
                                <span className="text-blue-600">One place.</span>
                            </h1>
                            <p className="mt-7 max-w-md text-lg leading-relaxed text-blue-950/65">
                                A more connected care journey starts here. Create your account, organise your information and take the next step with
                                MauriCare.
                            </p>
                            <Link
                                href={auth.user ? '/dashboard' : '/register'}
                                className="bg-primary mt-9 inline-flex items-center gap-6 rounded-md px-7 py-4 font-medium text-white hover:bg-blue-800"
                            >
                                {auth.user ? 'Open your dashboard' : 'Create your account'}
                                <ArrowRight className="size-5" />
                            </Link>
                            <p className="mt-5 text-xs text-blue-950/55">For patients, families and care professionals.</p>
                        </div>
                        <div className="relative rounded-[2rem] bg-[#eff6ff] p-7 sm:p-12">
                            <div className="mb-12 flex items-center justify-between">
                                <span className="rounded-full border border-blue-900/20 px-4 py-2 text-xs">A better beginning</span>
                                <HeartPulse className="size-10 text-blue-700" />
                            </div>
                            <h2 className="max-w-xs text-3xl leading-tight font-medium">
                                Care starts with
                                <br />
                                knowing you.
                            </h2>
                            <div className="mt-8 space-y-3">
                                {[
                                    [FileHeart, 'Your personal space', 'Begin with your account and profile.'],
                                    [Users, 'A connected care team', 'Professional access requires approval.'],
                                    [ShieldCheck, 'Clear permissions', 'Family access needs patient authority.'],
                                ].map(([Icon, title, body]) => {
                                    const I = Icon as typeof FileHeart;
                                    return (
                                        <div key={String(title)} className="flex gap-4 rounded-lg bg-white/80 p-5">
                                            <I className="mt-1 size-6 shrink-0 text-blue-700" />
                                            <div>
                                                <h3 className="text-sm font-semibold">{String(title)}</h3>
                                                <p className="mt-1 text-xs leading-relaxed text-blue-950/65">{String(body)}</p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="mt-7 flex items-center gap-2 text-xs text-blue-900/60">
                                <CalendarDays className="size-4" />
                                Designed around everyday care
                            </div>
                        </div>
                    </section>
                    <section className="grid gap-8 border-t border-blue-900/15 py-10 sm:grid-cols-3">
                        {[
                            ['01', 'For you & your family', 'Start a personal account or request representative access.'],
                            ['02', 'For professionals', 'Apply to join the care network. Verification comes first.'],
                            ['03', 'For care partners', 'Register your interest in working with MauriCare.'],
                        ].map(([n, title, body]) => (
                            <div key={n}>
                                <span className="text-xs text-blue-700">{n}</span>
                                <h3 className="mt-3 font-semibold">{title}</h3>
                                <p className="mt-2 max-w-xs text-sm leading-relaxed text-blue-950/60">{body}</p>
                            </div>
                        ))}
                    </section>
                </main>
                <footer className="mx-auto flex max-w-7xl flex-wrap justify-between gap-4 px-6 py-8 text-xs text-blue-950/55 lg:px-12">
                    <span>© {new Date().getFullYear()} MauriCare · Development preview — fictitious data only</span>
                    <div className="flex gap-5">
                        <Link href="/privacy">Privacy notice</Link>
                        <Link href="/terms">Terms</Link>
                    </div>
                </footer>
            </div>
        </>
    );
}
