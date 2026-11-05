import { useSidebar } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, Layers, type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
export type NavigationLink = { title: string; url: string; icon: LucideIcon; count?: number };
export type NavigationSection = { label: string; items: NavigationLink[]; expandable?: boolean };
export default function GlassSidebarNavigation({ sections, search }: { sections: NavigationSection[]; search: string }) {
    const { url } = usePage();
    const path = url.split('?')[0];
    const { state } = useSidebar();
    const nav = useRef<HTMLElement>(null);
    const hovered = useRef<HTMLElement | null>(null);
    const [pill, setPill] = useState({ x: 0, y: 0, width: 0, height: 0 });
    const [expanded, setExpanded] = useState(true);
    const measure = useCallback(() => {
        const root = nav.current;
        if (!root) return;
        let target = hovered.current ?? root.querySelector<HTMLElement>('[aria-current="page"]');
        if (target?.closest('[inert]')) target = root.querySelector<HTMLElement>('.mcare-group-toggle');
        if (!target) {
            setPill((v) => ({ ...v, width: 0 }));
            return;
        }
        const a = root.getBoundingClientRect();
        const b = target.getBoundingClientRect();
        setPill({ x: b.left - a.left, y: b.top - a.top, width: b.width, height: b.height });
    }, []);
    useEffect(() => {
        const root = nav.current;
        if (!root) return;
        hovered.current = null;
        const observer = new ResizeObserver(measure);
        observer.observe(root);
        measure();
        window.addEventListener('resize', measure);
        return () => {
            observer.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, [measure, path, search, state, expanded]);
    const focus = (target: HTMLElement) => {
        hovered.current = target;
        measure();
    };
    const reset = () => {
        hovered.current = null;
        measure();
    };
    const visible = sections
        .map((section) => ({ ...section, items: section.items.filter((item) => item.title.toLowerCase().includes(search.toLowerCase())) }))
        .filter((s) => s.items.length);
    const renderLink = (item: NavigationLink, nested = false) => (
        <Link
            key={item.url}
            href={item.url}
            prefetch
            aria-label={item.title}
            aria-current={path === item.url ? 'page' : undefined}
            title={state === 'collapsed' ? item.title : undefined}
            className={`mcare-nav-link ${nested ? 'mcare-nav-child' : ''}`}
            onPointerEnter={(e) => focus(e.currentTarget)}
            onFocus={(e) => focus(e.currentTarget)}
        >
            <item.icon className="mcare-nav-icon" />
            <span className="mcare-nav-label">{item.title}</span>
            {!!item.count && <span className="mcare-nav-count">{item.count}</span>}
            {path === item.url && <span className="mcare-active-dot" aria-hidden="true" />}
        </Link>
    );
    return (
        <nav
            ref={nav}
            aria-label="Main navigation"
            className="mcare-navigation"
            onPointerLeave={reset}
            onBlur={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget)) reset();
            }}
        >
            <div
                aria-hidden="true"
                className="mcare-nav-highlight"
                style={{ transform: `translate3d(${pill.x}px, ${pill.y}px, 0)`, width: pill.width, height: pill.height, opacity: pill.width ? 1 : 0 }}
            />
            {visible.map((section) => (
                <section key={section.label} className="mcare-nav-section">
                    <h2 className="mcare-section-label">{section.label}</h2>
                    {section.expandable && !search ? (
                        <>
                            <button
                                className="mcare-group-toggle"
                                aria-expanded={expanded}
                                aria-controls="care-navigation-children"
                                onClick={() => setExpanded((v) => !v)}
                            >
                                <Layers className="mcare-nav-icon" />
                                <span className="mcare-nav-label">Care operations</span>
                                <ChevronDown className={`mcare-group-chevron ${expanded ? 'is-open' : ''}`} />
                            </button>
                            <div className={`mcare-submenu ${expanded ? 'is-open' : ''}`} id="care-navigation-children" inert={!expanded}>
                                <div className="mcare-submenu-inner">{section.items.map((item) => renderLink(item, true))}</div>
                            </div>
                        </>
                    ) : (
                        section.items.map((item) => renderLink(item))
                    )}
                </section>
            ))}
            {!visible.length && (
                <p className="text-muted-foreground px-3 py-5 text-xs" role="status">
                    No pages match “{search}”.
                </p>
            )}
        </nav>
    );
}
