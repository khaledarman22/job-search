import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useMe } from '@/api/queries/auth';
import { Spinner } from '@/components/ui/Spinner';
import { t } from '@/lib/i18n';

/** يتحقق من الجلسة عبر /api/me — غير المسجَّل يُوجَّه لصفحة الدخول. */
export function AuthGuard({ children }: { children: ReactNode }) {
    const { data: user, isLoading, isError } = useMe();

    if (isLoading) {
        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-3 text-slate-500">
                <Spinner className="size-8 text-indigo-600" />
                <p className="text-sm font-semibold">{t.common.loading}</p>
            </div>
        );
    }

    if (isError || !user) {
        return <Navigate to="/login" replace />;
    }

    return <>{children}</>;
}
