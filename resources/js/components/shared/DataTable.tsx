import type { ReactNode } from 'react';
import type { Paginated } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { t } from '@/lib/i18n';

export interface Column<T> {
    key: string;
    header: ReactNode;
    render: (row: T) => ReactNode;
    className?: string;
}

interface DataTableProps<T> {
    columns: Column<T>[];
    result: Paginated<T> | undefined;
    isLoading: boolean;
    page: number;
    onPageChange: (page: number) => void;
    rowKey: (row: T) => number | string;
    emptyTitle: string;
    emptyDescription?: string;
    emptyIcon?: ReactNode;
}

/** جدول عام: أعمدة معرَّفة + تصفيح خادم + سكيلتون + حالة فارغة. */
export function DataTable<T>({
    columns,
    result,
    isLoading,
    page,
    onPageChange,
    rowKey,
    emptyTitle,
    emptyDescription,
    emptyIcon,
}: DataTableProps<T>) {
    const showSkeleton = isLoading && !result;

    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 bg-slate-50">
                            {columns.map((col) => (
                                <th
                                    key={col.key}
                                    className={`px-4 py-3 text-start text-xs font-bold whitespace-nowrap text-slate-500 ${col.className ?? ''}`}
                                >
                                    {col.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {showSkeleton ? (
                            Array.from({ length: 6 }).map((_, i) => (
                                <tr key={i} className="border-b border-slate-100">
                                    {columns.map((col) => (
                                        <td key={col.key} className="px-4 py-3.5">
                                            <Skeleton className="h-4 w-full max-w-28" />
                                        </td>
                                    ))}
                                </tr>
                            ))
                        ) : result && result.data.length > 0 ? (
                            result.data.map((row) => (
                                <tr
                                    key={rowKey(row)}
                                    className="border-b border-slate-100 transition-colors last:border-0 hover:bg-slate-50/60"
                                >
                                    {columns.map((col) => (
                                        <td key={col.key} className={`px-4 py-3 align-middle ${col.className ?? ''}`}>
                                            {col.render(row)}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={columns.length}>
                                    <EmptyState title={emptyTitle} description={emptyDescription} icon={emptyIcon} />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {result && result.last_page > 1 && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-2.5">
                    <p className="text-xs text-slate-500">
                        {t.common.pageOf(result.current_page, result.last_page)} —{' '}
                        {t.common.totalCount(result.total)}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={page <= 1 || isLoading}
                            onClick={() => onPageChange(page - 1)}
                        >
                            {t.common.previous}
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={page >= result.last_page || isLoading}
                            onClick={() => onPageChange(page + 1)}
                        >
                            {t.common.next}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
