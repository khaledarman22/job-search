import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, cleanParams } from '@/api/client';
import type { OutreachEmail, OutreachEmailDetail, Paginated } from '@/api/types';

export interface EmailFilters {
    status?: string;
    opened?: string;
    q?: string;
    page: number;
}

export function useEmails(filters: EmailFilters) {
    return useQuery({
        queryKey: ['emails', filters],
        queryFn: async () =>
            (
                await api.get<Paginated<OutreachEmail>>('/api/emails', {
                    params: cleanParams({ ...filters }),
                })
            ).data,
        placeholderData: keepPreviousData,
    });
}

/** الطابور الحالي (queued مرتب بالـ position) — يُحدَّث كل 5 ثوانٍ. */
export function useQueueList() {
    return useQuery({
        queryKey: ['queue'],
        queryFn: async () =>
            (await api.get<Paginated<OutreachEmail>>('/api/emails', { params: { status: 'queued' } }))
                .data,
        refetchInterval: 5000,
    });
}

/** الرسالة الجاري إرسالها الآن إن وُجدت — كل 5 ثوانٍ. */
export function useSendingNow() {
    return useQuery({
        queryKey: ['sending-now'],
        queryFn: async () =>
            (await api.get<Paginated<OutreachEmail>>('/api/emails', { params: { status: 'sending' } }))
                .data,
        refetchInterval: 5000,
    });
}

export function useEmail(id: number) {
    return useQuery({
        queryKey: ['email', id],
        queryFn: async () =>
            (await api.get<{ email: OutreachEmailDetail }>(`/api/emails/${id}`)).data.email,
        enabled: Number.isFinite(id),
    });
}

function useInvalidateEmails() {
    const qc = useQueryClient();
    return () => {
        void qc.invalidateQueries({ queryKey: ['queue'] });
        void qc.invalidateQueries({ queryKey: ['emails'] });
        void qc.invalidateQueries({ queryKey: ['dashboard'] });
    };
}

export function useCancelEmail() {
    const invalidate = useInvalidateEmails();
    return useMutation({
        mutationFn: async (id: number) =>
            (await api.post<{ email: OutreachEmail }>(`/api/emails/${id}/cancel`)).data.email,
        onSuccess: invalidate,
    });
}

export function useRequeueEmail() {
    const invalidate = useInvalidateEmails();
    return useMutation({
        mutationFn: async (id: number) =>
            (await api.post<{ email: OutreachEmail }>(`/api/emails/${id}/requeue`)).data.email,
        onSuccess: invalidate,
    });
}

export function useMarkReplied() {
    const invalidate = useInvalidateEmails();
    return useMutation({
        mutationFn: async ({ id, replied }: { id: number; replied: boolean }) =>
            (await api.patch<{ email: OutreachEmail }>(`/api/emails/${id}`, { replied })).data.email,
        onSuccess: invalidate,
    });
}

/** إعادة ترتيب الطابور — تحديث متفائل على كاش ['queue']. */
export function useReorderQueue() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (ordered_ids: number[]) => {
            await api.patch('/api/queue/reorder', { ordered_ids });
        },
        onMutate: async (orderedIds) => {
            await qc.cancelQueries({ queryKey: ['queue'] });
            const prev = qc.getQueryData<Paginated<OutreachEmail>>(['queue']);
            if (prev) {
                const byId = new Map(prev.data.map((e) => [e.id, e]));
                const next = orderedIds
                    .map((id) => byId.get(id))
                    .filter((e): e is OutreachEmail => e !== undefined);
                qc.setQueryData<Paginated<OutreachEmail>>(['queue'], { ...prev, data: next });
            }
            return { prev };
        },
        onError: (_err, _ids, ctx) => {
            if (ctx?.prev) qc.setQueryData(['queue'], ctx.prev);
        },
        onSettled: () => {
            void qc.invalidateQueries({ queryKey: ['queue'] });
        },
    });
}
