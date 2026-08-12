import { useState } from 'react';
import {
    DndContext,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { errorMessage } from '@/api/client';
import { useDashboard, usePauseSending, useResumeSending } from '@/api/queries/dashboard';
import {
    useCancelEmail,
    useEmails,
    useQueueList,
    useReorderQueue,
    useRequeueEmail,
    useSendingNow,
    useSendNowEmail,
    useAddManualEmail,
} from '@/api/queries/emails';
import type { OutreachEmail } from '@/api/types';
import { CountdownTimer } from '@/components/shared/CountdownTimer';
import { DataTable } from '@/components/shared/DataTable';
import type { Column } from '@/components/shared/DataTable';
import { PageHeader } from '@/components/shared/PageHeader';
import { Badge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { IconClock, IconGrip, IconPause, IconPlay, IconQueue, IconRefresh, IconX, IconSend, IconPlus } from '@/components/ui/icons';
import { formatDateTime, timeAgo } from '@/lib/format';
import { t } from '@/lib/i18n';

function QueueHeaderStrip() {
    const { data } = useDashboard();
    const pause = usePauseSending();
    const resume = useResumeSending();

    if (!data) return <Skeleton className="h-14 w-full rounded-xl" />;

    const { sending, stats } = data;
    const busy = pause.isPending || resume.isPending;

    return (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xs">
            <div className="flex flex-wrap items-center gap-4 text-sm">
                <span className="flex items-center gap-1.5 font-semibold text-slate-600">
                    <span
                        className={`size-2 rounded-full ${
                            sending.enabled ? 'animate-pulse bg-emerald-500' : 'bg-red-500'
                        }`}
                    />
                    {sending.enabled ? t.overview.sending.active : t.overview.sending.paused}
                </span>
                {sending.enabled && sending.next_send_at && !sending.cap_reached && (
                    <span className="flex items-center gap-1.5 text-slate-500">
                        <IconClock className="size-4" />
                        {t.queue.nextSend}
                        <CountdownTimer to={sending.next_send_at} className="text-indigo-600" />
                    </span>
                )}
                <span className="text-slate-500">
                    {t.queue.sentToday}{' '}
                    <span dir="ltr" className="font-bold text-slate-700 tabular-nums">
                        {stats.sent_today}/{stats.daily_cap}
                    </span>
                </span>
            </div>
            <Button
                size="sm"
                variant={sending.enabled ? 'danger' : 'success'}
                loading={busy}
                onClick={() => (sending.enabled ? pause.mutate() : resume.mutate())}
            >
                {sending.enabled ? (
                    <>
                        <IconPause className="size-3.5" /> {t.overview.sending.pause}
                    </>
                ) : (
                    <>
                        <IconPlay className="size-3.5" /> {t.overview.sending.resume}
                    </>
                )}
            </Button>
        </div>
    );
}

function QueueRowContent({ email }: { email: OutreachEmail }) {
    return (
        <>
            <div className="min-w-0 grow">
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-sm font-semibold text-slate-700" dir="ltr">
                        {email.to_email}
                    </span>
                    {email.is_manual && <Badge color="indigo">{t.queue.manual}</Badge>}
                </div>
                <p className="mt-0.5 truncate text-xs text-slate-500">
                    {email.company.name}
                    {email.job_post ? ` · ${email.job_post.title}` : ''}
                </p>
            </div>
            <span className="shrink-0 text-xs whitespace-nowrap text-slate-400">
                {t.queue.queuedAt} {timeAgo(email.queued_at)}
            </span>
        </>
    );
}

function SortableQueueRow({
    email,
    onCancel,
    onSendNow,
    isSendingNow,
}: {
    email: OutreachEmail;
    onCancel: (email: OutreachEmail) => void;
    onSendNow: (email: OutreachEmail) => void;
    isSendingNow: boolean;
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: email.id,
    });

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`flex items-center gap-3 border-b border-slate-100 bg-white px-4 py-3 last:border-0 ${
                isDragging ? 'relative z-10 rounded-lg shadow-lg' : ''
            }`}
        >
            <button
                type="button"
                title={t.queue.dragHint}
                className="shrink-0 cursor-grab touch-none text-slate-300 hover:text-slate-500 active:cursor-grabbing"
                {...attributes}
                {...listeners}
            >
                <IconGrip className="size-4.5" />
            </button>
            <QueueRowContent email={email} />
            <button
                type="button"
                title="إرسال الآن"
                disabled={isSendingNow}
                onClick={() => onSendNow(email)}
                className="shrink-0 rounded-md p-1.5 text-indigo-500 hover:bg-indigo-50 disabled:opacity-50"
            >
                <IconSend className="size-4" />
            </button>
            <button
                type="button"
                title={t.queue.remove}
                onClick={() => onCancel(email)}
                className="shrink-0 rounded-md p-1.5 text-slate-300 hover:bg-red-50 hover:text-red-500"
            >
                <IconX className="size-4" />
            </button>
        </li>
    );
}

