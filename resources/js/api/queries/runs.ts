import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, cleanParams } from '@/api/client';
import type {
    ApifyRun,
    Paginated,
    RunSourcePayload,
    Source,
    SourcePayload,
    SourceTestResult,
} from '@/api/types';

// ---------- Sources ----------

export function useSources() {
    return useQuery({
        queryKey: ['sources'],
        queryFn: async () => (await api.get<{ data: Source[] }>('/api/sources')).data.data,
    });
}

function useInvalidateSources() {
    const qc = useQueryClient();
    return () => {
        void qc.invalidateQueries({ queryKey: ['sources'] });
    };
}

export function useCreateSource() {
    const invalidate = useInvalidateSources();
    return useMutation({
        mutationFn: async (payload: SourcePayload) =>
            (await api.post<{ source: Source }>('/api/sources', payload)).data,
        onSuccess: invalidate,
    });
}

export function useUpdateSource() {
    const invalidate = useInvalidateSources();
    return useMutation({
        mutationFn: async ({ id, data }: { id: number; data: SourcePayload }) =>
            (await api.put<{ source: Source }>(`/api/sources/${id}`, data)).data,
        onSuccess: invalidate,
    });
}

export function useDeleteSource() {
    const invalidate = useInvalidateSources();
    return useMutation({
        mutationFn: async (id: number) => {
            await api.delete(`/api/sources/${id}`);
        },
        onSuccess: invalidate,
    });
}

/** تشغيل الأكتور فورًا. */
export function useRunSource() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, body }: { id: number; body: RunSourcePayload }) =>
            (await api.post<{ run: ApifyRun }>(`/api/sources/${id}/run`, body)).data.run,
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['runs'] });
            void qc.invalidateQueries({ queryKey: ['sources'] });
        },
    });
}

/** اختبار المصدر — قد يستغرق حتى 60 ثانية. */
export function useTestSource() {
    return useMutation({
        mutationFn: async ({
            id,
            body,
        }: {
            id: number;
            body: { keywords?: string; location?: string };
        }) =>
            (
                await api.post<SourceTestResult>(`/api/sources/${id}/test`, body, {
                    timeout: 70_000,
                })
            ).data,
    });
}

// ---------- Runs ----------

export interface RunFilters {
    purpose?: string;
    origin?: string;
    status?: string;
    page: number;
}

const ACTIVE_RUN_STATUSES = new Set(['ready', 'running']);

export function hasActiveRuns(result: Paginated<ApifyRun> | undefined): boolean {
    return !!result?.data.some((run) => ACTIVE_RUN_STATUSES.has(run.status.toLowerCase()));
}

/** قائمة التشغيلات — تُحدَّث كل 10 ثوانٍ طالما فيه تشغيل نشط. */
export function useRuns(filters: RunFilters) {
    return useQuery({
        queryKey: ['runs', filters],
        queryFn: async () =>
            (
                await api.get<Paginated<ApifyRun>>('/api/runs', {
                    params: cleanParams({ ...filters }),
                })
            ).data,
        placeholderData: keepPreviousData,
        refetchInterval: (query) => (hasActiveRuns(query.state.data) ? 10_000 : false),
    });
}

export function useImportRun() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ runId, sourceId }: { runId: number; sourceId: number }) =>
            (await api.post<{ imported: number }>(`/api/runs/${runId}/import`, { source_id: sourceId }))
                .data,
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['runs'] });
            void qc.invalidateQueries({ queryKey: ['jobs'] });
            void qc.invalidateQueries({ queryKey: ['dashboard'] });
        },
    });
}

export function useSyncRuns() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async () => (await api.post<{ synced: number }>('/api/runs/sync')).data,
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['runs'] });
        },
    });
}
