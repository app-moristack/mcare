import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { type BreadcrumbItem as BreadcrumbItemType, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { auth } = usePage<SharedData>().props;
    const initials = useInitials();
    return (
        <header className="bg-card flex h-18 shrink-0 items-center justify-between gap-4 border-b px-4 md:px-6">
            <div className="flex min-w-0 items-center gap-3">
                <SidebarTrigger />
                <div className="hidden sm:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
            <div className="flex items-center gap-3">
                <AppearanceToggleDropdown />
                <div className="h-6 border-l" />
                <Link
                    href="/settings/profile"
                    aria-label="Account settings"
                    className="focus-visible:outline-ring flex items-center gap-3 rounded-md focus-visible:outline-2"
                >
                    <Avatar className="size-9">
                        <AvatarFallback className="bg-muted text-foreground text-xs font-semibold">{initials(auth.user.name)}</AvatarFallback>
                    </Avatar>
                    <span className="hidden text-sm font-medium md:block">{auth.user.name}</span>
                </Link>
            </div>
        </header>
    );
}
