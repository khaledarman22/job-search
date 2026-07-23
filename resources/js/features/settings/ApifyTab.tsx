import { useState } from 'react';
import { errorMessage } from '@/api/client';
import {
    useCreateSource,
    useDeleteSource,
    useSources,
    useTestSource,
    useUpdateSource,
} from '@/api/queries/runs';
import { useSaveApify } from '@/api/queries/settings';
import type { ApifySettings, Source, SourcePayload, SourceTestResult } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/Skeleton';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { useToast } from '@/components/ui/Toast';
import { Toggle } from '@/components/ui/Toggle';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { IconAlert, IconPlay, IconPlus, IconTrash } from '@/components/ui/icons';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

interface SourceFormState {
    name: string;
    actor_id: string;
    enabled: boolean;
    default_keywords: string;
    default_location: string;
    max_items: string;
    input_template: string;
    field_map: string;
}

function toFormState(source: Source | null): SourceFormState {
    return {
        name: source?.name ?? '',
        actor_id: source?.actor_id ?? '',
        enabled: source?.enabled ?? true,
        default_keywords: source?.default_keywords ?? '',
        default_location: source?.default_location ?? '',
        max_items: source?.max_items != null ? String(source.max_items) : '',
        input_template: JSON.stringify(source?.input_template ?? {}, null, 2),
        field_map: JSON.stringify(source?.field_map ?? {}, null, 2),
    };
}

