import { createContext, useCallback, useContext, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { IconAlert, IconCheck, IconMail } from '@/components/ui/icons';

type ToastVariant = 'success' | 'error' | 'info';

interface ToastItem {
    id: number;
    message: string;
    variant: ToastVariant;
}

interface ToastContextValue {
    toast: (message: string, variant?: ToastVariant) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const styles: Record<ToastVariant, string> = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-red-200 bg-red-50 text-red-800',
    info: 'border-indigo-200 bg-indigo-50 text-indigo-800',
};

function ToastIcon({ variant }: { variant: ToastVariant }) {
    if (variant === 'success') return <IconCheck className="size-4 shrink-0" />;
    if (variant === 'error') return <IconAlert className="size-4 shrink-0" />;
    return <IconMail className="size-4 shrink-0" />;
}

export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<ToastItem[]>([]);
    const idRef = useRef(0);

    const toast = useCallback((message: string, variant: ToastVariant = 'success') => {
        const id = ++idRef.current;
        setToasts((prev) => [...prev, { id, message, variant }]);
        setTimeout(() => {
            setToasts((prev) => prev.filter((item) => item.id !== id));
        }, 4500);
    }, []);

    return (
        <ToastContext.Provider value={{ toast }}>
            {children}
            <div className="fixed bottom-4 start-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2">
                {toasts.map((item) => (
                    <div
                        key={item.id}
                        className={`animate-toast-in flex items-start gap-2 rounded-lg border px-3.5 py-2.5 text-sm font-medium shadow-md ${styles[item.variant]}`}
                    >
                        <span className="mt-0.5">
                            <ToastIcon variant={item.variant} />
                        </span>
                        <span className="leading-snug">{item.message}</span>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastContextValue {
    const ctx = useContext(ToastContext);
    if (!ctx) throw new Error('useToast must be used within ToastProvider');
    return ctx;
}
