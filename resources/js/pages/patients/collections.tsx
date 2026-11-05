import { Button } from '@/components/ui/button';
import { Field, Fields, title } from './fields';
export default function Collections({
    schema,
    values,
    change,
    disabled = false,
    options = {},
}: {
    schema: Record<string, Field[]>;
    values: Record<string, Record<string, string>[]>;
    change: (key: string, rows: Record<string, string>[]) => void;
    disabled?: boolean;
    options?: Record<string, { value: string; label: string }[]>;
}) {
    return (
        <>
            {Object.entries(schema).map(([key, fields]) => {
                const rows = values[key] ?? [];
                return (
                    <section key={key} className="surface space-y-4 p-4 sm:p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">{title(key)}</h2>
                            {!disabled && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={rows.length >= 50}
                                    onClick={() => change(key, [...rows, Object.fromEntries(fields.map((f) => [f.key, '']))])}
                                >
                                    Add entry
                                </Button>
                            )}
                        </div>
                        {rows.length === 0 && <p className="text-muted-foreground text-sm">No entries recorded.</p>}
                        {rows.map((row, i) => (
                            <div key={i} className="space-y-4 rounded-xl border p-3">
                                <Fields
                                    options={options}
                                    fields={fields}
                                    values={row}
                                    disabled={disabled}
                                    prefix={`${key}-${i}`}
                                    change={(k, v) =>
                                        change(
                                            key,
                                            rows.map((x, n) => (n === i ? { ...x, [k]: v } : x)),
                                        )
                                    }
                                />
                                {!disabled && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            change(
                                                key,
                                                rows.filter((_, n) => n !== i),
                                            )
                                        }
                                    >
                                        Remove entry {i + 1}
                                    </Button>
                                )}
                            </div>
                        ))}
                    </section>
                );
            })}
        </>
    );
}
