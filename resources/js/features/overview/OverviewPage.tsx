import { useDashboard, usePauseSending, useResumeSending } from '@/api/queries/dashboard';
import type { ActivityItem, DashboardResponse } from '@/api/types';
import { CountdownTimer } from '@/components/shared/CountdownTimer';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatCard } from '@/components/shared/StatCard';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { Toggle } from '@/components/ui/Toggle';
import {
    IconAlert,
    IconBriefcase,
    IconCheck,
    IconClock,
    IconEye,
    IconMail,
    IconQueue,
    IconSearch,
    IconSend,
    IconUsers,
} from '@/components/ui/icons';
import { formatPercent, timeAgo } from '@/lib/format';
import { t } from '@/lib/i18n';

function activityIcon(type: ActivityItem['type']) {
    switch (type) {
        case 'sent':
            return <IconSend className="size-4 text-indigo-500" />;
        case 'opened':
            return <IconEye className="size-4 text-emerald-500" />;
        case 'failed':
            return <IconAlert className="size-4 text-red-500" />;
        case 'scraped':
            return <IconSearch className="size-4 text-blue-500" />;
        case 'contact_found':
            return <IconUsers className="size-4 text-teal-500" />;
        case 'run_finished':
            return <IconCheck className="size-4 text-green-500" />;
    }
}

