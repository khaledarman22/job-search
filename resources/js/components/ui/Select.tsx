import { useId } from 'react';
import type { Ref, SelectHTMLAttributes } from 'react';

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
    label?: string;
    error?: string;
    ref?: Ref<HTMLSelectElement>;
}

export function Select({ label, error, className = '', id, children, ...rest }: SelectProps) {
    const autoId = useId();
    const selectId = id ?? autoId;
    return (
        <div className={className}>
            {label && (
                <label htmlFor={selectId} className="mb-1.5 block text-xs font-semibold text-slate-600">
                    {label}
                </label>
            )}
            <select
                id={selectId}
                className={`w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-800 focus:outline-2 focus:outline-indigo-500 ${
                    error ? 'border-red-400' : 'border-slate-300'
                }`}
                {...rest}
            >
                {children}
            </select>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}
