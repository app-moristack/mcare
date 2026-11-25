import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { date, Errors } from '@/pages/patients/fields';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { Episode } from './episode-discharges';

const sbarFields = {
    situation: 'Situation',
    background: 'Background',
    assessment: 'Assessment',
    recommendation: 'Recommendation',
};

type SbarContent = Record<keyof typeof sbarFields, string>;
type Clinician = { id: number; name: string };
type Recipient = Clinician & { canAcceptTransfers?: boolean; canAcceptResultTransfers?: boolean };
type TransferItem = { id: number; title: string };
type AvailableTransferItem = TransferItem & { version: number };

type TransferSelection = Pick<AvailableTransferItem, 'id' | 'version'>;

type Handover = {
    id: number;
    episode_id: number;
    sender: Clinician;
    recipient: Clinician;
    content: SbarContent;
    created_at: string;
    priority: 'routine' | 'urgent';
    due_at: string;
    acknowledged_at: string | null;
    acknowledgement: string | null;
    canAcknowledge: boolean;
    transfer_tasks: TransferItem[];
    transfer_results: TransferItem[];
};

export type HandoverData = {
    canCreate: boolean;
    recipients: Recipient[];
    records: Handover[];
    transferTasks?: AvailableTransferItem[];
    transferResults?: AvailableTransferItem[];
};

const emptyHandover = {
    request_id: '',
    priority: 'routine',
    due_at: '',
    recipient_id: '',
    transfer_tasks: [] as TransferSelection[],
    transfer_results: [] as TransferSelection[],
    situation: '',
    background: '',
    assessment: '',
    recommendation: '',
    password: '',
    declaration: false as boolean,
};

const emptyAcknowledgement = { acknowledgement: '', password: '', declaration: false as boolean, accept_transfer: false as boolean };
const maxTransferTasks = 20;
const maxTransferResults = 20;
const selectClass = 'border-input bg-background w-full min-w-0 rounded-md border p-2 text-base';

function TransferList({ items }: { items: TransferItem[] }) {
    return (
        <ul className="list-disc space-y-1 ps-5">
            {items.map((task) => (
                <li className="break-words" key={task.id}>
                    {task.title}
                </li>
            ))}
        </ul>
    );
}

