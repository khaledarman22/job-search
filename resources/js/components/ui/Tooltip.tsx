import type { ReactNode } from 'react';

interface TooltipProps {
    content: ReactNode;
    children: ReactNode;
}

export function Tooltip({ content, children }: TooltipProps) {
    return (
        <span className="group/tt relative inline-flex">
            {children}
            <span className="pointer-events-none absolute bottom-full start-1/2 z-20 mb-1.5 hidden translate-x-1/2 rounded-md bg-slate-800 px-2 py-1 text-xs whitespace-nowrap text-white shadow-md group-hover/tt:block">
                {content}
            </span>
        </span>
    );
}
