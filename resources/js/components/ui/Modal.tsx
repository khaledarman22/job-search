import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { IconX } from '@/components/ui/icons';

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    wide?: boolean;
}

export function Modal({ open, onClose, title, children, footer, wide = false }: ModalProps) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" dir="rtl">
            <div className="absolute inset-0 bg-slate-900/50" onClick={onClose} />
            <div
                role="dialog"
                aria-modal="true"
                className={`relative flex max-h-[85vh] w-full flex-col rounded-xl bg-white shadow-xl ${
                    wide ? 'max-w-3xl' : 'max-w-lg'
                }`}
            >
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                    <h3 className="font-bold text-slate-800">{title}</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <IconX className="size-4" />
                    </button>
                </div>
                <div className="grow overflow-y-auto p-5">{children}</div>
                {footer && (
                    <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-3.5">
                        {footer}
                    </div>
                )}
            </div>
        </div>,
        document.body,
    );
}
