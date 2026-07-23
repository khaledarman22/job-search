import { useRef, useState } from 'react';
import { errorMessage } from '@/api/client';
import { useSaveTemplate, useTemplatePreview } from '@/api/queries/settings';
import type { CvInfo, TemplateSettings } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { useToast } from '@/components/ui/Toast';
import { IconAlert, IconEye, IconFile } from '@/components/ui/icons';
import { TEMPLATE_PLACEHOLDERS, t } from '@/lib/i18n';

export function TemplateTab({ template, cv }: { template: TemplateSettings; cv: CvInfo }) {
    const [subject, setSubject] = useState(template.subject);
    const [body, setBody] = useState(template.body);
    const [dir, setDir] = useState<'ltr' | 'rtl'>('ltr');
    const [previewOpen, setPreviewOpen] = useState(false);
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    const saveTemplate = useSaveTemplate();
    const preview = useTemplatePreview();
    const { toast } = useToast();

    const insertPlaceholder = (placeholder: string) => {
        const el = bodyRef.current;
        if (!el) {
            setBody((prev) => prev + placeholder);
            return;
        }
        const start = el.selectionStart ?? body.length;
        const end = el.selectionEnd ?? start;
        const next = body.slice(0, start) + placeholder + body.slice(end);
        setBody(next);
        requestAnimationFrame(() => {
            el.focus();
            const pos = start + placeholder.length;
            el.setSelectionRange(pos, pos);
        });
    };

    const onSave = () => {
        saveTemplate.mutate(
            { subject, body },
            {
                onSuccess: () => toast(t.common.saved),
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const onPreview = () => {
        setPreviewOpen(true);
        preview.mutate(
            { subject, body },
            { onError: (err) => toast(errorMessage(err), 'error') },
        );
    };

    return (
        <div className="space-y-5">
            <Card
                title={t.settings.tabs.template}
                actions={
                    <button
                        type="button"
                        onClick={() => setDir((d) => (d === 'ltr' ? 'rtl' : 'ltr'))}
                        className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50"
                    >
                        {t.settings.template.direction}: {dir.toUpperCase()}
                    </button>
                }
                bodyClassName="p-5 space-y-4"
            >
                <Input
                    label={t.settings.template.subject}
                    dir={dir}
                    value={subject}
                    onChange={(e) => setSubject(e.target.value)}
                />

                <div>
                    <p className="mb-1.5 text-xs font-semibold text-slate-600">
                        {t.settings.template.placeholders}
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {TEMPLATE_PLACEHOLDERS.map((placeholder) => (
                            <button
                                key={placeholder}
                                type="button"
                                dir="ltr"
                                onClick={() => insertPlaceholder(placeholder)}
                                className="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 font-mono text-xs font-semibold text-indigo-700 transition-colors hover:bg-indigo-100"
                            >
                                {placeholder}
                            </button>
                        ))}
                    </div>
                </div>

                <Textarea
                    ref={bodyRef}
                    label={t.settings.template.body}
                    dir={dir}
                    rows={16}
                    className="font-mono"
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                />

                <div
                    className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-semibold ${
                        cv.uploaded
                            ? 'border-slate-200 bg-slate-50 text-slate-600'
                            : 'border-amber-200 bg-amber-50 text-amber-800'
                    }`}
                >
                    {cv.uploaded ? (
                        <>
                            <IconFile className="size-4 shrink-0" />
                            {t.settings.template.cvAttached(cv.original_name ?? '')}
                        </>
                    ) : (
                        <>
                            <IconAlert className="size-4 shrink-0" />
                            {t.settings.template.noCv}
                        </>
                    )}
                </div>

                <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    <Button onClick={onSave} loading={saveTemplate.isPending}>
                        {t.common.save}
                    </Button>
                    <Button variant="secondary" onClick={onPreview}>
                        <IconEye className="size-4" />
                        {t.settings.template.preview}
                    </Button>
                </div>
            </Card>

            <Modal
                open={previewOpen}
                onClose={() => setPreviewOpen(false)}
                title={t.settings.template.previewTitle}
                wide
            >
                {preview.isPending ? (
                    <div className="space-y-3">
                        <div className="h-6 w-2/3 animate-pulse rounded bg-slate-200" />
                        <div className="h-64 w-full animate-pulse rounded bg-slate-100" />
                    </div>
                ) : preview.data ? (
                    <div className="space-y-3">
                        <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700">
                            {t.settings.template.subject}: {preview.data.subject}
                        </p>
                        <iframe
                            title={t.settings.template.previewTitle}
                            sandbox=""
                            srcDoc={preview.data.body}
                            className="h-96 w-full rounded-lg border border-slate-200 bg-white"
                        />
                    </div>
                ) : null}
            </Modal>
        </div>
    );
}
