import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Link } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background relative flex min-h-svh flex-col items-center justify-center px-4 py-12 sm:px-6">
            <div className="absolute top-5 right-5">
                <AppearanceToggleDropdown />
            </div>
            <div className="w-full max-w-md">
                <div className="mb-8 text-center">
                    <Link href="/" className="inline-flex items-center gap-2.5 text-3xl font-bold tracking-tight">
                        <HeartPulse className="text-primary size-8" />
                        MauriCare
                    </Link>
                    <p className="text-muted-foreground mt-2 text-sm">Care, connected.</p>
                </div>
                <main className="surface glass-auth-card">
                    <div className="mb-7">
                        <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                        <p className="text-muted-foreground mt-2 text-sm leading-relaxed">{description}</p>
                    </div>
                    {children}
                </main>
                <p className="text-muted-foreground mt-6 text-center text-xs">MauriCare · Development preview</p>
            </div>
        </div>
    );
}
