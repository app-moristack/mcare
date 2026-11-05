import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
export type Field = { key: string; label: string; type: string; choices: string | null; required: boolean };
export type Section = { title: string; fields: Field[] };
export type Schema = {
    demographics: Section[];
    clinical: Section[];
    children: Record<string, Field[]>;
    demographicsChildren: Record<string, Field[]>;
};
export const title = (s: string) =>
    s
        .replaceAll('_', ' ')
        .replaceAll('.', ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
export const date = (s: string | null) =>
    s ? new Date(s).toLocaleString('en-GB', { timeZone: 'Indian/Mauritius', dateStyle: 'medium', timeStyle: 'short' }) : '—';
export function Fields({
    fields,
    values,
    change,
    prefix = 'record',
    disabled = false,
    options = {},
}: {
    fields: Field[];
    values: Record<string, string>;
    change: (key: string, value: string) => void;
    prefix?: string;
    disabled?: boolean;
    options?: Record<string, { value: string; label: string }[]>;
}) {
    return (
        <div className="grid min-w-0 gap-4 sm:grid-cols-2">
            {fields.map((f) => (
                <div key={f.key} className={f.type === 'textarea' ? 'min-w-0 space-y-2 sm:col-span-2' : 'min-w-0 space-y-2'}>
                    <Label id={`${prefix}-${f.key}-label`} htmlFor={disabled ? undefined : `${prefix}-${f.key}`}>
                        {f.label}
                        {f.required ? ' *' : ''}
                    </Label>
                    {disabled ? (
                        <div
                            aria-labelledby={`${prefix}-${f.key}-label`}
                            className="bg-muted/30 min-h-10 rounded-xl border px-3 py-2 text-sm break-words whitespace-pre-wrap"
                        >
                            {options[f.key]?.find((o) => o.value === String(values[f.key]))?.label ?? (values[f.key] || 'Not recorded')}
                        </div>
                    ) : f.type === 'select' || f.type === 'staff' ? (
                        <select
                            className="w-full min-w-0"
                            id={`${prefix}-${f.key}`}
                            required={f.required}
                            disabled={disabled}
                            value={values[f.key] ?? ''}
                            onChange={(e) => change(f.key, e.target.value)}
                        >
                            <option value="">Select…</option>
                            {options[f.key]?.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                            {!options[f.key] &&
                                f.choices?.split(',').map((c) => (
                                    <option key={c} value={c}>
                                        {title(c)}
                                    </option>
                                ))}
                        </select>
                    ) : f.type === 'textarea' ? (
                        <textarea
                            className="w-full min-w-0"
                            id={`${prefix}-${f.key}`}
                            required={f.required}
                            disabled={disabled}
                            value={values[f.key] ?? ''}
                            onChange={(e) => change(f.key, e.target.value)}
                            rows={3}
                            maxLength={3000}
                        />
                    ) : (
                        <Input
                            className="min-w-0"
                            id={`${prefix}-${f.key}`}
                            type={f.type}
                            step={f.type === 'number' ? 'any' : undefined}
                            required={f.required}
                            disabled={disabled}
                            maxLength={3000}
                            value={values[f.key] ?? ''}
                            onChange={(e) => change(f.key, e.target.value)}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    return (
        <div role="alert" className="text-destructive space-y-1 text-sm">
            {Object.entries(errors).map(([key, error]) => (
                <p key={key}>{error}</p>
            ))}
        </div>
    );
}
export type AccessRule = {
    id: number;
    kind: string;
    scope: string;
    role: string | null;
    purpose: string;
    authority_type: string;
    wording_version: string;
    starts_at: string;
    ends_at: string;
    revoked_at: string | null;
    revocation_reason: string | null;
    user: { id: number; name: string };
};
export type History = { id: number; event: string; created_at: string; actor: string | null }[];
