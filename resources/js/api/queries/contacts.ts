import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, cleanParams } from '@/api/client';
import type { Contact, ContactUpdatePayload, ImportEmailsStats, OutreachEmail, Paginated } from '@/api/types';

export interface ContactFilters {
    status?: string;
    phone_only?: string;
    sent_before?: string;
    q?: string;
    page: number;
}

export function useContacts(filters: ContactFilters) {
    return useQuery({
        queryKey: ['contacts', filters],
        queryFn: async () =>
            (
                await api.get<Paginated<Contact>>('/api/contacts', {
                    params: cleanParams({ ...filters }),
                })
            ).data,
        placeholderData: keepPreviousData,
    });
}

function useInvalidateContacts() {
    const qc = useQueryClient();
    return () => {
        void qc.invalidateQueries({ queryKey: ['contacts'] });
        void qc.invalidateQueries({ queryKey: ['queue'] });
        void qc.invalidateQueries({ queryKey: ['emails'] });
        void qc.invalidateQueries({ queryKey: ['dashboard'] });
    };
}

export function useUpdateContact() {
    const invalidate = useInvalidateContacts();
    return useMutation({
        mutationFn: async ({ id, data }: { id: number; data: ContactUpdatePayload }) =>
            (await api.patch<{ contact: Contact }>(`/api/contacts/${id}`, data)).data.contact,
        onSuccess: invalidate,
    });
}

/** إدراج/إعادة إدراج يدوي في الطابور. */
export function useQueueContact() {
    const invalidate = useInvalidateContacts();
    return useMutation({
        mutationFn: async ({ id, job_post_id }: { id: number; job_post_id?: number }) =>
            (
                await api.post<{ email: OutreachEmail }>(
                    `/api/contacts/${id}/queue`,
                    job_post_id ? { job_post_id } : {},
                )
            ).data.email,
        onSuccess: invalidate,
    });
}

export function useSuppressContact() {
    const invalidate = useInvalidateContacts();
    return useMutation({
        mutationFn: async ({ id, suppress }: { id: number; suppress: boolean }) =>
            (
                await api.post<{ contact: Contact }>(
                    `/api/contacts/${id}/${suppress ? 'suppress' : 'unsuppress'}`,
                )
            ).data.contact,
        onSuccess: invalidate,
    });
}

/** استيراد قائمة إيميلات (لصق نص و/أو ملف txt/csv) كجهات اتصال. */
export function useImportEmails() {
    const invalidate = useInvalidateContacts();
    return useMutation({
        mutationFn: async ({
            emails,
            file,
            queue,
        }: {
            emails: string;
            file: File | null;
            queue: boolean;
        }) => {
            const form = new FormData();
            if (emails) form.append('emails', emails);
            if (file) form.append('file', file);
            form.append('queue', queue ? '1' : '0');
            return (await api.post<{ ok: true; stats: ImportEmailsStats }>('/api/contacts/import', form))
                .data.stats;
        },
        onSuccess: invalidate,
    });
}
