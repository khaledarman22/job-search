import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { errorMessage } from '@/api/client';
import {
    useContacts,
    useImportEmails,
    useQueueContact,
    useSuppressContact,
    useUpdateContact,
} from '@/api/queries/contacts';
import type { Contact } from '@/api/types';
import { DataTable } from '@/components/shared/DataTable';
import type { Column } from '@/components/shared/DataTable';
import { FilterBar } from '@/components/shared/FilterBar';
import { PageHeader } from '@/components/shared/PageHeader';
import { Badge, StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useToast } from '@/components/ui/Toast';
import { Toggle } from '@/components/ui/Toggle';
import { Tooltip } from '@/components/ui/Tooltip';
import {
    IconBan,
    IconCheck,
    IconFile,
    IconPencil,
    IconPlus,
    IconUpload,
    IconUsers,
    IconWhatsApp,
} from '@/components/ui/icons';
import { formatDateTime } from '@/lib/format';
import { useDebouncedValue } from '@/lib/hooks';
import { t } from '@/lib/i18n';

const CONTACT_STATUSES = ['new', 'queued', 'contacted', 'suppressed', 'invalid'];

function ContactBadges({ contact }: { contact: Contact }) {
    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {contact.last_sent_at && (
                <Tooltip content={t.contacts.lastSentAt(formatDateTime(contact.last_sent_at))}>
                    <Badge color="indigo">{t.contacts.badges.sentBefore}</Badge>
                </Tooltip>
            )}
            {contact.last_opened_at && <Badge color="emerald">{t.contacts.badges.opened}</Badge>}
            {contact.sightings_count > 1 && (
                <Badge color="amber">{t.contacts.badges.reappeared(contact.sightings_count - 1)}</Badge>
            )}
            {!contact.email && contact.phone && (
                <Badge color="teal">{t.contacts.badges.whatsappOnly}</Badge>
            )}
            {contact.is_alternate && <Badge color="slate">{t.contacts.badges.alternate}</Badge>}
        </div>
    );
}

interface EditState {
    contact: Contact;
    email: string;
    name: string;
    phone: string;
    position: string;
}

