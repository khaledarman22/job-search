import type { ReactNode } from 'react';

export function FilterBar({ children }: { children: ReactNode }) {
    return (
        <div className="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3.5 shadow-xs">
            {children}
        </div>
    );
}
