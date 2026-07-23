import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { DashboardResponse, SendingState } from '@/api/types';

/** بيانات لوحة المتابعة — تُحدَّث كل 30 ثانية. */
export function useDashboard() {
    return useQuery({
        queryKey: ['dashboard'],
        queryFn: async () => (await api.get<DashboardResponse>('/api/dashboard')).data,
        refetchInterval: 30_000,
    });
}

function useSendingMutation(action: 'pause' | 'resume') {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async () =>
            (await api.post<{ sending: SendingState }>(`/api/queue/${action}`)).data.sending,
        onSuccess: (sending) => {
            qc.setQueryData<DashboardResponse>(['dashboard'], (old) =>
                old ? { ...old, sending } : old,
            );
            void qc.invalidateQueries({ queryKey: ['dashboard'] });
        },
    });
}

export function usePauseSending() {
    return useSendingMutation('pause');
}

export function useResumeSending() {
    return useSendingMutation('resume');
}
