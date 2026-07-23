import { useId } from 'react';
import type { InputHTMLAttributes, Ref } from 'react';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
    hint?: string;
    ref?: Ref<HTMLInputElement>;
}

export function Input({ label, error, hint, className = '', id, ...rest }: InputProps) {
    const autoId = useId();
    const inputId = id ?? autoId;
    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className="mb-1.5 block text-xs font-semibold text-slate-600">
                    {label}
                </label>
            )}
            <input
                id={inputId}
                className={`w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-2 focus:outline-indigo-500 disabled:bg-slate-50 disabled:text-slate-400 ${
                    error ? 'border-red-400' : 'border-slate-300'
                }`}
                {...rest}
            />
            {hint && !error && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}
