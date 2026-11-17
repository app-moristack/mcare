import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Person = { id: number; name: string } | null;
type Visit = { id: number; provider_id: number; service: string; starts_at: string; status: string };
type Medicine = { name: string; dose: string; route: string; frequency: string; status: string };
type Reconciliation = {
    id: number;
    appointment_id: number;
    supersedes_id: number | null;
    reconciled_at: string;
    status: string;
    sources: string[];
    medicines: Medicine[];
    discrepancies?: string;
    follow_up?: string;
    correction_reason?: string;
    author: Person;
    appointment?: { id: number; service: string };
};
type Details = {
    medicine: string;
    dose: string;
    route: string;
    frequency: string;
    instructions?: string;
    prescriber_name: string;
    prescriber_registration: string;
    source_reference: string;
    verification_evidence: string;
};
type Instruction = {
    id: number;
    supersedes_id: number | null;
    verified_at: string;
    starts_at: string;
    ends_at: string | null;
    status: string;
    details: Details;
    correction_reason?: string;
    verifier: Person;
};
type Administration = {
    id: number;
    medication_instruction_id: number;
    appointment_id: number;
    supersedes_id: number | null;
    administered_at: string;
    outcome: string;
    instruction_snapshot: Details;
    reason?: string;
    notes?: string;
    correction_reason?: string;
    administrator: Person;
    appointment?: { id: number; service: string };
};

