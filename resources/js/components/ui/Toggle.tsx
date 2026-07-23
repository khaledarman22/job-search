interface ToggleProps {
    checked: boolean;
    onChange: (value: boolean) => void;
    label?: string;
    disabled?: boolean;
    size?: 'md' | 'lg';
}

export function Toggle({ checked, onChange, label, disabled = false, size = 'md' }: ToggleProps) {
    const track = size === 'lg' ? 'h-7 w-12' : 'h-5 w-9';
    const knob = size === 'lg' ? 'size-6' : 'size-4';
    // في RTL المقبض يبدأ يمينًا ويتحرك يسارًا (سالب X) عند التفعيل.
    const travel = size === 'lg' ? '-translate-x-5' : '-translate-x-4';

    return (
        <label
            className={`inline-flex items-center gap-2 select-none ${
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'
            }`}
        >
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative inline-flex shrink-0 items-center rounded-full transition-colors ${track} ${
                    checked ? 'bg-indigo-600' : 'bg-slate-300'
                }`}
            >
                <span
                    className={`absolute start-0.5 inline-block rounded-full bg-white shadow transition-transform ${knob} ${
                        checked ? travel : 'translate-x-0'
                    }`}
                />
            </button>
            {label && <span className="text-sm font-medium text-slate-700">{label}</span>}
        </label>
    );
}