function SendingPanel({ data }: { data: DashboardResponse }) {
    const pause = usePauseSending();
    const resume = useResumeSending();
    const { sending, stats, apify_spend: spend } = data;
    const busy = pause.isPending || resume.isPending;
    const spendPct = spend.cap > 0 ? Math.min(100, (spend.spent / spend.cap) * 100) : 0;

    const capPct = stats.daily_cap > 0 ? Math.min(100, (stats.sent_today / stats.daily_cap) * 100) : 0;

    let stateLabel: string;
    let stateClass: string;
    if (!sending.enabled) {
        stateLabel = t.overview.sending.paused;
        stateClass = 'bg-red-50 text-red-700 border-red-200';
    } else if (sending.cap_reached) {
        stateLabel = t.overview.sending.capReached;
        stateClass = 'bg-amber-50 text-amber-800 border-amber-200';
    } else if (!sending.in_window) {
        stateLabel = t.overview.sending.outsideWindow;
        stateClass = 'bg-slate-50 text-slate-600 border-slate-200';
    } else {
        stateLabel = t.overview.sending.waitingNext;
        stateClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
    }

    const windowDays = sending.window.days
        .map((d) => t.days[d] ?? String(d))
        .join('، ');

    const warningList: (string | null)[] = [
        sending.smtp_configured ? null : t.overview.sending.smtpMissing,
        sending.apify_configured ? null : t.overview.sending.apifyMissing,
        sending.template_configured ? null : t.overview.sending.templateMissing,
    ];
    const configWarnings = warningList.filter((w): w is string => w !== null);

    return (
        <Card title={t.overview.sending.title} className="h-full" bodyClassName="p-5 space-y-4">
            <div className="flex items-center justify-between gap-3">
                <Toggle
                    size="lg"
                    checked={sending.enabled}
                    disabled={busy}
                    onChange={(next) => (next ? resume.mutate() : pause.mutate())}
                    label={sending.enabled ? t.overview.sending.active : t.overview.sending.paused}
                />
                <span className={`rounded-full border px-3 py-1 text-xs font-bold ${stateClass}`}>
                    {stateLabel}
                </span>
            </div>

            {sending.paused_reason && (
                <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-medium text-red-700">
                    <IconAlert className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {t.overview.sending.pausedReason}: {sending.paused_reason}
                    </span>
                </div>
            )}

            {sending.enabled &&
                sending.in_window &&
                !sending.cap_reached &&
                sending.next_send_at && (
                    <div className="flex items-center gap-2 rounded-lg bg-indigo-50 px-3.5 py-3 text-indigo-700">
                        <IconClock className="size-4.5" />
                        <span className="text-sm font-semibold">{t.overview.sending.nextIn}</span>
                        <CountdownTimer to={sending.next_send_at} className="text-lg" />
                    </div>
                )}

            <div className="space-y-1 text-sm text-slate-600">
                <p>
                    <span className="font-semibold">{t.overview.sending.window}:</span>{' '}
                    <span dir="ltr" className="tabular-nums">
                        {sending.window.start}–{sending.window.end}
                    </span>{' '}
                    ({sending.window.timezone})
                </p>
                <p className="text-xs text-slate-500">{windowDays}</p>
            </div>

            <div>
                <div className="mb-1 flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>{t.overview.sending.capProgress}</span>
                    <span dir="ltr" className="tabular-nums">
                        {stats.sent_today}/{stats.daily_cap}
                    </span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div
                        className={`h-full rounded-full transition-all ${
                            sending.cap_reached ? 'bg-amber-500' : 'bg-indigo-500'
                        }`}
                        style={{ width: `${capPct}%` }}
                    />
                </div>
            </div>

            <div>
                <div className="mb-1 flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>{t.overview.spend.title}</span>
                    <span dir="ltr" className="tabular-nums">
                        ${spend.spent.toFixed(2)}/${spend.cap.toFixed(2)}
                    </span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div
                        className={`h-full rounded-full transition-all ${
                            spend.cap_reached ? 'bg-red-500' : spendPct > 75 ? 'bg-amber-500' : 'bg-emerald-500'
                        }`}
                        style={{ width: `${spendPct}%` }}
                    />
                </div>
            </div>

            {configWarnings.length > 0 && (
                <div className="space-y-1.5">
                    {configWarnings.map((warning) => (
                        <div
                            key={warning}
                            className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800"
                        >
                            <IconAlert className="size-3.5 shrink-0" />
                            {warning}
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}

function ActivityFeed({ items }: { items: ActivityItem[] }) {
    return (
        <Card title={t.overview.activity.title} className="h-full" bodyClassName="p-0">
            {items.length === 0 ? (
                <EmptyState title={t.overview.activity.empty} />
            ) : (
                <ul className="divide-y divide-slate-100">
                    {items.map((item, i) => (
                        <li key={`${item.at}-${i}`} className="flex items-start gap-3 px-5 py-3">
                            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-50">
                                {activityIcon(item.type)}
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm leading-snug text-slate-700">{item.message}</p>
                                <p className="mt-0.5 text-xs text-slate-400">{timeAgo(item.at)}</p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function SpendAlert({ spend }: { spend: DashboardResponse['apify_spend'] }) {
    if (!spend?.paused) {
        return null;
    }
    return (
        <div className="mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3.5 text-red-800">
            <IconAlert className="mt-0.5 size-5 shrink-0" />
            <div>
                <p className="font-bold">{t.overview.spend.pausedTitle}</p>
                <p className="mt-0.5 text-sm">
                    {spend.paused_reason ?? t.overview.spend.pausedHint}
                </p>
                <p className="mt-1 text-xs font-semibold" dir="ltr">
                    {t.overview.spend.spentOf(spend.spent.toFixed(2), spend.cap.toFixed(2))}
                </p>
            </div>
        </div>
    );
}

export function OverviewPage() {
    const { data, isLoading } = useDashboard();
    const stats = data?.stats;

    return (
        <div>
            <PageHeader title={t.overview.title} description={t.overview.subtitle} />

            {data && <SpendAlert spend={data.apify_spend} />}

            <div className="mb-5 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <StatCard
                    label={t.overview.stats.jobs}
                    value={stats?.jobs_total ?? 0}
                    icon={<IconBriefcase />}
                    loading={isLoading}
                />
                <StatCard
                    label={t.overview.stats.contacts}
                    value={stats?.contacts_total ?? 0}
                    icon={<IconUsers />}
                    loading={isLoading}
                />
                <StatCard
                    label={t.overview.stats.queued}
                    value={stats?.queued ?? 0}
                    icon={<IconQueue />}
                    loading={isLoading}
                />
                <StatCard
                    label={t.overview.stats.sentToday}
                    value={
                        stats ? (
                            <span dir="ltr">
                                {stats.sent_today}/{stats.daily_cap}
                            </span>
                        ) : (
                            0
                        )
                    }
                    icon={<IconSend />}
                    loading={isLoading}
                />
                <StatCard
                    label={t.overview.stats.sentTotal}
                    value={stats?.sent_total ?? 0}
                    icon={<IconMail />}
                    loading={isLoading}
                />
                <StatCard
                    label={t.overview.stats.openRate}
                    value={stats ? formatPercent(stats.open_rate) : '—'}
                    icon={<IconEye />}
                    loading={isLoading}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {data ? (
                    <SendingPanel data={data} />
                ) : (
                    <Card title={t.overview.sending.title}>
                        <div className="space-y-3">
                            <Skeleton className="h-8 w-40" />
                            <Skeleton className="h-12 w-full" />
                            <Skeleton className="h-2 w-full" />
                        </div>
                    </Card>
                )}
                {data ? (
                    <ActivityFeed items={data.recent_activity} />
                ) : (
                    <Card title={t.overview.activity.title}>
                        <div className="space-y-3">
                            {Array.from({ length: 4 }).map((_, i) => (
                                <Skeleton key={i} className="h-10 w-full" />
                            ))}
                        </div>
                    </Card>
                )}
            </div>
        </div>
    );
}