function QueueTab() {
    const { data: queueData, isLoading } = useQueueList();
    const { data: sendingData } = useSendingNow();
    const reorder = useReorderQueue();
    const cancelEmail = useCancelEmail();
    const sendNow = useSendNowEmail();
    const addManual = useAddManualEmail();
    const { toast } = useToast();
    const [cancelTarget, setCancelTarget] = useState<OutreachEmail | null>(null);
    const [manualEmail, setManualEmail] = useState('');

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));
    const items = queueData?.data ?? [];
    const sendingItems = sendingData?.data ?? [];

    const onDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;
        if (!over || active.id === over.id) return;
        const oldIndex = items.findIndex((i) => i.id === active.id);
        const newIndex = items.findIndex((i) => i.id === over.id);
        if (oldIndex < 0 || newIndex < 0) return;
        const next = arrayMove(items, oldIndex, newIndex);
        reorder.mutate(
            next.map((i) => i.id),
            { onError: (err) => toast(`${t.queue.reorderFailed}: ${errorMessage(err)}`, 'error') },
        );
    };

    const confirmCancel = () => {
        if (!cancelTarget) return;
        cancelEmail.mutate(cancelTarget.id, {
            onSuccess: () => {
                toast(t.queue.removedOk);
                setCancelTarget(null);
            },
            onError: (err) => {
                toast(errorMessage(err), 'error');
                setCancelTarget(null);
            },
        });
    };

    const handleSendNow = (email: OutreachEmail) => {
        sendNow.mutate(email.id, {
            onSuccess: () => toast('جاري الإرسال الآن...'),
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    };

    const handleAddManual = (e: React.FormEvent) => {
        e.preventDefault();
        if (!manualEmail.trim()) return;
        addManual.mutate(manualEmail, {
            onSuccess: (data) => {
                if (data.stats?.queued > 0) {
                    toast('تمت الإضافة للطابور بنجاح.');
                    setManualEmail('');
                } else if (data.stats?.duplicates > 0) {
                    toast('الإيميل موجود بالفعل.', 'error');
                } else {
                    toast('لم يتم الإضافة للطابور. تأكد من صحة الإيميل.', 'error');
                }
            },
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    };

    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
            <form onSubmit={handleAddManual} className="flex gap-2 border-b border-slate-200 bg-slate-50 p-3">
                <input
                    type="email"
                    required
                    placeholder="إضافة إيميل يدوياً..."
                    className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 flex-1 min-w-0"
                    value={manualEmail}
                    onChange={(e) => setManualEmail(e.target.value)}
                    dir="ltr"
                />
                <Button type="submit" size="sm" loading={addManual.isPending} variant="secondary">
                    <IconPlus className="size-4" />
                    إضافة
                </Button>
            </form>

            {sendingItems.map((email) => (
                <div
                    key={email.id}
                    className="flex items-center gap-3 border-b border-blue-100 bg-blue-50/70 px-4 py-3"
                >
                    <span className="size-2 shrink-0 animate-pulse rounded-full bg-blue-500" />
                    <QueueRowContent email={email} />
                    <Badge color="indigo">{t.queue.sendingNow}</Badge>
                </div>
            ))}

            {isLoading && !queueData ? (
                <div className="space-y-3 p-4">
                    {Array.from({ length: 5 }).map((_, i) => (
                        <Skeleton key={i} className="h-12 w-full" />
                    ))}
                </div>
            ) : items.length === 0 && sendingItems.length === 0 ? (
                <EmptyState
                    title={t.queue.empty}
                    description={t.queue.emptyDesc}
                    icon={<IconQueue className="size-6" />}
                />
            ) : (
                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                    <SortableContext items={items.map((i) => i.id)} strategy={verticalListSortingStrategy}>
                        <ul>
                            {items.map((email) => (
                                <SortableQueueRow 
                                    key={email.id} 
                                    email={email} 
                                    onCancel={setCancelTarget} 
                                    onSendNow={handleSendNow} 
                                    isSendingNow={sendNow.isPending && sendNow.variables === email.id} 
                                />
                            ))}
                        </ul>
                    </SortableContext>
                </DndContext>
            )}

            <ConfirmDialog
                open={cancelTarget !== null}
                title={t.queue.removeTitle}
                message={cancelTarget ? t.queue.removeConfirm(cancelTarget.to_email) : ''}
                danger
                loading={cancelEmail.isPending}
                onConfirm={confirmCancel}
                onClose={() => setCancelTarget(null)}
            />
        </div>
    );
}

function FailedTab() {
    const [page, setPage] = useState(1);
    const { data: result, isLoading } = useEmails({ status: 'failed', page });
    const requeue = useRequeueEmail();
    const { toast } = useToast();

    const columns: Column<OutreachEmail>[] = [
        {
            key: 'recipient',
            header: t.queue.columns.recipient,
            render: (email) => (
                <div>
                    <p className="font-semibold text-slate-700" dir="ltr">
                        {email.to_email}
                    </p>
                    <p className="text-xs text-slate-500">
                        {t.queue.attempts}: {email.attempts}
                    </p>
                </div>
            ),
        },
        {
            key: 'company',
            header: t.queue.columns.company,
            render: (email) => <span className="text-slate-700">{email.company.name}</span>,
        },
        {
            key: 'job',
            header: t.queue.columns.job,
            render: (email) => <span className="text-slate-500">{email.job_post?.title ?? '—'}</span>,
        },
        {
            key: 'error',
            header: t.queue.columns.error,
            render: (email) => (
                <span className="block max-w-64 text-xs leading-snug text-red-600" dir="ltr">
                    {email.error ?? '—'}
                </span>
            ),
        },
        {
            key: 'date',
            header: t.queue.columns.date,
            render: (email) => (
                <span className="whitespace-nowrap text-slate-500">{formatDateTime(email.queued_at)}</span>
            ),
        },
        {
            key: 'actions',
            header: t.queue.columns.actions,
            render: (email) => (
                <Button
                    size="sm"
                    variant="secondary"
                    loading={requeue.isPending && requeue.variables === email.id}
                    onClick={() =>
                        requeue.mutate(email.id, {
                            onSuccess: () => toast(t.queue.requeuedOk),
                            onError: (err) => toast(errorMessage(err), 'error'),
                        })
                    }
                >
                    <IconRefresh className="size-3.5" />
                    {t.queue.requeue}
                </Button>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            result={result}
            isLoading={isLoading}
            page={page}
            onPageChange={setPage}
            rowKey={(email) => email.id}
            emptyTitle={t.queue.failedEmpty}
        />
    );
}

export function QueuePage() {
    const [tab, setTab] = useState('queue');

    return (
        <div>
            <PageHeader title={t.queue.title} description={t.queue.subtitle} />
            <QueueHeaderStrip />
            <Tabs
                className="mb-4"
                value={tab}
                onChange={setTab}
                items={[
                    { key: 'queue', label: t.queue.tabs.queue },
                    { key: 'failed', label: t.queue.tabs.failed },
                ]}
            />
            {tab === 'queue' ? <QueueTab /> : <FailedTab />}
        </div>
    );
}
