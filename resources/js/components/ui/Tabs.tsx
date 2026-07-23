import type { ReactNode } from 'react';

export interface TabItem {
    key: string;
    label: ReactNode;
}

interface TabsProps {
    items: TabItem[];
    value: string;
    onChange: (key: string) => void;
    className?: string;
}

export function Tabs({ items, value, onChange, className = '' }: TabsProps) {
    return (
        <div className={`flex gap-1 overflow-x-auto border-b border-slate-200 ${className}`} role="tablist">
            {items.map((item) => (
                <button
                    key={item.key}
                    type="button"
                    role="tab"
                    aria-selected={value === item.key}
                    onClick={() => onChange(item.key)}
                    className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap transition-colors ${
                        value === item.key
                            ? 'border-indigo-600 text-indigo-600'
                            : 'border-transparent text-slate-500 hover:text-slate-700'
                    }`}
                >
                    {item.label}
                </button>
            ))}
        </div>
    );
}
