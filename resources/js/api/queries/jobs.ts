import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, cleanParams } from '@/api/client';
import type { JobPost, JobPostDetail, OutreachEmail, Paginated } from '@/api/types';

export interface JobFilters {
    status?: string;
    source_id?: string;
    has_contact?: string;
    q?: string;
    page: number;
}

export function useJobs(filters: JobFilters) {
    return useQuery({
        queryKey: ['jobs', filters],
        queryFn: async () =>
            (
                await api.get<Paginated<JobPost>>('/api/job-posts', {
                    params: cleanParams({ ...filters }),
                })
            ).data,
        placeholderData: keepPreviousData,
    });
}

export function useJob(id: number) {
    return useQuery({
        queryKey: ['job', id],
        queryFn: async () => (await api.get<{ job: JobPostDetail }>(`/api/job-posts/${id}`)).data.job,
        enabled: Number.isFinite(id),
    });
}

/** إضافة أفضل جهة اتصال للوظيفة إلى الطابور. */
export function useQueueJob() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (jobId: number) =>
            (await api.post<{ email: OutreachEmail }>(`/api/job-posts/${jobId}/queue`)).data.email,
        onSuccess: (_email, jobId) => {
            void qc.invalidateQueries({ queryKey: ['queue'] });
            void qc.invalidateQueries({ queryKey: ['emails'] });
            void qc.invalidateQueries({ queryKey: ['dashboard'] });
            void qc.invalidateQueries({ queryKey: ['job', jobId] });
            void qc.invalidateQueries({ queryKey: ['contacts'] });
        },
    });
}

/** إعادة ضبط إثراء الشركة. */
export function useEnrichCompany() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (companyId: number) =>
            (await api.post<{ company: unknown }>(`/api/companies/${companyId}/enrich`)).data,
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['job'] });
            void qc.invalidateQueries({ queryKey: ['jobs'] });
        },
    });
}
