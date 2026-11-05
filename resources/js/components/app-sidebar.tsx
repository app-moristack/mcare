import GlassSidebarNavigation, { type NavigationLink, type NavigationSection } from '@/components/glass-sidebar-navigation';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, useSidebar } from '@/components/ui/sidebar';
import { useAppearance } from '@/hooks/use-appearance';
import { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Briefcase,
    CalendarDays,
    ChevronLeft,
    ClipboardList,
    CreditCard,
    HeartPulse,
    LayoutGrid,
    ListTodo,
    Moon,
    Search,
    Settings,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
function SidebarContents() {
    const { workspace, auth } = usePage<SharedData>().props;
    const { state, toggleSidebar, isMobile } = useSidebar();
    const { appearance, updateAppearance } = useAppearance();
    const [search, setSearch] = useState('');
    const searchRef = useRef<HTMLInputElement>(null);
    const dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    useEffect(() => {
        const handler = (event: KeyboardEvent) => {
            if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                if (state === 'collapsed') toggleSidebar();
                requestAnimationFrame(() => searchRef.current?.focus());
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [state, toggleSidebar]);
    const allowed = (permission: string) => workspace?.permissions.includes(permission) ?? false;
    const main: NavigationLink[] = [
        { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
        ...(allowed('dashboard.view') ? [{ title: 'Command centre', url: '/workspace/overview', icon: LayoutGrid }] : []),
        ...(allowed('patients.manage') ? [{ title: 'Patients', url: '/workspace/patients', icon: HeartPulse }] : []),
        ...(allowed('appointments.manage') ? [{ title: 'Appointments', url: '/workspace/appointments', icon: CalendarDays }] : []),
    ];
    const operations: NavigationLink[] = [
        { title: 'Referrals', url: '/workspace/referrals', icon: ClipboardList, permission: 'referrals.manage' },
        { title: 'Tasks', url: '/workspace/tasks', icon: ListTodo, permission: 'tasks.manage', count: workspace?.notifications.length ?? 0 },
        { title: 'Team & credentials', url: '/workspace/team', icon: Briefcase, permission: 'team.manage' },
        { title: 'Invoices & payments', url: '/workspace/invoices', icon: CreditCard, permission: 'finance.manage' },
        { title: 'Access history', url: '/workspace/audit', icon: ShieldCheck, permission: 'audit.view' },
    ].filter((item) => allowed(item.permission));
    const personal: NavigationLink[] = [
        ...(auth.user.account_kind === 'provider_application' ? [{ title: 'My application', url: '/application', icon: Briefcase }] : []),
        { title: 'My care', url: '/my-care', icon: HeartPulse },
        ...((workspace?.organisations.length ?? 0) > 0 ? [{ title: 'My visits', url: '/my-visits', icon: CalendarDays }] : []),
        { title: 'Two-factor security', url: '/security/mfa', icon: ShieldCheck },
        { title: 'Account settings', url: '/settings/profile', icon: Settings },
    ];
    const sections: NavigationSection[] = [
        { label: 'Main', items: main },
        { label: 'Workspace', items: operations, expandable: true },
        { label: 'My account', items: personal },
    ];
    return (
        <>
            <SidebarHeader className="mcare-sidebar-header">
                <Link href="/dashboard" className="mcare-sidebar-brand" aria-label="MauriCare dashboard">
                    <span className="mcare-brand-mark">
                        <HeartPulse />
                    </span>
                    <span className="mcare-brand-copy">
                        <strong>MauriCare</strong>
                        <span>Care workspace</span>
                    </span>
                </Link>
                <button
                    type="button"
                    className="mcare-collapse"
                    onClick={() => {
                        setSearch('');
                        toggleSidebar();
                    }}
                    aria-label={isMobile ? 'Close navigation' : state === 'expanded' ? 'Collapse navigation' : 'Expand navigation'}
                    aria-expanded={isMobile || state === 'expanded'}
                >
                    <ChevronLeft />
                </button>
                <div className="mcare-search" inert={state === 'collapsed' && !isMobile}>
                    <Search aria-hidden="true" />
                    <input
                        ref={searchRef}
                        type="search"
                        aria-label="Search navigation pages"
                        placeholder="Search pages"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <kbd>Ctrl K</kbd>
                </div>
            </SidebarHeader>
            <SidebarContent className="mcare-sidebar-content">
                <GlassSidebarNavigation sections={sections} search={search} />
            </SidebarContent>
            <SidebarFooter className="mcare-sidebar-footer">
                <button
                    type="button"
                    role="switch"
                    aria-checked={dark}
                    aria-label="Night mode"
                    title="Night mode"
                    className="mcare-night-mode"
                    onClick={() => updateAppearance(dark ? 'light' : 'dark')}
                >
                    <Moon className="mcare-nav-icon" />
                    <span className="mcare-nav-label">Night mode</span>
                    <span className={`mcare-switch ${dark ? 'is-on' : ''}`} aria-hidden="true">
                        <span />
                    </span>
                </button>
                <NavUser />
            </SidebarFooter>
        </>
    );
}
export function AppSidebar() {
    return (
        <Sidebar
            collapsible="icon"
            variant="floating"
            className="glass-sidebar mcare-sidebar"
            onPointerMove={(event) => {
                const rect = event.currentTarget.getBoundingClientRect();
                event.currentTarget.style.setProperty('--sidebar-pointer-y', `${event.clientY - rect.top}px`);
            }}
        >
            <SidebarContents />
        </Sidebar>
    );
}
