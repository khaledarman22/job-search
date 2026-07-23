import type { ReactNode } from 'react';
import { IconInbox } from '@/components/ui/icons';

interface EmptyStateProps {
    title: string;
    description?: string;
    icon?: ReactNode;
    action?: ReactNode;
}

export function EmptyState({ title, description, icon, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                {icon ?? <IconInbox className="size-6" />}
            </div>
            <p className="font-bold text-slate-700">{title}</p>
            {description && <p className="max-w-sm text-sm text-slate-500">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
