import { useColorTheme } from '@/hooks/use-appearance';
import { Check } from 'lucide-react';
export default function ColorThemePicker() {
    const { colorTheme, updateColorTheme } = useColorTheme();
    return (
        <div role="group" aria-label="Colour theme" className="grid gap-3 sm:grid-cols-2">
            {(
                [
                    { value: 'warm', name: 'Warm glass', description: 'Amber accents and the warm medical backdrop.' },
                    { value: 'medical', name: 'White & blue', description: 'White glass, blue accents and your blue examination room.' },
                ] as const
            ).map((t) => (
                <button
                    type="button"
                    key={t.value}
                    aria-pressed={colorTheme === t.value}
                    onClick={() => updateColorTheme(t.value)}
                    className={`surface focus-visible:outline-ring space-y-3 p-4 text-left focus-visible:outline-2 ${colorTheme === t.value ? 'ring-primary ring-2' : ''}`}
                >
                    <span className={`theme-preview theme-preview-${t.value}`} aria-hidden="true">
                        <span />
                        <span />
                        <span />
                    </span>
                    <span className="flex items-center justify-between gap-2 font-semibold">
                        {t.name}
                        {colorTheme === t.value && <Check className="text-primary size-4" />}
                    </span>
                    <span className="text-muted-foreground block text-xs">{t.description}</span>
                </button>
            ))}
        </div>
    );
}
