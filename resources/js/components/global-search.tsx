import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useSidebar } from '@/components/ui/sidebar';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, LoaderCircle, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
type Result = { category: string; title: string; subtitle: string; url: string };
export default function GlobalSearch() {
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [results, setResults] = useState<Result[]>([]),
        [truncated, setTruncated] = useState(false),
        [loading, setLoading] = useState(false),
        [error, setError] = useState('');
    const { isMobile, setOpenMobile, state } = useSidebar();
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if (e.key.toLowerCase() === 'k' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                setOpen((v) => !v);
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        setResults([]);
        setTruncated(false);
        setError('');
        const q = query.trim();
        if (!open || q.length < 2) {
            setLoading(false);
            return;
        }
        setLoading(true);
        const timer = setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then(async (r) => {
                    if (!r.ok)
                        throw new Error(
                            r.status === 429 ? 'Please wait before searching again.' : 'Search is unavailable. Check your session and try again.',
                        );
                    return r.json();
                })
                .then((d) => {
                    setResults(d.results);
                    setTruncated(!!d.truncated);
                    setLoading(false);
                })
                .catch((e) => {
                    if (e.name !== 'AbortError') {
                        setError(e.message);
                        setLoading(false);
                    }
                });
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query, open]);
    return (
        <>
            <button
                type="button"
                className="mcare-search w-full text-left"
                aria-label="Search the app"
                title="Search the app (Ctrl/Cmd K)"
                tabIndex={state === 'collapsed' && !isMobile ? -1 : 0}
                onClick={() => setOpen(true)}
            >
                <Search aria-hidden="true" />
                <span className="min-w-0 flex-1 truncate text-xs">Search the app</span>
                <kbd>Ctrl K</kbd>
            </button>
            <Dialog
                open={open}
                onOpenChange={(v) => {
                    setOpen(v);
                    if (!v) setQuery('');
                }}
            >
                <DialogContent className="max-w-2xl p-4 sm:p-6">
                    <DialogTitle>Search MauriCare</DialogTitle>
                    <DialogDescription>
                        Find patients, clients, users, visits, referrals, tasks and invoices you can access in your current organisation, plus your
                        own care records.
                    </DialogDescription>
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-3 left-3 size-4" />
                        <Input
                            autoFocus
                            type="search"
                            aria-label="Search all records"
                            placeholder="Search a name, reference, service or task…"
                            className="h-11 pl-10"
                            maxLength={100}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                    <div role="status" className="text-muted-foreground text-xs">
                        {loading
                            ? 'Searching…'
                            : query.trim().length < 2
                              ? 'Enter at least 2 characters.'
                              : error ||
                                `${truncated ? 'First ' : ''}${results.length} accessible result${results.length === 1 ? '' : 's'}${truncated ? ' — narrow your search for more matches.' : ''}`}
                    </div>
                    <div className="max-h-[55dvh] space-y-4 overflow-y-auto overscroll-contain">
                        {loading && <LoaderCircle className="text-primary mx-auto size-5 animate-spin" />}
                        {!loading && query.trim().length >= 2 && !error && !results.length && (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                No accessible records found. Try a different name or reference.
                            </p>
                        )}
                        {[...new Set(results.map((r) => r.category))].map((category) => (
                            <section key={category}>
                                <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide">{category}</h3>
                                <ul className="space-y-1">
                                    {results
                                        .filter((r) => r.category === category)
                                        .map((r, i) => (
                                            <li key={`${r.url}-${i}`}>
                                                <Link
                                                    href={r.url}
                                                    className="hover:border-border hover:bg-accent focus-visible:outline-ring flex min-h-12 items-center justify-between gap-3 rounded-xl border border-transparent p-3 focus-visible:outline-2"
                                                    onClick={() => {
                                                        setOpen(false);
                                                        setQuery('');
                                                        if (isMobile) setOpenMobile(false);
                                                    }}
                                                >
                                                    <span className="min-w-0">
                                                        <span className="block text-sm font-medium break-words">{r.title}</span>
                                                        <span className="text-muted-foreground block text-xs break-all">{r.subtitle}</span>
                                                    </span>
                                                    <ArrowUpRight className="text-primary size-4 shrink-0" />
                                                </Link>
                                            </li>
                                        ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
