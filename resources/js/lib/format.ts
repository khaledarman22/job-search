// تنسيق التواريخ والأرقام بتوقيت القاهرة وأرقام لاتينية.

const TZ = 'Africa/Cairo';
const LOCALE = 'ar-EG-u-nu-latn';

const dateTimeFmt = new Intl.DateTimeFormat(LOCALE, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: TZ,
});

const dateFmt = new Intl.DateTimeFormat(LOCALE, {
    dateStyle: 'medium',
    timeZone: TZ,
});

const timeFmt = new Intl.DateTimeFormat(LOCALE, {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: TZ,
});

function parse(iso: string | null | undefined): Date | null {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d;
}

export function formatDateTime(iso: string | null | undefined): string {
    const d = parse(iso);
    return d ? dateTimeFmt.format(d) : '—';
}

export function formatDate(iso: string | null | undefined): string {
    const d = parse(iso);
    return d ? dateFmt.format(d) : '—';
}

export function formatTime(iso: string | null | undefined): string {
    const d = parse(iso);
    return d ? timeFmt.format(d) : '—';
}

/** صياغة عربية للعدد مع الوحدة (مفرد/مثنى/جمع). */
function arUnit(n: number, one: string, two: string, few: string, many: string): string {
    if (n === 1) return one;
    if (n === 2) return two;
    if (n >= 3 && n <= 10) return `${n} ${few}`;
    return `${n} ${many}`;
}

/** «منذ …» نسبيًا، وبعد 30 يومًا يعرض التاريخ. */
export function timeAgo(iso: string | null | undefined): string {
    const d = parse(iso);
    if (!d) return '—';
    const secs = Math.floor((Date.now() - d.getTime()) / 1000);
    if (secs < 45) return 'منذ لحظات';
    if (secs < 3600) {
        const m = Math.max(1, Math.floor(secs / 60));
        return `منذ ${arUnit(m, 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة')}`;
    }
    if (secs < 86400) {
        const h = Math.floor(secs / 3600);
        return `منذ ${arUnit(h, 'ساعة', 'ساعتين', 'ساعات', 'ساعة')}`;
    }
    if (secs < 30 * 86400) {
        const days = Math.floor(secs / 86400);
        return `منذ ${arUnit(days, 'يوم', 'يومين', 'أيام', 'يومًا')}`;
    }
    return dateFmt.format(d);
}

export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes == null) return '—';
    if (bytes < 1024) return `${bytes} بايت`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} ك.ب`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} م.ب`;
}

/** معدل الفتح كنسبة مئوية (القيمة قادمة من السيرفر 0–100). */
export function formatPercent(value: number | null | undefined): string {
    if (value == null) return '—';
    return `${Math.round(value * 10) / 10}%`;
}
