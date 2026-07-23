import { useEffect, useState } from 'react';

interface CountdownTimerProps {
    to: string;
    className?: string;
}

/** عدّاد تنازلي حتى timestamp — بصيغة mm:ss (ومع الساعات لو زادت). */
export function CountdownTimer({ to, className = '' }: CountdownTimerProps) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(interval);
    }, []);

    const target = new Date(to).getTime();
    const diff = Number.isNaN(target) ? 0 : Math.max(0, Math.floor((target - now) / 1000));
    const hours = Math.floor(diff / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return (
        <span dir="ltr" className={`font-mono font-semibold tabular-nums ${className}`}>
            {hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${pad(minutes)}:${pad(seconds)}`}
        </span>
    );
}
