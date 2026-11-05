import { Head, Link } from '@inertiajs/react';
export default function Legal({ kind }: { kind: 'terms' | 'privacy' }) {
    const title = kind === 'terms' ? 'Development terms' : 'Draft privacy notice';
    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl px-6 py-16">
                <Link href="/" className="font-semibold text-blue-700">
                    ← MauriCare
                </Link>
                <p className="text-muted-foreground mt-10 text-sm">Draft version · 5 November 2026</p>
                <h1 className="mt-3 text-3xl font-semibold">{title}</h1>
                <div className="mt-8 space-y-5 leading-relaxed">
                    <p>
                        This is a development preview for testing with fictitious information. It is not ready for real patient records or delivery of
                        care.
                    </p>
                    {kind === 'terms' ? (
                        <>
                            <p>
                                Creating an account gives access to the available account and onboarding features. It does not establish a care
                                relationship, grant professional authority or provide emergency support.
                            </p>
                            <p>
                                Provider and partner applications require independent verification and approval. A representative account has no
                                patient access until authority has been verified.
                            </p>
                        </>
                    ) : (
                        <>
                            <p>
                                This preview stores account details, your selected account type and language preference, hashed passwords,
                                verification state and any basic profile details you enter.
                            </p>
                            <p>
                                It also records the version and time of these acknowledgements and basic account registration and sign-in events.
                                Session cookies support authentication.
                            </p>
                            <p>
                                These acknowledgements do not constitute clinical consent, permission to share a patient chart or optional marketing
                                consent.
                            </p>
                        </>
                    )}
                    <p className="rounded-lg bg-amber-50 p-5 text-amber-950">
                        Production terms, privacy contacts, retention, hosting details and rights procedures require review and approval before
                        launch. This draft is not the production legal notice.
                    </p>
                </div>
            </main>
        </>
    );
}
