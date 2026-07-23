import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { User } from '@/api/types';

export function useMe() {
    return useQuery({
        queryKey: ['me'],
        queryFn: async () => {
            const { data } = await api.get<{ user: User }>('/api/me');
            return data.user;
        },
        retry: false,
        staleTime: 5 * 60 * 1000,
    });
}

export function useLogin() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: { email: string; password: string }) => {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.post<{ user: User }>('/api/login', payload);
            return data.user;
        },
        onSuccess: (user) => {
            qc.setQueryData(['me'], user);
        },
    });
}

export function useLogout() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async () => {
            await api.post('/api/logout');
        },
        onSuccess: () => {
            qc.clear();
            window.location.href = '/login';
        },
    });
}
