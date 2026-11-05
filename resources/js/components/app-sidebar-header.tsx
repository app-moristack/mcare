import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { type BreadcrumbItem as BreadcrumbItemType, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { auth, workspace } = usePage<SharedData>().props;
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
                {(workspace?.organisations.length ?? 0) > 1 && (
                    <select
                        aria-label="Working organisation"
                        className="max-w-40 text-xs"
                        value={workspace?.organisation_id ?? ''}
                        onChange={(e) => router.post('/workspace-context', { organisation_id: e.target.value })}
                    >
                        <option value="">Select organisation</option>
                        {workspace?.organisations.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                )}
                {(workspace?.notifications.length ?? 0) > 0 && (
                    <Link href="/workspace/tasks" className="rounded-md bg-amber-100 px-2 py-1 text-xs text-amber-900">
                        {workspace?.notifications.length} task alerts
                    </Link>
                )}

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
