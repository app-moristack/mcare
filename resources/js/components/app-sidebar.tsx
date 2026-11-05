import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Briefcase, CalendarDays, ClipboardList, CreditCard, HeartPulse, LayoutGrid, ListTodo, Settings, ShieldCheck, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    { title: 'Account settings', url: '/settings/profile', icon: Settings },
];

export function AppSidebar() {
    const { workspace, auth } = usePage<SharedData>().props;
    const modules = [
        { title: 'Command centre', key: 'overview', permission: 'dashboard.view', icon: LayoutGrid },
        { title: 'Patients', key: 'patients', permission: 'patients.manage', icon: Users },
        { title: 'Referrals', key: 'referrals', permission: 'referrals.manage', icon: ClipboardList },
        { title: 'Appointments', key: 'appointments', permission: 'appointments.manage', icon: CalendarDays },
        { title: 'Tasks', key: 'tasks', permission: 'tasks.manage', icon: ListTodo },
        { title: 'Team & credentials', key: 'team', permission: 'team.manage', icon: Briefcase },
        { title: 'Invoices & payments', key: 'invoices', permission: 'finance.manage', icon: CreditCard },
        { title: 'Access history', key: 'audit', permission: 'audit.view', icon: ShieldCheck },
    ];
    const items: NavItem[] = [
        mainNavItems[0],
        ...modules
            .filter((m) => workspace?.permissions.includes(m.permission))
            .map((m) => ({ title: m.title, url: `/workspace/${m.key}`, icon: m.icon })),
        ...(auth.user.account_kind === 'provider_application' ? [{ title: 'My application', url: '/application', icon: Briefcase }] : []),
        { title: 'My care', url: '/my-care', icon: HeartPulse },
        ...((workspace?.organisations.length ?? 0) > 0 ? [{ title: 'My visits', url: '/my-visits', icon: CalendarDays }] : []),
        { title: 'Two-factor security', url: '/security/mfa', icon: ShieldCheck },
        mainNavItems[1],
    ];
    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="h-18 justify-center border-b px-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
