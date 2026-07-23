import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/Skeleton';

interface StatCardProps {
    label: string;
    value: ReactNode;
    icon?: ReactNode;
    sub?: ReactNode;
    loading?: boolean;
}

export function StatCard({ label, value, icon, sub, loading = false }: StatCardProps) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-slate-500">{label}</p>
                    {loading ? (
                        <Skeleton className="mt-2 h-7 w-16" />
                    ) : (
                        <p className="mt-1 text-2xl font-bold text-slate-800 tabular-nums">{value}</p>
                    )}
                    {sub && <p className="mt-0.5 text-xs text-slate-400">{sub}</p>}
                </div>
                {icon && (
                    <div className="rounded-lg bg-indigo-50 p-2.5 text-indigo-600 [&>svg]:size-5">{icon}</div>
                )}
            </div>
        </div>
    );
}
