import { useId } from 'react';
import type { Ref, TextareaHTMLAttributes } from 'react';

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: string;
    error?: string;
    ref?: Ref<HTMLTextAreaElement>;
}

export function Textarea({ label, error, className = '', id, ...rest }: TextareaProps) {
    const autoId = useId();
    const areaId = id ?? autoId;
    return (
        <div className={className}>
            {label && (
                <label htmlFor={areaId} className="mb-1.5 block text-xs font-semibold text-slate-600">
                    {label}
                </label>
            )}
            <textarea
                id={areaId}
                className={`w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-2 focus:outline-indigo-500 ${
                    error ? 'border-red-400' : 'border-slate-300'
                }`}
                {...rest}
            />
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}