function SourceCard({ source, onCreated }: { source: Source | null; onCreated?: () => void }) {
    const [form, setForm] = useState<SourceFormState>(() => toFormState(source));
    const [formError, setFormError] = useState<string | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [testOpen, setTestOpen] = useState(false);
    const [testResult, setTestResult] = useState<SourceTestResult | null>(null);
    const [testError, setTestError] = useState<string | null>(null);

    const createSource = useCreateSource();
    const updateSource = useUpdateSource();
    const deleteSource = useDeleteSource();
    const testSource = useTestSource();
    const { toast } = useToast();

    const set = <K extends keyof SourceFormState>(key: K, value: SourceFormState[K]) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const buildPayload = (): SourcePayload | null => {
        let inputTemplate: Record<string, unknown>;
        let fieldMap: Record<string, unknown>;
        try {
            inputTemplate = JSON.parse(form.input_template) as Record<string, unknown>;
        } catch {
            setFormError(t.settings.apify.invalidJson(t.settings.apify.inputTemplate));
            return null;
        }
        try {
            fieldMap = JSON.parse(form.field_map) as Record<string, unknown>;
        } catch {
            setFormError(t.settings.apify.invalidJson(t.settings.apify.fieldMap));
            return null;
        }
        setFormError(null);
        return {
            name: form.name,
            actor_id: form.actor_id,
            input_template: inputTemplate,
            field_map: fieldMap,
            default_keywords: form.default_keywords || undefined,
            default_location: form.default_location || undefined,
            max_items: form.max_items ? Number(form.max_items) : undefined,
            enabled: form.enabled,
        };
    };

    const onSave = () => {
        const payload = buildPayload();
        if (!payload) return;
        if (source) {
            updateSource.mutate(
                { id: source.id, data: payload },
                {
                    onSuccess: () => toast(t.common.saved),
                    onError: (err) => setFormError(errorMessage(err)),
                },
            );
        } else {
            createSource.mutate(payload, {
                onSuccess: () => {
                    toast(t.settings.apify.createdOk);
                    onCreated?.();
                },
                onError: (err) => setFormError(errorMessage(err)),
            });
        }
    };

    const onTest = () => {
        if (!source) return;
        setTestResult(null);
        setTestError(null);
        setTestOpen(true);
        testSource.mutate(
            { id: source.id, body: { keywords: form.default_keywords || undefined } },
            {
                onSuccess: (data) => setTestResult(data),
                onError: (err) => setTestError(errorMessage(err)),
            },
        );
    };

    const onDelete = () => {
        if (!source) return;
        deleteSource.mutate(source.id, {
            onSuccess: () => {
                toast(t.settings.apify.deletedOk);
                setDeleteOpen(false);
            },
            onError: (err) => {
                toast(errorMessage(err), 'error');
                setDeleteOpen(false);
            },
        });
    };

    const saving = createSource.isPending || updateSource.isPending;

    return (
        <Card
            title={source ? source.name : t.settings.apify.newSource}
            actions={
                <div className="flex items-center gap-3">
                    {source?.last_run_at && (
                        <span className="text-xs text-slate-400">
                            {t.settings.apify.lastRun}: {formatDateTime(source.last_run_at)}
                        </span>
                    )}
                    <Toggle
                        checked={form.enabled}
                        onChange={(v) => set('enabled', v)}
                        label={t.settings.apify.enabled}
                    />
                </div>
            }
            bodyClassName="p-5 space-y-4"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Input
                    label={t.settings.apify.sourceName}
                    value={form.name}
                    onChange={(e) => set('name', e.target.value)}
                />
                <Input
                    label={t.settings.apify.actorId}
                    dir="ltr"
                    value={form.actor_id}
                    onChange={(e) => set('actor_id', e.target.value)}
                />
                <Input
                    label={t.settings.apify.defaultKeywords}
                    value={form.default_keywords}
                    onChange={(e) => set('default_keywords', e.target.value)}
                />
                <Input
                    label={t.settings.apify.defaultLocation}
                    value={form.default_location}
                    onChange={(e) => set('default_location', e.target.value)}
                />
                <Input
                    label={t.settings.apify.maxItems}
                    type="number"
                    min={1}
                    value={form.max_items}
                    onChange={(e) => set('max_items', e.target.value)}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Textarea
                    label={t.settings.apify.inputTemplate}
                    dir="ltr"
                    rows={7}
                    className="font-mono"
                    value={form.input_template}
                    onChange={(e) => set('input_template', e.target.value)}
                />
                <Textarea
                    label={t.settings.apify.fieldMap}
                    dir="ltr"
                    rows={7}
                    className="font-mono"
                    value={form.field_map}
                    onChange={(e) => set('field_map', e.target.value)}
                />
            </div>

            {formError && (
                <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
                    <IconAlert className="size-4 shrink-0" />
                    {formError}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                <Button onClick={onSave} loading={saving}>
                    {t.common.save}
                </Button>
                {source && (
                    <>
                        <Button variant="secondary" onClick={onTest}>
                            <IconPlay className="size-4" />
                            {t.settings.apify.test}
                        </Button>
                        <Button variant="danger" onClick={() => setDeleteOpen(true)}>
                            <IconTrash className="size-4" />
                            {t.common.delete}
                        </Button>
                    </>
                )}
            </div>

            <ConfirmDialog
                open={deleteOpen}
                title={t.settings.apify.deleteTitle}
                message={source ? t.settings.apify.deleteConfirm(source.name) : ''}
                danger
                confirmLabel={t.common.delete}
                loading={deleteSource.isPending}
                onConfirm={onDelete}
                onClose={() => setDeleteOpen(false)}
            />

            <Modal
                open={testOpen}
                onClose={() => setTestOpen(false)}
                title={t.settings.apify.testResult}
                wide
            >
                {testSource.isPending ? (
                    <div className="flex flex-col items-center gap-3 py-10 text-slate-500">
                        <Spinner className="size-8 text-indigo-600" />
                        <p className="text-sm font-semibold">{t.settings.apify.testing}</p>
                    </div>
                ) : testError ? (
                    <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-medium text-red-700">
                        <IconAlert className="mt-0.5 size-4 shrink-0" />
                        {testError}
                    </div>
                ) : testResult ? (
                    <div className="space-y-3">
                        <div className="flex items-center gap-2 text-sm font-semibold text-slate-600">
                            {t.settings.apify.runStatus}: <StatusBadge status={testResult.run_status} />
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            <div>
                                <p className="mb-1.5 text-xs font-bold text-slate-500">{t.settings.apify.raw}</p>
                                <pre
                                    dir="ltr"
                                    className="max-h-80 overflow-auto rounded-lg bg-slate-50 p-3 text-[11px] leading-snug text-slate-700"
                                >
                                    {JSON.stringify(testResult.raw_items, null, 2)}
                                </pre>
                            </div>
                            <div>
                                <p className="mb-1.5 text-xs font-bold text-slate-500">
                                    {t.settings.apify.mapped}
                                </p>
                                <pre
                                    dir="ltr"
                                    className="max-h-80 overflow-auto rounded-lg bg-slate-50 p-3 text-[11px] leading-snug text-slate-700"
                                >
                                    {JSON.stringify(testResult.mapped, null, 2)}
                                </pre>
                            </div>
                        </div>
                    </div>
                ) : null}
            </Modal>
        </Card>
    );
}

export function ApifyTab({ apify }: { apify: ApifySettings }) {
    const [token, setToken] = useState('');
    const [enrichActor, setEnrichActor] = useState(apify.enrich_actor_id);
    const [error, setError] = useState<string | null>(null);
    const [draftOpen, setDraftOpen] = useState(false);

    const saveApify = useSaveApify();
    const { data: sources, isLoading: sourcesLoading } = useSources();
    const { toast } = useToast();

    const onSave = () => {
        setError(null);
        saveApify.mutate(
            { token: token || undefined, enrich_actor_id: enrichActor },
            {
                onSuccess: (data) => {
                    toast(
                        data.account_name
                            ? t.settings.apify.savedWithAccount(data.account_name)
                            : t.common.saved,
                    );
                    setToken('');
                },
                onError: (err) => setError(errorMessage(err)),
            },
        );
    };

    return (
        <div className="space-y-5">
            <Card title={t.settings.apify.sectionToken} bodyClassName="p-5 space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label={t.settings.apify.token}
                        type="password"
                        dir="ltr"
                        autoComplete="off"
                        placeholder={apify.token_set ? (apify.token_masked ?? '') : ''}
                        hint={apify.token_set ? t.settings.apify.tokenHint : undefined}
                        value={token}
                        onChange={(e) => setToken(e.target.value)}
                    />
                    <Input
                        label={t.settings.apify.enrichActor}
                        dir="ltr"
                        value={enrichActor}
                        onChange={(e) => setEnrichActor(e.target.value)}
                    />
                </div>
                {error && (
                    <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
                        <IconAlert className="size-4 shrink-0" />
                        {error}
                    </div>
                )}
                <Button onClick={onSave} loading={saveApify.isPending}>
                    {t.common.save}
                </Button>
            </Card>

            <div className="flex items-center justify-between">
                <h2 className="text-base font-bold text-slate-800">{t.settings.apify.sources}</h2>
                <Button variant="secondary" onClick={() => setDraftOpen(true)} disabled={draftOpen}>
                    <IconPlus className="size-4" />
                    {t.settings.apify.addSource}
                </Button>
            </div>

            {draftOpen && <SourceCard source={null} onCreated={() => setDraftOpen(false)} />}

            {sourcesLoading ? (
                <Skeleton className="h-64 w-full" />
            ) : (sources ?? []).length === 0 && !draftOpen ? (
                <p className="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-400">
                    {t.settings.apify.noSources}
                </p>
            ) : (
                (sources ?? []).map((source) => <SourceCard key={source.id} source={source} />)
            )}
        </div>
    );
}
