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
};

export type HandoverData = { canCreate: boolean; recipients: Clinician[]; records: Handover[] };

const emptyHandover = {
    request_id: '',
    priority: 'routine',
    due_at: '',
    recipient_id: '',
    situation: '',
    background: '',
    assessment: '',
    recommendation: '',
    password: '',
    declaration: false as boolean,
};

const emptyAcknowledgement = { acknowledgement: '', password: '', declaration: false as boolean };
const selectClass = 'border-input bg-background w-full min-w-0 rounded-md border p-2 text-base';

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
                                {handover.recipients.map((recipient) => (
                                    <option key={recipient.id} value={recipient.id}>
                                        {recipient.name}
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
                                <Input
                                    type="datetime-local"
                                    required
                                    value={create.data.due_at}
                                    onChange={(event) => create.setData('due_at', event.target.value)}
                                />
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
                            <Signature
                                password={acknowledge.data.password}
                                declaration={acknowledge.data.declaration}
                                onPassword={(value) => acknowledge.setData('password', value)}
                                onDeclaration={(value) => acknowledge.setData('declaration', value)}
                                label="I confirm I reviewed this handover and recorded my acknowledgement."
                            />
                            <Button type="submit" disabled={acknowledge.processing}>
                                Sign acknowledgement
                            </Button>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