const nowLocal = () => {
    const value = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000);
    return value.toISOString().slice(0, 16);
};
const format = (value: string) =>
    new Intl.DateTimeFormat('en-MU', { timeZone: 'Indian/Mauritius', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const title = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

function SignFields({
    password,
    declaration,
    setPassword,
    setDeclaration,
    errors,
}: {
    password: string;
    declaration: boolean;
    setPassword: (value: string) => void;
    setDeclaration: (value: boolean) => void;
    errors: Record<string, string | undefined>;
}) {
    return (
        <div className="border-border space-y-3 border-t pt-4">
            <div className="max-w-md">
                <Label>Confirm your password</Label>
                <Input className="mt-1" type="password" value={password} onChange={(event) => setPassword(event.target.value)} required />
                <InputError message={errors.password} />
            </div>
            <label className="flex min-h-11 items-start gap-3 text-sm">
                <input className="mt-1" type="checkbox" checked={declaration} onChange={(event) => setDeclaration(event.target.checked)} required />
                <span>
                    I confirm this record is accurate to the best of my knowledge. Signing makes it immutable; any correction creates a linked new
                    record.
                </span>
            </label>
            <InputError message={errors.declaration} />
        </div>
    );
}

export default function Medications({
    patient,
    reconciliations,
    instructions,
    administrations,
    canReconcile,
    canVerify,
    canAdminister,
    reconciliationVisits,
    administrationVisits,
}: {
    patient: { id: number; name: string; reference: string };
    reconciliations: Reconciliation[];
    instructions: Instruction[];
    administrations: Administration[];
    canReconcile: boolean;
    canVerify: boolean;
    canAdminister: boolean;
    reconciliationVisits: Visit[];
    administrationVisits: Visit[];
}) {
    const correctedReconciliations = new Set(reconciliations.map((row) => row.supersedes_id).filter(Boolean));
    const supersededInstructions = new Set(instructions.map((row) => row.supersedes_id).filter(Boolean));
    const correctedAdministrations = new Set(administrations.map((row) => row.supersedes_id).filter(Boolean));
    const activeInstructions = instructions.filter(
        (row) =>
            row.status === 'active' &&
            !supersededInstructions.has(row.id) &&
            new Date(row.starts_at) <= new Date() &&
            (!row.ends_at || new Date(row.ends_at) >= new Date()),
    );
    const reconciliation = useForm({
        request_id: crypto.randomUUID(),
        appointment_id: '',
        reconciled_at: nowLocal(),
        status: 'complete',
        sources_text: '',
        medicines: [{ name: '', dose: '', route: '', frequency: '', status: 'taking' }] as Medicine[],
        discrepancies: '',
        follow_up: '',
        supersedes_id: '',
        correction_reason: '',
        password: '',
        declaration: false,
    });
    const instruction = useForm({
        request_id: crypto.randomUUID(),
        verified_at: nowLocal(),
        starts_at: nowLocal(),
        ends_at: '',
        status: 'active',
        medicine: '',
        dose: '',
        route: '',
        frequency: '',
        instructions: '',
        prescriber_name: '',
        prescriber_registration: '',
        source_reference: '',
        verification_evidence: '',
        supersedes_id: '',
        correction_reason: '',
        password: '',
        declaration: false,
    });
    const administration = useForm({
        request_id: crypto.randomUUID(),
        medication_instruction_id: '',
        appointment_id: '',
        administered_at: nowLocal(),
        outcome: 'administered',
        reason: '',
        notes: '',
        supersedes_id: '',
        correction_reason: '',
        password: '',
        declaration: false,
    });
    const reconciliationErrors = reconciliation.errors as Record<string, string | undefined>;
    const submitReconciliation = (event: FormEvent) => {
        event.preventDefault();
        reconciliation.transform((data) => ({
            ...data,
            sources: data.sources_text
                .split('\n')
                .map((value) => value.trim())
                .filter(Boolean),
            sources_text: undefined,
        }));
        reconciliation.post(`/patient-records/${patient.id}/medications/reconciliations`, {
            preserveScroll: true,
            onSuccess: () => {
                reconciliation.reset();
                reconciliation.setData({
                    ...reconciliation.data,
                    request_id: crypto.randomUUID(),
                    appointment_id: '',
                    reconciled_at: nowLocal(),
                    status: 'complete',
                    sources_text: '',
                    medicines: [{ name: '', dose: '', route: '', frequency: '', status: 'taking' }],
                    discrepancies: '',
                    follow_up: '',
                    supersedes_id: '',
                    correction_reason: '',
                    password: '',
                    declaration: false,
                });
            },
        });
    };
    const submitInstruction = (event: FormEvent) => {
        event.preventDefault();
        instruction.post(`/patient-records/${patient.id}/medications/instructions`, {
            preserveScroll: true,
            onSuccess: () => {
                instruction.reset();
                instruction.setData('request_id', crypto.randomUUID());
                instruction.setData('verified_at', nowLocal());
                instruction.setData('starts_at', nowLocal());
            },
        });
    };
    const submitAdministration = (event: FormEvent) => {
        event.preventDefault();
        administration.post(`/patient-records/${patient.id}/medications/administrations`, {
            preserveScroll: true,
            onSuccess: () => {
                administration.reset();
                administration.setData('request_id', crypto.randomUUID());
                administration.setData('administered_at', nowLocal());
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patient record', href: `/patient-records/${patient.id}` },
                { title: 'Medications', href: `/patient-records/${patient.id}/medications` },
            ]}
        >
            <Head title={`${patient.name} medications`} />
            <main className="mx-auto w-full max-w-7xl min-w-0 space-y-6 p-3 sm:p-6">
                <header className="surface space-y-3 p-4 sm:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-muted-foreground text-xs">Medication safety workspace</p>
                            <h1 className="text-2xl font-semibold break-words">{patient.name}: medications</h1>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={`/patient-records/${patient.id}`}>Patient record</Link>
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Reconcile what the patient reports, verify external instructions, and record administration outcomes. Verification here does
                        not create a prescription. No dose advice, interaction checking, or automated clinical decisions are provided.
                    </p>
                </header>

                {canReconcile && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Sign a medication reconciliation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-5" onSubmit={submitReconciliation}>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Encounter</Label>
                                        <select
                                            className="glass-input mt-1 min-h-11 w-full"
                                            value={reconciliation.data.appointment_id}
                                            onChange={(e) => reconciliation.setData('appointment_id', e.target.value)}
                                            required
                                        >
                                            <option value="">Choose encounter</option>
                                            {reconciliationVisits.map((visit) => (
                                                <option key={visit.id} value={visit.id}>
                                                    {visit.service} · {format(visit.starts_at)}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={reconciliation.errors.appointment_id} />
                                    </div>
                                    <div>
                                        <Label>Reconciled time (Mauritius)</Label>
                                        <Input
                                            className="mt-1"
                                            type="datetime-local"
                                            value={reconciliation.data.reconciled_at}
                                            onChange={(e) => reconciliation.setData('reconciled_at', e.target.value)}
                                            required
                                        />
                                        <InputError message={reconciliation.errors.reconciled_at} />
                                    </div>
                                    <div>
                                        <Label>Completion status</Label>
                                        <select
                                            className="glass-input mt-1 min-h-11 w-full"
                                            value={reconciliation.data.status}
                                            onChange={(e) => reconciliation.setData('status', e.target.value)}
                                        >
                                            <option value="complete">Complete</option>
                                            <option value="incomplete">Incomplete</option>
                                            <option value="unable">Unable</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <Label>Information sources (one per line)</Label>
                                    <textarea
                                        className="glass-input mt-1 min-h-24 w-full"
                                        value={reconciliation.data.sources_text}
                                        onChange={(e) => reconciliation.setData('sources_text', e.target.value)}
                                        placeholder="Patient interview&#10;Dispensing label reviewed"
                                        required
                                    />
                                    <InputError message={reconciliation.errors.sources} />
                                </div>
                                <fieldset className="space-y-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <legend className="font-medium">Reported medicines</legend>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                reconciliation.setData('medicines', [
                                                    ...reconciliation.data.medicines,
                                                    { name: '', dose: '', route: '', frequency: '', status: 'taking' },
                                                ])
                                            }
                                        >
                                            Add medicine
                                        </Button>
                                    </div>
                                    {reconciliation.data.medicines.map((medicine, index) => (
                                        <div className="glass-subtle grid gap-3 rounded-xl p-3 sm:grid-cols-2 lg:grid-cols-5" key={index}>
                                            {(['name', 'dose', 'route', 'frequency'] as const).map((field) => (
                                                <div key={field}>
                                                    <Label>{title(field)}</Label>
                                                    <Input
                                                        className="mt-1"
                                                        value={medicine[field]}
                                                        onChange={(e) =>
                                                            reconciliation.setData(
                                                                'medicines',
                                                                reconciliation.data.medicines.map((item, i) =>
                                                                    i === index ? { ...item, [field]: e.target.value } : item,
                                                                ),
                                                            )
                                                        }
                                                        required
                                                    />
                                                    <InputError message={reconciliationErrors[`medicines.${index}.${field}`]} />
                                                </div>
                                            ))}
                                            <div>
                                                <Label>Status</Label>
                                                <select
                                                    className="glass-input mt-1 min-h-10 w-full"
                                                    value={medicine.status}
                                                    onChange={(e) =>
                                                        reconciliation.setData(
                                                            'medicines',
                                                            reconciliation.data.medicines.map((item, i) =>
                                                                i === index ? { ...item, status: e.target.value } : item,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="taking">Taking</option>
                                                    <option value="not_taking">Not taking</option>
                                                    <option value="uncertain">Uncertain</option>
                                                </select>
                                                <button
                                                    className="text-destructive mt-2 text-sm"
                                                    type="button"
                                                    onClick={() =>
                                                        reconciliation.setData(
                                                            'medicines',
                                                            reconciliation.data.medicines.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </fieldset>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Discrepancies or uncertainties</Label>
                                        <textarea
                                            className="glass-input mt-1 min-h-24 w-full"
                                            value={reconciliation.data.discrepancies}
                                            onChange={(e) => reconciliation.setData('discrepancies', e.target.value)}
                                        />
                                        <InputError message={reconciliation.errors.discrepancies} />
                                    </div>
                                    <div>
                                        <Label>Follow-up required</Label>
                                        <textarea
                                            className="glass-input mt-1 min-h-24 w-full"
                                            value={reconciliation.data.follow_up}
                                            onChange={(e) => reconciliation.setData('follow_up', e.target.value)}
                                            required={reconciliation.data.status !== 'complete'}
                                        />
                                        <InputError message={reconciliation.errors.follow_up} />
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Append-only correction of</Label>
                                        <select
                                            className="glass-input mt-1 min-h-11 w-full"
                                            value={reconciliation.data.supersedes_id}
                                            onChange={(e) => reconciliation.setData('supersedes_id', e.target.value)}
                                        >
                                            <option value="">Not a correction</option>
                                            {reconciliations
                                                .filter((row) => !correctedReconciliations.has(row.id))
                                                .map((row) => (
                                                    <option key={row.id} value={row.id}>
                                                        Reconciliation #{row.id} · {format(row.reconciled_at)}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Correction reason</Label>
                                        <Input
                                            className="mt-1"
                                            value={reconciliation.data.correction_reason}
                                            onChange={(e) => reconciliation.setData('correction_reason', e.target.value)}
                                            disabled={!reconciliation.data.supersedes_id}
                                            required={Boolean(reconciliation.data.supersedes_id)}
                                        />
                                        <InputError message={reconciliation.errors.correction_reason} />
                                    </div>
                                </div>
                                <SignFields
                                    password={reconciliation.data.password}
                                    declaration={reconciliation.data.declaration}
                                    setPassword={(value) => reconciliation.setData('password', value)}
                                    setDeclaration={(value) => reconciliation.setData('declaration', value)}
                                    errors={reconciliationErrors}
                                />
                                <Button disabled={reconciliation.processing || reconciliationVisits.length === 0}>Sign reconciliation</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {canVerify && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Verify an external medication instruction</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="space-y-5" onSubmit={submitInstruction}>
                                <p className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                                    Transcribe only from an authoritative external instruction you have personally checked. This record is not a
                                    prescription and does not authorize prescribing.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    {(['medicine', 'dose', 'route', 'frequency'] as const).map((field) => (
                                        <div key={field}>
                                            <Label>{title(field)}</Label>
                                            <Input
                                                className="mt-1"
                                                value={instruction.data[field]}
                                                onChange={(e) => instruction.setData(field, e.target.value)}
                                                required
                                            />
                                            <InputError message={instruction.errors[field]} />
                                        </div>
                                    ))}
                                </div>
                                <div>
                                    <Label>Additional administration instructions</Label>
                                    <textarea
                                        className="glass-input mt-1 min-h-20 w-full"
                                        value={instruction.data.instructions}
                                        onChange={(e) => instruction.setData('instructions', e.target.value)}
                                    />
                                    <InputError message={instruction.errors.instructions} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Prescriber name</Label>
                                        <Input
                                            className="mt-1"
                                            value={instruction.data.prescriber_name}
                                            onChange={(e) => instruction.setData('prescriber_name', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Professional registration</Label>
                                        <Input
                                            className="mt-1"
                                            value={instruction.data.prescriber_registration}
                                            onChange={(e) => instruction.setData('prescriber_registration', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Source reference</Label>
                                        <Input
                                            className="mt-1"
                                            value={instruction.data.source_reference}
                                            onChange={(e) => instruction.setData('source_reference', e.target.value)}
                                            required
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>How the source was verified</Label>
                                    <textarea
                                        className="glass-input mt-1 min-h-20 w-full"
                                        value={instruction.data.verification_evidence}
                                        onChange={(e) => instruction.setData('verification_evidence', e.target.value)}
                                        required
                                    />
                                    <InputError message={instruction.errors.verification_evidence} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <Label>Verified time</Label>
                                        <Input
                                            className="mt-1"
                                            type="datetime-local"
                                            value={instruction.data.verified_at}
                                            onChange={(e) => instruction.setData('verified_at', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Effective from</Label>
                                        <Input
                                            className="mt-1"
                                            type="datetime-local"
                                            value={instruction.data.starts_at}
                                            onChange={(e) => instruction.setData('starts_at', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Effective until</Label>
                                        <Input
                                            className="mt-1"
                                            type="datetime-local"
                                            value={instruction.data.ends_at}
                                            onChange={(e) => instruction.setData('ends_at', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Status</Label>
                                        <select
                                            className="glass-input mt-1 min-h-11 w-full"
                                            value={instruction.data.status}
                                            onChange={(e) => instruction.setData('status', e.target.value)}
                                        >
                                            <option value="active">Active</option>
                                            <option value="discontinued">Discontinued</option>
                                        </select>
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Correction or replacement of</Label>
                                        <select
                                            className="glass-input mt-1 min-h-11 w-full"
                                            value={instruction.data.supersedes_id}
                                            onChange={(e) => instruction.setData('supersedes_id', e.target.value)}
                                        >
                                            <option value="">New verification</option>
                                            {instructions
                                                .filter((row) => !supersededInstructions.has(row.id))
                                                .map((row) => (
                                                    <option key={row.id} value={row.id}>
                                                        #{row.id} · {row.details.medicine} {row.details.dose}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Correction / replacement reason</Label>
                                        <Input
                                            className="mt-1"
                                            value={instruction.data.correction_reason}
                                            onChange={(e) => instruction.setData('correction_reason', e.target.value)}
                                            disabled={!instruction.data.supersedes_id}
                                            required={Boolean(instruction.data.supersedes_id)}
                                        />
                                    </div>
                                </div>
                                <SignFields
                                    password={instruction.data.password}
                                    declaration={instruction.data.declaration}
                                    setPassword={(value) => instruction.setData('password', value)}
                                    setDeclaration={(value) => instruction.setData('declaration', value)}
                                    errors={instruction.errors as Record<string, string | undefined>}
                                />
                                <Button disabled={instruction.processing}>Verify external instruction</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {canAdminister && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Record an administration outcome</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {activeInstructions.length === 0 || administrationVisits.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    A current verified instruction and an eligible assigned visit are required.
                                </p>
                            ) : (
                                <form className="space-y-5" onSubmit={submitAdministration}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label>Verified instruction</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={administration.data.medication_instruction_id}
                                                onChange={(e) => administration.setData('medication_instruction_id', e.target.value)}
                                                required
                                            >
                                                <option value="">Choose instruction</option>
                                                {activeInstructions.map((row) => (
                                                    <option key={row.id} value={row.id}>
                                                        {row.details.medicine} · {row.details.dose} · {row.details.route} · {row.details.frequency}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={administration.errors.medication_instruction_id} />
                                        </div>
                                        <div>
                                            <Label>Assigned visit</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={administration.data.appointment_id}
                                                onChange={(e) => administration.setData('appointment_id', e.target.value)}
                                                required
                                            >
                                                <option value="">Choose visit</option>
                                                {administrationVisits.map((visit) => (
                                                    <option key={visit.id} value={visit.id}>
                                                        {visit.service} · {format(visit.starts_at)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div>
                                            <Label>Actual time (Mauritius)</Label>
                                            <Input
                                                className="mt-1"
                                                type="datetime-local"
                                                value={administration.data.administered_at}
                                                onChange={(e) => administration.setData('administered_at', e.target.value)}
                                                required
                                            />
                                        </div>
                                        <div>
                                            <Label>Outcome</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={administration.data.outcome}
                                                onChange={(e) => administration.setData('outcome', e.target.value)}
                                            >
                                                <option value="administered">Administered</option>
                                                <option value="withheld">Withheld</option>
                                                <option value="refused">Refused</option>
                                                <option value="omitted">Omitted</option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label>Reason if not administered</Label>
                                            <Input
                                                className="mt-1"
                                                value={administration.data.reason}
                                                onChange={(e) => administration.setData('reason', e.target.value)}
                                                required={administration.data.outcome !== 'administered'}
                                            />
                                            <InputError message={administration.errors.reason} />
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Notes</Label>
                                        <textarea
                                            className="glass-input mt-1 min-h-20 w-full"
                                            value={administration.data.notes}
                                            onChange={(e) => administration.setData('notes', e.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label>Append-only correction of</Label>
                                            <select
                                                className="glass-input mt-1 min-h-11 w-full"
                                                value={administration.data.supersedes_id}
                                                onChange={(e) => administration.setData('supersedes_id', e.target.value)}
                                            >
                                                <option value="">Not a correction</option>
                                                {administrations
                                                    .filter((row) => !correctedAdministrations.has(row.id))
                                                    .map((row) => (
                                                        <option key={row.id} value={row.id}>
                                                            Administration #{row.id} · {format(row.administered_at)}
                                                        </option>
                                                    ))}
                                            </select>
                                        </div>
                                        <div>
                                            <Label>Correction reason</Label>
                                            <Input
                                                className="mt-1"
                                                value={administration.data.correction_reason}
                                                onChange={(e) => administration.setData('correction_reason', e.target.value)}
                                                disabled={!administration.data.supersedes_id}
                                                required={Boolean(administration.data.supersedes_id)}
                                            />
                                        </div>
                                    </div>
                                    <SignFields
                                        password={administration.data.password}
                                        declaration={administration.data.declaration}
                                        setPassword={(value) => administration.setData('password', value)}
                                        setDeclaration={(value) => administration.setData('declaration', value)}
                                        errors={administration.errors as Record<string, string | undefined>}
                                    />
                                    <Button disabled={administration.processing}>Sign administration outcome</Button>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}

                <section className="space-y-3" aria-labelledby="verified-instructions">
                    <h2 id="verified-instructions" className="text-xl font-semibold">
                        Verified external instructions
                    </h2>
                    {instructions.length === 0 ? (
                        <p className="text-muted-foreground">No verified instructions recorded.</p>
                    ) : (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {instructions.map((row) => (
                                <article className={`surface space-y-2 p-4 ${supersededInstructions.has(row.id) ? 'opacity-70' : ''}`} key={row.id}>
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <h3 className="font-semibold break-words">
                                            {row.details.medicine} · {row.details.dose}
                                        </h3>
                                        <Badge variant={row.status === 'active' && !supersededInstructions.has(row.id) ? 'default' : 'secondary'}>
                                            {supersededInstructions.has(row.id) ? 'Superseded' : title(row.status)}
                                        </Badge>
                                    </div>
                                    <p className="text-sm">
                                        {row.details.route} · {row.details.frequency}
                                    </p>
                                    {row.details.instructions && <p className="text-sm break-words">{row.details.instructions}</p>}
                                    <dl className="text-muted-foreground grid gap-1 text-xs sm:grid-cols-2">
                                        <div>
                                            <dt>External prescriber</dt>
                                            <dd className="break-words">
                                                {row.details.prescriber_name} · {row.details.prescriber_registration}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Source</dt>
                                            <dd className="break-words">{row.details.source_reference}</dd>
                                        </div>
                                        <div>
                                            <dt>Effective</dt>
                                            <dd>
                                                {format(row.starts_at)}
                                                {row.ends_at ? ` – ${format(row.ends_at)}` : ''}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Verified</dt>
                                            <dd>
                                                {format(row.verified_at)} by {row.verifier?.name}
                                            </dd>
                                        </div>
                                    </dl>
                                    {row.correction_reason && <p className="text-muted-foreground text-xs">Correction: {row.correction_reason}</p>}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
                <section className="space-y-3" aria-labelledby="administration-history">
                    <h2 id="administration-history" className="text-xl font-semibold">
                        Administration history
                    </h2>
                    {administrations.length === 0 ? (
                        <p className="text-muted-foreground">No administration outcomes recorded.</p>
                    ) : (
                        <div className="space-y-3">
                            {administrations.map((row) => (
                                <article className={`surface p-4 ${correctedAdministrations.has(row.id) ? 'opacity-70' : ''}`} key={row.id}>
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <h3 className="font-semibold break-words">
                                            {row.instruction_snapshot.medicine} · {row.instruction_snapshot.dose} · {row.instruction_snapshot.route}
                                        </h3>
                                        <Badge variant="secondary">{correctedAdministrations.has(row.id) ? 'Corrected' : title(row.outcome)}</Badge>
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {format(row.administered_at)} · {row.appointment?.service} · {row.administrator?.name}
                                    </p>
                                    {row.reason && <p className="mt-2 text-sm">Reason: {row.reason}</p>}
                                    {row.notes && <p className="mt-2 text-sm break-words">{row.notes}</p>}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
                <section className="space-y-3" aria-labelledby="reconciliation-history">
                    <h2 id="reconciliation-history" className="text-xl font-semibold">
                        Reconciliation history
                    </h2>
                    {reconciliations.length === 0 ? (
                        <p className="text-muted-foreground">No reconciliations recorded.</p>
                    ) : (
                        <div className="space-y-3">
                            {reconciliations.map((row) => (
                                <article className={`surface space-y-3 p-4 ${correctedReconciliations.has(row.id) ? 'opacity-70' : ''}`} key={row.id}>
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <div>
                                            <h3 className="font-semibold">Reconciliation #{row.id}</h3>
                                            <p className="text-muted-foreground text-sm">
                                                {format(row.reconciled_at)} · {row.appointment?.service} · {row.author?.name}
                                            </p>
                                        </div>
                                        <Badge variant="secondary">{correctedReconciliations.has(row.id) ? 'Corrected' : title(row.status)}</Badge>
                                    </div>
                                    <p className="text-sm">Sources: {row.sources.join('; ')}</p>
                                    {row.medicines.length === 0 ? (
                                        <p className="text-sm">No medicines reported.</p>
                                    ) : (
                                        <ul className="grid gap-2 sm:grid-cols-2">
                                            {row.medicines.map((medicine, index) => (
                                                <li className="glass-subtle rounded-lg p-3 text-sm" key={index}>
                                                    <span className="font-medium">{medicine.name}</span>
                                                    <br />
                                                    {medicine.dose} · {medicine.route} · {medicine.frequency}
                                                    <br />
                                                    <span className="text-muted-foreground">{title(medicine.status)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {row.discrepancies && <p className="text-sm">Discrepancies: {row.discrepancies}</p>}
                                    {row.follow_up && <p className="text-sm">Follow-up: {row.follow_up}</p>}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </main>
        </AppLayout>
    );
}
