import axios from 'axios';
import { t } from '@/lib/i18n';

export const api = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (
            axios.isAxiosError(error) &&
            error.response?.status === 401 &&
            window.location.pathname !== '/login'
        ) {
            window.location.href = '/login';
        }
        return Promise.reject(error);
    },
);

interface ApiErrorBody {
    message?: string;
    code?: string;
    errors?: Record<string, string[]>;
}

/** أول رسالة خطأ صالحة للعرض من استجابة الـ API. */
export function errorMessage(err: unknown): string {
    if (axios.isAxiosError(err)) {
        const body = err.response?.data as ApiErrorBody | undefined;
        if (body?.errors) {
            const first = Object.values(body.errors)[0];
            if (first && first.length > 0) return first[0];
        }
        if (body?.message) return body.message;
    }
    return t.common.error;
}

/** كود الخطأ المنطقي (مثل already_sent) إن وُجد. */
export function errorCode(err: unknown): string | undefined {
    if (axios.isAxiosError(err)) {
        return (err.response?.data as ApiErrorBody | undefined)?.code;
    }
    return undefined;
}

export function errorStatus(err: unknown): number | undefined {
    return axios.isAxiosError(err) ? err.response?.status : undefined;
}

/** أخطاء الحقول من استجابة 422. */
export function fieldErrors(err: unknown): Record<string, string> {
    const out: Record<string, string> = {};
    if (axios.isAxiosError(err)) {
        const body = err.response?.data as ApiErrorBody | undefined;
        if (body?.errors) {
            for (const [field, messages] of Object.entries(body.errors)) {
                if (messages.length > 0) out[field] = messages[0];
            }
        }
    }
    return out;
}

/** يحذف القيم الفارغة قبل إرسالها كـ query params. */
export function cleanParams(
    params: Record<string, string | number | boolean | undefined | null>,
): Record<string, string | number | boolean> {
    const out: Record<string, string | number | boolean> = {};
    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '') out[key] = value;
    }
    return out;
}
