import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import type {
    AutoScrapeSettings,
    CvInfo,
    ImportMode,
    ProfileSettings,
    ScheduleSettings,
    SettingsResponse,
    SmtpEncryption,
    TemplateSettings,
} from '@/api/types';

export function useSettings() {
    return useQuery({
        queryKey: ['settings'],
        queryFn: async () => (await api.get<SettingsResponse>('/api/settings')).data,
    });
}

function useInvalidateSettings() {
    const qc = useQueryClient();
    return () => {
        void qc.invalidateQueries({ queryKey: ['settings'] });
        void qc.invalidateQueries({ queryKey: ['dashboard'] });
    };
}

export interface ApifyPayload {
    token?: string;
    enrich_actor_id?: string;
}

export function useSaveApify() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (payload: ApifyPayload) =>
            (await api.put<{ ok: true; account_name?: string }>('/api/settings/apify', payload)).data,
        onSuccess: invalidate,
    });
}

export interface SmtpPayload {
    host: string;
    port: number;
    encryption: SmtpEncryption;
    username: string;
    password?: string;
    from_email: string;
    from_name: string;
}

export function useSaveSmtp() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (payload: SmtpPayload) =>
            (await api.put<{ ok: true }>('/api/settings/smtp', payload)).data,
        onSuccess: invalidate,
    });
}

export function useSmtpTest() {
    return useMutation({
        mutationFn: async (to: string) =>
            (await api.post<{ ok: true }>('/api/settings/smtp-test', { to }, { timeout: 40_000 }))
                .data,
    });
}

export function useSaveTemplate() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (payload: TemplateSettings) =>
            (await api.put<{ ok: true }>('/api/settings/template', payload)).data,
        onSuccess: invalidate,
    });
}

export function useTemplatePreview() {
    return useMutation({
        mutationFn: async (payload: TemplateSettings) =>
            (await api.post<TemplateSettings>('/api/settings/template-preview', payload)).data,
    });
}

export function useSaveSchedule() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (payload: ScheduleSettings) =>
            (await api.put<{ ok: true }>('/api/settings/schedule', payload)).data,
        onSuccess: invalidate,
    });
}

export function useSaveProfile() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (payload: ProfileSettings) =>
            (await api.put<{ ok: true }>('/api/settings/profile', payload)).data,
        onSuccess: invalidate,
    });
}

export interface AutoScrapePayload {
    enabled: boolean;
    time: string;
    keywords: string;
    location: string;
    random_sources: boolean;
    auto_queue: boolean;
    import_mode: ImportMode;
}

export function useUpdateAutoScrape() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (payload: AutoScrapePayload) =>
            (
                await api.put<{ ok: true; auto_scrape: AutoScrapeSettings }>(
                    '/api/settings/auto-scrape',
                    payload,
                )
            ).data,
        onSuccess: invalidate,
    });
}

export function useUploadCv() {
    const invalidate = useInvalidateSettings();
    return useMutation({
        mutationFn: async (file: File) => {
            const form = new FormData();
            form.append('file', file);
            return (await api.post<{ cv: CvInfo }>('/api/settings/cv', form)).data.cv;
        },
        onSuccess: invalidate,
    });
}
