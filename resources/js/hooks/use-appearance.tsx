import { useSyncExternalStore } from 'react';
export type Appearance = 'light' | 'dark' | 'system';
export type ColorTheme = 'warm' | 'medical';
const changeEvent = 'mauricare-appearance-change';
const memory = new Map<string, string>();
const read = (key: string) => {
    try {
        return localStorage.getItem(key);
    } catch {
        return memory.get(key) ?? null;
    }
};
const write = (key: string, value: string) => {
    memory.set(key, value);
    try {
        localStorage.setItem(key, value);
    } catch {
        /* Theme selection still works for this page when storage is unavailable. */
    }
};
const appearanceSnapshot = (): Appearance => {
    const v = read('appearance');
    return v === 'light' || v === 'dark' ? v : 'system';
};
const paletteSnapshot = (): ColorTheme => (read('mcare-color-theme') === 'medical' ? 'medical' : 'warm');
const subscribe = (callback: () => void) => {
    window.addEventListener(changeEvent, callback);
    window.addEventListener('storage', callback);
    return () => {
        window.removeEventListener(changeEvent, callback);
        window.removeEventListener('storage', callback);
    };
};
const apply = () => {
    const mode = appearanceSnapshot();
    document.documentElement.classList.toggle(
        'dark',
        mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches),
    );
    document.documentElement.dataset.colorTheme = paletteSnapshot();
};
let initialized = false;
export function initializeTheme() {
    apply();
    if (initialized) return;
    initialized = true;
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', apply);
    window.addEventListener('storage', apply);
}
export function useAppearance() {
    const appearance = useSyncExternalStore(subscribe, appearanceSnapshot, () => 'system' as Appearance);
    const updateAppearance = (mode: Appearance) => {
        write('appearance', mode);
        apply();
        window.dispatchEvent(new Event(changeEvent));
    };
    return { appearance, updateAppearance };
}
export function useColorTheme() {
    const colorTheme = useSyncExternalStore(subscribe, paletteSnapshot, () => 'warm' as ColorTheme);
    const updateColorTheme = (theme: ColorTheme) => {
        write('mcare-color-theme', theme);
        if (theme === 'medical') write('appearance', 'light');
        apply();
        window.dispatchEvent(new Event(changeEvent));
    };
    return { colorTheme, updateColorTheme };
}
