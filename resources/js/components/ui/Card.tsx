import type { ReactNode } from 'react';

interface CardProps {
    title?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
}

export function Card({ title, actions, children, className = '', bodyClassName = 'p-5' }: CardProps) {
    return (
        <div className={`rounded-xl border border-slate-200 bg-white shadow-xs ${className}`}>
            {(title !== undefined || actions !== undefined) && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
                    <h3 className="text-sm font-bold text-slate-800">{title}</h3>
                    {actions}
                </div>
            )}
            <div className={bodyClassName}>{children}</div>
        </div>
    );
}