function OfferTransfers({
    items,
    selected,
    onChange,
    eligible,
    label,
    limit,
    hintId,
}: {
    items: AvailableTransferItem[];
    selected: TransferSelection[];
    onChange: (items: TransferSelection[]) => void;
    eligible: boolean;
    label: string;
    limit: number;
    hintId: string;
}) {
    if (!items.length) return null;

    const selectedIds = new Set(selected.map(({ id }) => id));

    return (
        <fieldset className="min-w-0 space-y-2 rounded-md border p-3 text-sm">
            <legend className="px-1 font-medium">Offer {label} for transfer</legend>
            <p className="text-muted-foreground" id={hintId}>
                {eligible
                    ? `Select up to ${limit}. You remain responsible for follow-up until the recipient accepts.`
                    : `Choose a recipient who can accept responsibility for these ${label} and any other items you selected.`}
            </p>
            <div className="max-h-52 space-y-1 overflow-y-auto">
                {items.map(({ id, title, version }) => (
                    <label className="hover:bg-muted/50 flex min-h-11 min-w-0 items-start gap-2 rounded-md px-2 py-3" key={id}>
                        <input
                            className="mt-0.5"
                            type="checkbox"
                            aria-describedby={hintId}
                            disabled={!selectedIds.has(id) && (!eligible || selected.length >= limit)}
                            checked={selectedIds.has(id)}
                            onChange={(event) =>
                                onChange(event.target.checked ? [...selected, { id, version }] : selected.filter((item) => item.id !== id))
                            }
                        />
                        <span className="min-w-0 break-words">{title}</span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

function TransferItems({ tasks, results }: { tasks: TransferItem[]; results: TransferItem[] }) {
    return (
        <>
            {tasks.length > 0 && (
                <div className="space-y-1">
                    <p className="font-medium">Care tasks</p>
                    <TransferList items={tasks} />
                </div>
            )}
            {results.length > 0 && (
                <div className="space-y-1">
                    <p className="font-medium">Investigation results</p>
                    <TransferList items={results} />
                </div>
            )}
        </>
    );
}

function Signature({
    password,
    declaration,
    onPassword,
    onDeclaration,
    label,
}: {
    password: string;
    declaration: boolean;
    onPassword: (value: string) => void;
    onDeclaration: (value: boolean) => void;
    label: string;
}) {
    return (
        <>
            <label className="block space-y-1 text-sm font-medium">
                <span>Current password</span>
                <Input
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(event) => onPassword(event.target.value)}
                />
            </label>
            <label className="flex items-start gap-2 text-sm">
                <input className="mt-1" type="checkbox" required checked={declaration} onChange={(event) => onDeclaration(event.target.checked)} />
                <span>{label}</span>
            </label>
        </>
    );
}

export default function EpisodeHandovers({ base, episodes, handover }: { base: string; episodes: Episode[]; handover: HandoverData }) {
    const [creating, setCreating] = useState(false);
    const [episodeId, setEpisodeId] = useState('');
    const [selected, setSelected] = useState<Handover | null>(null);
    const create = useForm(emptyHandover);
    const acknowledge = useForm(emptyAcknowledgement);
    const openEpisodes = episodes.filter((episode) => !episode.discharged_at);
    const episodeTitles = new Map(episodes.map((episode) => [episode.id, episode.title]));
    const transferTasks = handover.transferTasks ?? [];
    const transferResults = handover.transferResults ?? [];
    const hasTasks = create.data.transfer_tasks.length > 0;
    const hasResults = create.data.transfer_results.length > 0;
    const recipient = handover.recipients.find(({ id }) => String(id) === create.data.recipient_id);
    const possibleRecipients = recipient ? [recipient] : handover.recipients;
    const canOfferTasks = possibleRecipients.some((person) => person.canAcceptTransfers && (!hasResults || person.canAcceptResultTransfers));
    const canOfferResults = possibleRecipients.some((person) => person.canAcceptResultTransfers && (!hasTasks || person.canAcceptTransfers));
    const acceptingTransfer = !!selected && (selected.transfer_tasks.length > 0 || selected.transfer_results.length > 0);

    return (
        <section className="surface min-w-0 space-y-4 p-4 sm:p-6" aria-labelledby="episode-handover-heading">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 className="font-semibold" id="episode-handover-heading">
                    Clinical handovers
                </h2>
                {handover.canCreate && openEpisodes.length > 0 && handover.recipients.length > 0 && (
                    <Button
                        variant="outline"
                        onClick={() => {
                            create.clearErrors();
                            create.setData({
                                ...emptyHandover,
                                request_id: crypto.randomUUID(),
                            });
                            setEpisodeId(openEpisodes.length === 1 ? String(openEpisodes[0].id) : '');
                            setCreating(true);
                        }}
                    >
                        New handover
                    </Button>
                )}
            </div>
            {handover.records.length === 0 && <p className="text-muted-foreground text-sm">No handovers to display.</p>}
            {handover.canCreate && openEpisodes.length === 0 && (
                <p className="text-muted-foreground text-sm">An open care episode is required to create a handover.</p>
            )}
            {handover.canCreate && openEpisodes.length > 0 && handover.recipients.length === 0 && (
                <p className="text-muted-foreground text-sm">There are no eligible clinical recipients for this patient.</p>
            )}
            {handover.records.map((record) => (
                <article className="min-w-0 space-y-3 rounded-lg border p-3" key={record.id}>
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <h3 className="min-w-0 font-medium break-words">{episodeTitles.get(record.episode_id) ?? 'Care episode'}</h3>
                        <Badge variant={record.acknowledged_at ? 'success' : 'secondary'}>
                            {record.acknowledged_at ? 'Acknowledged' : 'Awaiting acknowledgement'}
                        </Badge>
                    </div>
                    <dl className="grid min-w-0 gap-2 text-sm sm:grid-cols-2">
                        <div className="min-w-0">
                            <dt className="text-muted-foreground">Sender</dt>
                            <dd className="break-words">{record.sender.name}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className="text-muted-foreground">Recipient</dt>
                            <dd className="break-words">{record.recipient.name}</dd>
                        </div>
                    </dl>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <span>{record.priority === 'urgent' ? 'Urgent' : 'Routine'}</span>
                        <span>Acknowledgement due {date(record.due_at)} (Mauritius time)</span>
                        <span className="text-muted-foreground">Signed {date(record.created_at)}</span>
                    </div>
                    <details className="min-w-0 space-y-3 text-sm">
                        <summary className="cursor-pointer font-medium">Read handover</summary>
                        <dl className="space-y-3">
                            {Object.entries(sbarFields).map(([key, label]) => (
                                <div key={key}>
                                    <dt className="font-medium">{label}</dt>
                                    <dd className="break-words whitespace-pre-wrap">{record.content[key as keyof SbarContent]}</dd>
                                </div>
                            ))}
                            {record.acknowledged_at && (
                                <div>
                                    <dt className="font-medium">Acknowledged {date(record.acknowledged_at)}</dt>
                                    <dd className="break-words whitespace-pre-wrap">{record.acknowledgement}</dd>
                                </div>
                            )}
                        </dl>
                    </details>
                    {(record.transfer_tasks.length > 0 || record.transfer_results.length > 0) && (
                        <div className="space-y-2 text-sm">
                            <h4 className="font-medium">{record.acknowledged_at ? 'Accepted transfers' : 'Offered transfers'}</h4>
                            <TransferItems tasks={record.transfer_tasks} results={record.transfer_results} />
                            {!record.acknowledged_at && (
                                <p className="text-muted-foreground">The current owners remain responsible until the recipient accepts.</p>
                            )}
                        </div>
                    )}
                    {record.canAcknowledge && (
                        <Button
                            variant="outline"
                            onClick={() => {
                                acknowledge.clearErrors();
                                acknowledge.reset();
                                setSelected(record);
                            }}
                        >
                            Review and acknowledge
                        </Button>
                    )}
                </article>
            ))}

            <Dialog
                open={creating}
                onOpenChange={(open) => {
                    if (!open && !create.processing) {
                        create.reset();
                        setCreating(false);
                    }
                }}
            >
                <DialogContent className="max-h-[85dvh] w-[calc(100%-1.5rem)] overflow-y-auto sm:max-w-xl">
                    <DialogTitle>Sign a clinical handover</DialogTitle>
                    <DialogDescription>
                        Send an SBAR handover to a named clinician for acknowledgement. For emergencies, use your approved escalation route.
                    </DialogDescription>
                    <form
                        className="min-w-0 space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const deadline = new FormData(event.currentTarget).get('due_at');
                            create.transform((payload) => ({ ...payload, due_at: typeof deadline === 'string' ? deadline : '' }));
                            create.post(`${base}/episodes/${episodeId}/handovers`, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    create.reset();
                                    setCreating(false);
                                },
                                onFinish: () => create.setData('password', ''),
                            });
                        }}
                    >
                        <Errors errors={create.errors} />
                        <label className="block space-y-1 text-sm font-medium">
                            <span>Care episode</span>
                            <select className={selectClass} required value={episodeId} onChange={(event) => setEpisodeId(event.target.value)}>
                                <option value="" disabled>
                                    Select an episode
                                </option>
                                {openEpisodes.map((episode) => (
                                    <option key={episode.id} value={episode.id}>
                                        {episode.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block space-y-1 text-sm font-medium">
                            <span>Clinical recipient</span>
                            <select
                                className={selectClass}
                                required
                                value={create.data.recipient_id}
                                onChange={(event) => create.setData('recipient_id', event.target.value)}
                            >
                                <option value="" disabled>
                                    Select a recipient
                                </option>
                                {handover.recipients.map((person) => (
                                    <option
                                        key={person.id}
                                        value={person.id}
                                        disabled={(hasTasks && !person.canAcceptTransfers) || (hasResults && !person.canAcceptResultTransfers)}
                                    >
                                        {person.name}
                                        {(transferTasks.length > 0 || transferResults.length > 0) &&
                                        !person.canAcceptTransfers &&
                                        !person.canAcceptResultTransfers
                                            ? ' (handover only)'
                                            : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label className="block min-w-0 space-y-1 text-sm font-medium">
                                <span>Priority</span>
                                <select
                                    className={selectClass}
                                    required
                                    value={create.data.priority}
                                    onChange={(event) => create.setData('priority', event.target.value)}
                                >
                                    <option value="routine">Routine</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </label>
                            <label className="block min-w-0 space-y-1 text-sm font-medium">
                                <span>Acknowledgement due (Mauritius time)</span>
                                <Input type="datetime-local" name="due_at" required />
                            </label>
                        </div>
                        {Object.entries(sbarFields).map(([key, label]) => (
                            <label className="block space-y-1 text-sm font-medium" key={key}>
                                <span>{label}</span>
                                <textarea
                                    className={selectClass}
                                    rows={3}
                                    maxLength={4000}
                                    required
                                    value={create.data[key as keyof SbarContent]}
                                    onChange={(event) => create.setData(key as keyof SbarContent, event.target.value)}
                                />
                            </label>
                        ))}
                        <OfferTransfers
                            items={transferTasks}
                            selected={create.data.transfer_tasks}
                            onChange={(items) => create.setData('transfer_tasks', items)}
                            eligible={canOfferTasks}
                            label="care tasks"
                            limit={maxTransferTasks}
                            hintId="handover-task-transfer-hint"
                        />
                        <OfferTransfers
                            items={transferResults}
                            selected={create.data.transfer_results}
                            onChange={(items) => create.setData('transfer_results', items)}
                            eligible={canOfferResults}
                            label="investigation results"
                            limit={maxTransferResults}
                            hintId="handover-result-transfer-hint"
                        />
                        <Signature
                            password={create.data.password}
                            declaration={create.data.declaration}
                            onPassword={(value) => create.setData('password', value)}
                            onDeclaration={(value) => create.setData('declaration', value)}
                            label="I confirm the details and intended recipient are correct."
                        />
                        <Button type="submit" disabled={create.processing}>
                            Sign and send handover
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open && !acknowledge.processing) {
                        acknowledge.reset();
                        setSelected(null);
                    }
                }}
            >
                <DialogContent className="max-h-[85dvh] w-[calc(100%-1.5rem)] overflow-y-auto sm:max-w-xl">
                    <DialogTitle>Acknowledge handover</DialogTitle>
                    <DialogDescription className="break-words">
                        Record your acknowledgement of the handover from {selected?.sender.name}.
                    </DialogDescription>
                    {selected && (
                        <form
                            className="min-w-0 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                acknowledge.post(`${base}/episodes/${selected.episode_id}/handovers/${selected.id}/acknowledge`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        acknowledge.reset();
                                        setSelected(null);
                                    },
                                    onFinish: () => acknowledge.setData('password', ''),
                                });
                            }}
                        >
                            <Errors errors={acknowledge.errors} />
                            <dl className="space-y-3 text-sm">
                                {Object.entries(sbarFields).map(([key, label]) => (
                                    <div key={key}>
                                        <dt className="font-medium">{label}</dt>
                                        <dd className="break-words whitespace-pre-wrap">{selected.content[key as keyof SbarContent]}</dd>
                                    </div>
                                ))}
                            </dl>
                            <label className="block space-y-1 text-sm font-medium">
                                <span>Acknowledgement and follow-up</span>
                                <textarea
                                    className={selectClass}
                                    rows={3}
                                    maxLength={4000}
                                    required
                                    value={acknowledge.data.acknowledgement}
                                    onChange={(event) => acknowledge.setData('acknowledgement', event.target.value)}
                                />
                            </label>
                            {acceptingTransfer && (
                                <fieldset className="min-w-0 space-y-3 rounded-md border p-3 text-sm">
                                    <legend className="px-1 font-medium">Accept follow-up responsibility</legend>
                                    <p>When you sign and accept this handover, you take responsibility for the listed work.</p>
                                    <TransferItems tasks={selected.transfer_tasks} results={selected.transfer_results} />
                                    <label className="flex min-h-11 items-start gap-2 py-2 font-medium">
                                        <input
                                            className="mt-1"
                                            type="checkbox"
                                            required
                                            checked={acknowledge.data.accept_transfer}
                                            onChange={(event) => acknowledge.setData('accept_transfer', event.target.checked)}
                                        />
                                        <span>I accept responsibility for all listed follow-up.</span>
                                    </label>
                                </fieldset>
                            )}
                            <Signature
                                password={acknowledge.data.password}
                                declaration={acknowledge.data.declaration}
                                onPassword={(value) => acknowledge.setData('password', value)}
                                onDeclaration={(value) => acknowledge.setData('declaration', value)}
                                label="I confirm I reviewed this handover and recorded my acknowledgement."
                            />
                            <Button type="submit" disabled={acknowledge.processing}>
                                {acceptingTransfer ? 'Sign and accept transfers' : 'Sign acknowledgement'}
                            </Button>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
