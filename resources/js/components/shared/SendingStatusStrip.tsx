import { useDashboard, usePauseSending, useResumeSending } from '@/api/queries/dashboard';
import { CountdownTimer } from '@/components/shared/CountdownTimer';
import { Skeleton } from '@/components/ui/Skeleton';
import { IconPause, IconPlay } from '@/components/ui/icons';
import { t } from '@/lib/i18n';

/** شريط حالة الإرسال في أعلى الصفحة — نقطة + عدّاد + الحصة + إيقاف/استئناف. */
export function SendingStatusStrip() {
    const { data, isLoading } = useDashboard();
    const pause = usePauseSending();
    const resume = useResumeSending();

    if (isLoading || !data) {
        return <Skeleton className="h-6 w-64" />;
    }

    const { sending, stats } = data;

    let dotClass = 'bg-emerald-500 animate-pulse';
    let label: string = t.overview.sending.active;
    if (!sending.enabled) {
        dotClass = 'bg-red-500';
        label = t.overview.sending.paused;
    } else if (sending.cap_reached) {
        dotClass = 'bg-amber-500';
        label = t.overview.sending.capReached;
    } else if (!sending.in_window) {
        dotClass = 'bg-slate-400';
        label = t.overview.sending.outsideWindow;
    }

    const busy = pause.isPending || resume.isPending;

    return (
        <div className="flex min-w-0 items-center gap-3 text-xs font-semibold text-slate-600">
            <span className="flex items-center gap-1.5">
                <span className={`size-2 shrink-0 rounded-full ${dotClass}`} />
                <span className="whitespace-nowrap">{label}</span>
            </span>

            {sending.enabled && sending.in_window && !sending.cap_reached && sending.next_send_at && (
                <span className="hidden items-center gap-1 whitespace-nowrap sm:flex">
                    <span className="text-slate-400">{t.overview.sending.nextIn}</span>
                    <CountdownTimer to={sending.next_send_at} className="text-indigo-600" />
                </span>
            )}

            <span className="hidden whitespace-nowrap text-slate-400 md:inline">
                {t.overview.stats.sentToday}{' '}
                <span dir="ltr" className="font-bold text-slate-600 tabular-nums">
                    {stats.sent_today}/{stats.daily_cap}
                </span>
            </span>

            <button
                type="button"
                disabled={busy}
                onClick={() => (sending.enabled ? pause.mutate() : resume.mutate())}
                className={`flex items-center gap-1 rounded-md px-2 py-1 transition-colors disabled:opacity-50 ${
                    sending.enabled
                        ? 'bg-red-50 text-red-600 hover:bg-red-100'
                        : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                }`}
            >
                {sending.enabled ? <IconPause className="size-3" /> : <IconPlay className="size-3" />}
                {sending.enabled ? t.overview.sending.pause : t.overview.sending.resume}
            </button>
        </div>
    );
}
