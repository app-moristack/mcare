import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AccessRule, date, Errors, Field, Fields, title } from './fields';
const fields: Field[] = [
    { key: 'kind', label: 'Rule type', type: 'select', choices: 'assignment,grant,block', required: true },
    {
        key: 'scope',
        label: 'Scope',
        type: 'select',
        choices:
            'all,summary.read,summary.write,basic.read,basic.write,nursing.read,nursing.write,nursing.cosign,notes.read,notes.write,notes.publish,plans.read,plans.write,plans.approve,plans.publish',
        required: true,
    },
    {
        key: 'role',
        label: 'Clinical assignment role (assignment only)',
        type: 'select',
        choices: 'doctor,nurse,physiotherapist,carer,clinical_admin',
        required: false,
    },
    {
        key: 'authority_type',
        label: 'Authority type',
        type: 'select',
        choices: 'patient_consent,verified_representative,care_assignment,patient_block',
        required: true,
    },
    { key: 'purpose', label: 'Purpose of access', type: 'text', choices: null, required: true },
    { key: 'wording_version', label: 'Consent / authority wording version', type: 'text', choices: null, required: true },
    { key: 'starts_at', label: 'Starts (Mauritius time)', type: 'datetime-local', choices: null, required: true },
    { key: 'ends_at', label: 'Expires (Mauritius time)', type: 'datetime-local', choices: null, required: true },
    { key: 'evidence', label: 'Actual authority evidence / representative verification', type: 'textarea', choices: null, required: true },
];
export function RuleList({ rules, base, patient = false }: { rules: AccessRule[]; base: string; patient?: boolean }) {
    const f = useForm({ reason: '' });
    const [action, setAction] = useState<{
        url: string;
        label: string;
        user_id?: number;
        scope?: string;
        ends_at?: string;
        request_id?: string;
    } | null>(null);
    return (
        <div className="space-y-3">
            {rules.length === 0 && <p className="text-muted-foreground text-sm">No access rules recorded.</p>}
            {rules.map((a) => (
                <article className="rounded-xl border p-4" key={a.id}>
                    <div className="flex flex-wrap justify-between gap-2">
                        <h3 className="font-medium">{a.user.name}</h3>
                        <Badge variant={a.kind === 'block' ? 'warning' : 'secondary'}>{title(a.kind)}</Badge>
                    </div>
                    <p className="mt-2 text-sm">
                        {title(a.scope)}
                        {a.role ? ` · ${title(a.role)}` : ''}
                    </p>
                    <p className="text-muted-foreground mt-1 text-sm break-words">{a.purpose}</p>
                    <p className="text-muted-foreground mt-2 text-xs">
                        {date(a.starts_at)} to {date(a.ends_at)}
                    </p>
                    <p className="mt-1 text-xs">
                        {a.revoked_at
                            ? `Revoked ${date(a.revoked_at)}`
                            : new Date(a.ends_at) < new Date()
                              ? 'Expired'
                              : new Date(a.starts_at) > new Date()
                                ? 'Scheduled'
                                : 'Current period'}{' '}
                        · {title(a.authority_type)}
                    </p>
                    {!a.revoked_at && (patient ? a.kind !== 'assignment' : a.kind !== 'block') && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3"
                            disabled={f.processing}
                            onClick={() => {
                                setAction({ url: `${base}/access/${a.id}/revoke`, label: a.kind === 'block' ? 'Lift block' : 'Revoke access rule' });
                            }}
                        >
                            {a.kind === 'block' ? 'Lift block' : 'Revoke'}
                        </Button>
                    )}
                    {patient && a.kind === 'assignment' && !a.revoked_at && new Date(a.ends_at) > new Date() && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {['summary.read'].map((scope) => (
                                <Button
                                    key={scope}
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setAction({
                                            url: `${base}/grants`,
                                            label: 'Authorise record access',
                                            user_id: a.user.id,
                                            scope,
                                            ends_at: a.ends_at,
                                            request_id: crypto.randomUUID(),
                                        })
                                    }
                                >
                                    {'Authorise record access'}
                                </Button>
                            ))}
                        </div>
                    )}
                    {patient && a.kind !== 'block' && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3 ml-2"
                            onClick={() => {
                                setAction({ url: `${base}/blocks`, label: 'Block professional', user_id: a.user.id });
                            }}
                        >
                            Block professional
                        </Button>
                    )}
                </article>
            ))}
            <Dialog
                open={!!action}
                onOpenChange={(open) => {
                    if (!open) setAction(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{action?.label}</DialogTitle>
                    <DialogDescription>
                        {action?.scope
                            ? `I authorise this assigned professional for ${title(action.scope)} for their recorded care purpose until ${date(action.ends_at ?? null)}. Only this record category and action are authorised. Private nursing records remain restricted to the professional team. I can revoke this authority at any time. Existing blocks still apply.`
                            : 'This change takes effect immediately for future clinical summary requests.'}
                    </DialogDescription>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!action) return;
                            f.transform((d) => ({
                                ...d,
                                ...(action.user_id ? { user_id: action.user_id } : {}),
                                ...(action.scope ? { scope: action.scope, ends_at: action.ends_at, request_id: action.request_id } : {}),
                            }));
                            f.post(action.url, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setAction(null);
                                    f.reset();
                                },
                            });
                        }}
                    >
                        {action?.scope && (
                            <div className="space-y-2">
                                <label htmlFor="authority-scope">Record category and action</label>
                                <select
                                    id="authority-scope"
                                    className="w-full"
                                    value={action.scope}
                                    onChange={(e) => setAction({ ...action, scope: e.target.value })}
                                >
                                    {[
                                        'summary.read',
                                        'summary.write',
                                        'basic.read',
                                        'basic.write',
                                        'nursing.read',
                                        'nursing.write',
                                        'nursing.cosign',
                                        'notes.read',
                                        'notes.write',
                                        'notes.publish',
                                        'plans.read',
                                        'plans.write',
                                        'plans.approve',
                                        'plans.publish',
                                    ].map((scope) => (
                                        <option key={scope} value={scope}>
                                            {title(scope)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <label htmlFor="access-reason">Reason *</label>
                        <textarea
                            id="access-reason"
                            className="w-full"
                            rows={3}
                            required
                            maxLength={2000}
                            value={f.data.reason}
                            onChange={(e) => f.setData('reason', e.target.value)}
                        />
                        <Errors errors={f.errors} />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setAction(null)}>
                                Cancel
                            </Button>
                            <Button disabled={f.processing}>{action?.label}</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
export default function AccessRules({ patientId, rules, staff }: { patientId: number; rules: AccessRule[]; staff: { id: number; name: string }[] }) {
    const f = useForm({
        request_id: crypto.randomUUID(),
        user_id: '',
        kind: 'assignment',
        scope: 'all',
        role: '',
        purpose: '',
        authority_type: 'care_assignment',
        wording_version: '',
        starts_at: '',
        ends_at: '',
        evidence: '',
        attested: false as boolean,
    });
    return (
        <div className="grid items-start gap-5 lg:grid-cols-2">
            <section className="surface p-4 sm:p-6">
                <h2 className="text-lg font-semibold">Assignments and authority</h2>
                <p className="text-muted-foreground mt-2 mb-5 text-sm">
                    Assignments establish the working relationship. Separate read and write grants record actual patient or verified representative
                    authority. Blocks override both. Each record category requires its own scoped authority. Patient blocks override ordinary access
                    across these categories.
                </p>
                <RuleList rules={rules} base={`/patient-records/${patientId}`} />
            </section>
            <form
                className="surface space-y-5 p-4 sm:p-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    f.post(`/patient-records/${patientId}/access`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            f.reset();
                            f.setData('request_id', crypto.randomUUID());
                        },
                    });
                }}
            >
                <h2 className="text-lg font-semibold">Record an access rule</h2>
                <div className="space-y-2">
                    <label htmlFor="rule-user">Professional</label>
                    <select className="w-full" id="rule-user" required value={f.data.user_id} onChange={(e) => f.setData('user_id', e.target.value)}>
                        <option value="">Select an active member</option>
                        {staff.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </select>
                </div>
                <Fields
                    fields={fields}
                    prefix="rule"
                    values={Object.fromEntries(fields.map((x) => [x.key, String(f.data[x.key as keyof typeof f.data] ?? '')]))}
                    change={(k, v) => {
                        if (k === 'kind') {
                            f.setData({
                                ...f.data,
                                kind: v,
                                scope: v === 'grant' ? 'summary.read' : 'all',
                                authority_type: v === 'grant' ? 'patient_consent' : v === 'block' ? 'patient_block' : 'care_assignment',
                            });
                        } else f.setData(k as 'purpose', v);
                    }}
                />
                <label className="flex items-start gap-3 text-sm">
                    <input type="checkbox" required checked={f.data.attested} onChange={(e) => f.setData('attested', e.target.checked)} />
                    <span>I verified this actual authority and its evidence. A family relationship, payment or admin role alone is not consent.</span>
                </label>
                <Errors errors={f.errors} />
                <Button disabled={f.processing}>Record rule</Button>
            </form>
        </div>
    );
}