export function ContactsPage() {
    const [status, setStatus] = useState('');
    const [phoneOnly, setPhoneOnly] = useState('');
    const [sentBefore, setSentBefore] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const q = useDebouncedValue(search, 400);
    const { data: result, isLoading } = useContacts({
        status,
        phone_only: phoneOnly,
        sent_before: sentBefore,
        q,
        page,
    });

    const queueContact = useQueueContact();
    const suppressContact = useSuppressContact();
    const updateContact = useUpdateContact();
    const importEmails = useImportEmails();
    const { toast } = useToast();

    const [queueTarget, setQueueTarget] = useState<Contact | null>(null);
    const [editState, setEditState] = useState<EditState | null>(null);

    const [importOpen, setImportOpen] = useState(false);
    const [importText, setImportText] = useState('');
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importQueue, setImportQueue] = useState(true);
    const importFileInputRef = useRef<HTMLInputElement>(null);

    const closeImport = () => {
        setImportOpen(false);
        setImportText('');
        setImportFile(null);
        setImportQueue(true);
        if (importFileInputRef.current) importFileInputRef.current.value = '';
    };

    const submitImport = (e: FormEvent) => {
        e.preventDefault();
        if (!importText.trim() && !importFile) {
            toast(t.contacts.import.emptyError, 'error');
            return;
        }
        importEmails.mutate(
            { emails: importText, file: importFile, queue: importQueue },
            {
                onSuccess: (stats) => {
                    toast(
                        t.contacts.import.summary(
                            stats.imported,
                            stats.queued,
                            stats.duplicates,
                            stats.invalid,
                        ),
                    );
                    closeImport();
                },
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const setFilter = (setter: (v: string) => void) => (value: string) => {
        setter(value);
        setPage(1);
    };

    const confirmQueue = () => {
        if (!queueTarget) return;
        queueContact.mutate(
            { id: queueTarget.id },
            {
                onSuccess: () => {
                    toast(t.contacts.queuedOk);
                    setQueueTarget(null);
                },
                onError: (err) => {
                    toast(errorMessage(err), 'error');
                    setQueueTarget(null);
                },
            },
        );
    };

    const toggleSuppress = (contact: Contact) => {
        const suppress = contact.status !== 'suppressed';
        suppressContact.mutate(
            { id: contact.id, suppress },
            {
                onSuccess: () => toast(suppress ? t.contacts.suppressedOk : t.contacts.unsuppressedOk),
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const submitEdit = (e: FormEvent) => {
        e.preventDefault();
        if (!editState) return;
        updateContact.mutate(
            {
                id: editState.contact.id,
                data: {
                    email: editState.email,
                    name: editState.name,
                    phone: editState.phone,
                    position: editState.position,
                },
            },
            {
                onSuccess: () => {
                    toast(t.contacts.savedOk);
                    setEditState(null);
                },
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const columns: Column<Contact>[] = [
        {
            key: 'contact',
            header: t.contacts.columns.contact,
            render: (contact) => (
                <div>
                    <p className="text-sm font-semibold text-slate-700" dir={contact.email ? 'ltr' : undefined}>
                        {contact.email ?? contact.name ?? '—'}
                    </p>
                    {contact.email && contact.name && (
                        <p className="text-xs text-slate-500">{contact.name}</p>
                    )}
                    <ContactBadges contact={contact} />
                </div>
            ),
        },
        {
            key: 'company',
            header: t.contacts.columns.company,
            render: (contact) => <span className="text-slate-700">{contact.company.name}</span>,
        },
        {
            key: 'phone',
            header: t.contacts.columns.phone,
            render: (contact) =>
                contact.whatsapp_url ? (
                    <a
                        href={contact.whatsapp_url}
                        target="_blank"
                        rel="noreferrer"
                        dir="ltr"
                        className="inline-flex items-center gap-1.5 font-medium text-green-600 hover:text-green-700 hover:underline"
                    >
                        <IconWhatsApp className="size-4" />
                        {contact.phone ?? 'WhatsApp'}
                    </a>
                ) : contact.phone ? (
                    <span dir="ltr" className="text-slate-600">
                        {contact.phone}
                    </span>
                ) : (
                    <span className="text-slate-400">—</span>
                ),
        },
        {
            key: 'status',
            header: t.contacts.columns.status,
            render: (contact) => <StatusBadge status={contact.status} />,
        },
        {
            key: 'actions',
            header: t.contacts.columns.actions,
            className: 'w-32',
            render: (contact) => (
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        title={t.contacts.actions.queue}
                        onClick={() => setQueueTarget(contact)}
                        className="rounded-md p-1.5 text-indigo-500 hover:bg-indigo-50"
                    >
                        <IconPlus className="size-4" />
                    </button>
                    <button
                        type="button"
                        title={
                            contact.status === 'suppressed'
                                ? t.contacts.actions.unsuppress
                                : t.contacts.actions.suppress
                        }
                        onClick={() => toggleSuppress(contact)}
                        className={`rounded-md p-1.5 ${
                            contact.status === 'suppressed'
                                ? 'text-emerald-500 hover:bg-emerald-50'
                                : 'text-slate-400 hover:bg-slate-100 hover:text-red-500'
                        }`}
                    >
                        {contact.status === 'suppressed' ? (
                            <IconCheck className="size-4" />
                        ) : (
                            <IconBan className="size-4" />
                        )}
                    </button>
                    <button
                        type="button"
                        title={t.contacts.actions.edit}
                        onClick={() =>
                            setEditState({
                                contact,
                                email: contact.email ?? '',
                                name: contact.name ?? '',
                                phone: contact.phone ?? '',
                                position: contact.position ?? '',
                            })
                        }
                        className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <IconPencil className="size-4" />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <div>
            <PageHeader
                title={t.contacts.title}
                description={t.contacts.subtitle}
                actions={
                    <Button variant="secondary" onClick={() => setImportOpen(true)}>
                        <IconUpload className="size-4" />
                        {t.contacts.import.button}
                    </Button>
                }
            />

            <FilterBar>
                <Input
                    label={t.common.search}
                    placeholder={t.contacts.filters.search}
                    value={search}
                    onChange={(e) => setFilter(setSearch)(e.target.value)}
                    className="w-64"
                />
                <Select
                    label={t.contacts.filters.status}
                    value={status}
                    onChange={(e) => setFilter(setStatus)(e.target.value)}
                    className="w-40"
                >
                    <option value="">{t.common.all}</option>
                    {CONTACT_STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {t.status[s]}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t.contacts.filters.phoneOnly}
                    value={phoneOnly}
                    onChange={(e) => setFilter(setPhoneOnly)(e.target.value)}
                    className="w-32"
                >
                    <option value="">{t.common.all}</option>
                    <option value="1">{t.common.yes}</option>
                </Select>
                <Select
                    label={t.contacts.filters.sentBefore}
                    value={sentBefore}
                    onChange={(e) => setFilter(setSentBefore)(e.target.value)}
                    className="w-32"
                >
                    <option value="">{t.common.all}</option>
                    <option value="1">{t.common.yes}</option>
                    <option value="0">{t.common.no}</option>
                </Select>
            </FilterBar>

            <DataTable
                columns={columns}
                result={result}
                isLoading={isLoading}
                page={page}
                onPageChange={setPage}
                rowKey={(contact) => contact.id}
                emptyTitle={t.contacts.empty}
                emptyDescription={t.contacts.emptyDesc}
                emptyIcon={<IconUsers className="size-6" />}
            />

            <ConfirmDialog
                open={queueTarget !== null}
                title={t.contacts.queueConfirm.title}
                loading={queueContact.isPending}
                onClose={() => setQueueTarget(null)}
                onConfirm={confirmQueue}
                message={
                    queueTarget && (
                        <div className="space-y-2">
                            <p>
                                {t.contacts.queueConfirm.message(
                                    queueTarget.name ?? queueTarget.email ?? String(queueTarget.id),
                                )}
                            </p>
                            {queueTarget.last_sent_at && (
                                <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                                    {t.contacts.queueConfirm.warning(formatDateTime(queueTarget.last_sent_at))}
                                </p>
                            )}
                        </div>
                    )
                }
            />

            <Modal
                open={editState !== null}
                onClose={() => setEditState(null)}
                title={t.contacts.editTitle}
            >
                {editState && (
                    <form onSubmit={submitEdit} className="space-y-4">
                        <Input
                            label={t.contacts.fields.email}
                            type="email"
                            dir="ltr"
                            value={editState.email}
                            onChange={(e) => setEditState({ ...editState, email: e.target.value })}
                        />
                        <Input
                            label={t.contacts.fields.name}
                            value={editState.name}
                            onChange={(e) => setEditState({ ...editState, name: e.target.value })}
                        />
                        <Input
                            label={t.contacts.fields.phone}
                            dir="ltr"
                            value={editState.phone}
                            onChange={(e) => setEditState({ ...editState, phone: e.target.value })}
                        />
                        <Input
                            label={t.contacts.fields.position}
                            value={editState.position}
                            onChange={(e) => setEditState({ ...editState, position: e.target.value })}
                        />
                        <div className="flex justify-end gap-2 pt-1">
                            <Button variant="secondary" onClick={() => setEditState(null)}>
                                {t.common.cancel}
                            </Button>
                            <Button type="submit" loading={updateContact.isPending}>
                                {t.common.save}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>

            <Modal open={importOpen} onClose={closeImport} title={t.contacts.import.title}>
                <form onSubmit={submitImport} className="space-y-4">
                    <p className="text-sm text-slate-500">{t.contacts.import.description}</p>
                    <Textarea
                        label={t.contacts.import.textareaLabel}
                        placeholder={t.contacts.import.textareaPlaceholder}
                        dir="ltr"
                        rows={6}
                        value={importText}
                        onChange={(e) => setImportText(e.target.value)}
                    />
                    <div>
                        <label className="mb-1.5 block text-xs font-semibold text-slate-600">
                            {t.contacts.import.fileLabel}
                        </label>
                        <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <IconFile className="size-4" />
                            {importFile ? importFile.name : t.contacts.import.chooseFile}
                            <input
                                ref={importFileInputRef}
                                type="file"
                                accept=".txt,.csv,text/plain,text/csv"
                                className="hidden"
                                onChange={(e) => setImportFile(e.target.files?.[0] ?? null)}
                            />
                        </label>
                    </div>
                    <Toggle
                        checked={importQueue}
                        onChange={setImportQueue}
                        label={t.contacts.import.queueCheckbox}
                    />
                    <div className="flex justify-end gap-2 pt-1">
                        <Button variant="secondary" onClick={closeImport}>
                            {t.common.cancel}
                        </Button>
                        <Button type="submit" loading={importEmails.isPending}>
                            {t.contacts.import.submit}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
