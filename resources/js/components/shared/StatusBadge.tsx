import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';

interface BadgeInfo {
    cls: string;
    pulse?: boolean;
}

const COLORS: Record<string, BadgeInfo> = {
    queued: { cls: 'bg-amber-100 text-amber-800' },
    sending: { cls: 'bg-blue-100 text-blue-800', pulse: true },
    sent: { cls: 'bg-green-100 text-green-800' },
    opened: { cls: 'bg-emerald-100 text-emerald-800' },
    failed: { cls: 'bg-red-100 text-red-700' },
    cancelled: { cls: 'bg-slate-100 text-slate-600' },
    new: { cls: 'bg-slate-100 text-slate-700' },
    contacted: { cls: 'bg-green-100 text-green-800' },
    suppressed: { cls: 'bg-slate-200 text-slate-600' },
    invalid: { cls: 'bg-red-100 text-red-700' },
    pending: { cls: 'bg-amber-100 text-amber-800' },
    running: { cls: 'bg-blue-100 text-blue-800', pulse: true },
    enriched: { cls: 'bg-green-100 text-green-800' },
    enriching: { cls: 'bg-blue-100 text-blue-800', pulse: true },
    no_contact: { cls: 'bg-slate-100 text-slate-500' },
    no_website: { cls: 'bg-slate-100 text-slate-500' },
    ready: { cls: 'bg-amber-100 text-amber-800' },
    succeeded: { cls: 'bg-green-100 text-green-800' },
    aborted: { cls: 'bg-slate-100 text-slate-600' },
    aborting: { cls: 'bg-slate-100 text-slate-600', pulse: true },
    'timed-out': { cls: 'bg-red-100 text-red-700' },
    'timing-out': { cls: 'bg-red-100 text-red-700', pulse: true },
};

/** شارة حالة موحّدة لكل الـ enums — تقبل حالات Apify الكبيرة أيضًا. */
export function StatusBadge({ status }: { status: string }) {
    const lower = status.toLowerCase();
    const key = lower in COLORS ? lower : lower.replace(/_/g, '-');
    const info = COLORS[key] ?? { cls: 'bg-slate-100 text-slate-600' };
    const label = t.status[key] ?? t.status[lower] ?? status;
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${info.cls}`}
        >
            {info.pulse && <span className="size-1.5 animate-pulse rounded-full bg-current" />}
            {label}
        </span>
    );
}

type BadgeColor = 'slate' | 'amber' | 'emerald' | 'red' | 'indigo' | 'teal';

const BADGE_COLORS: Record<BadgeColor, string> = {
    slate: 'bg-slate-100 text-slate-600',
    amber: 'bg-amber-100 text-amber-800',
    emerald: 'bg-emerald-100 text-emerald-800',
    red: 'bg-red-100 text-red-700',
    indigo: 'bg-indigo-100 text-indigo-700',
    teal: 'bg-teal-100 text-teal-800',
};

/** شارة عامة صغيرة للعلامات المساعدة (بديل، إعادة يدوية…). */
export function Badge({ color = 'slate', children }: { color?: BadgeColor; children: ReactNode }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${BADGE_COLORS[color]}`}
        >
            {children}
        </span>
    );
}
