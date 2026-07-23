import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { errorMessage } from '@/api/client';
import { useImportRun, useRunSource, useRuns, useSources, useSyncRuns } from '@/api/queries/runs';
import { useSettings, useUpdateAutoScrape } from '@/api/queries/settings';
import type { ApifyRun, AutoScrapeSettings, ImportMode } from '@/api/types';
import { DataTable } from '@/components/shared/DataTable';
import type { Column } from '@/components/shared/DataTable';
import { FilterBar } from '@/components/shared/FilterBar';
import { PageHeader } from '@/components/shared/PageHeader';
import { Badge, StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Toggle } from '@/components/ui/Toggle';
import { useToast } from '@/components/ui/Toast';
import { IconChevronDown, IconDownload, IconPlay, IconRefresh, IconSearch } from '@/components/ui/icons';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

const tt = t.searches.autoScrape;

function AutoScrapeForm({ autoScrape }: { autoScrape: AutoScrapeSettings }) {
    const updateAutoScrape = useUpdateAutoScrape();
    const { toast } = useToast();

    const [enabled, setEnabled] = useState(autoScrape.enabled);
    const [time, setTime] = useState(autoScrape.time || '10:00');
    const [autoKeywords, setAutoKeywords] = useState(autoScrape.keywords.trim() === '');
    const [keywords, setKeywords] = useState(autoScrape.keywords);
    const [location, setLocation] = useState(autoScrape.location);
    const [randomSources, setRandomSources] = useState(autoScrape.random_sources);
    const [autoQueue, setAutoQueue] = useState(autoScrape.auto_queue);
    const [importMode, setImportMode] = useState<ImportMode>(autoScrape.import_mode ?? 'all');

    const onAutoKeywordsChange = (value: boolean) => {
        setAutoKeywords(value);
        if (value) setKeywords('');
    };

    const effectiveKeywords = autoKeywords ? '' : keywords.trim();

    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        updateAutoScrape.mutate(
            {
                enabled,
                time,
                keywords: effectiveKeywords,
                location,
                random_sources: randomSources,
                auto_queue: autoQueue,
                import_mode: importMode,
            },
            {
                onSuccess: () => toast(tt.savedOk),
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const locationName =
        location === ''
            ? tt.randomArab
            : (autoScrape.available_locations.find((l) => l.code === location)?.name ?? location);
    const summaryKeywords = effectiveKeywords === '' ? tt.autoValue : effectiveKeywords;

    return (
        <form onSubmit={onSubmit} className="space-y-4">
            <Toggle checked={enabled} onChange={setEnabled} label={tt.enable} size="lg" />

            <div className={`space-y-4 ${enabled ? '' : 'opacity-60'}`}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label={tt.time}
                        type="time"
                        dir="ltr"
                        className="sm:w-44"
                        value={time}
                        onChange={(e) => setTime(e.target.value)}
                        disabled={!enabled}
                    />
                    <Select
                        label={tt.location}
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                        disabled={!enabled}
                        className="sm:w-64"
                    >
                        <option value="">{tt.locationAuto}</option>
                        {autoScrape.available_locations.map((l) => (
                            <option key={l.code} value={l.code}>
                                {l.name}
                            </option>
                        ))}
                    </Select>
                </div>

                <div className="space-y-2">
                    <Toggle
                        checked={autoKeywords}
                        onChange={onAutoKeywordsChange}
                        label={tt.autoKeywords}
                        disabled={!enabled}
                    />
                    <Input
                        label={tt.keywords}
                        hint={tt.keywordsHint}
                        value={autoKeywords ? '' : keywords}
                        onChange={(e) => setKeywords(e.target.value)}
                        disabled={!enabled || autoKeywords}
                    />
                </div>

                <div className="space-y-1">
                    <Toggle
                        checked={randomSources}
                        onChange={setRandomSources}
                        label={tt.randomSources}
                        disabled={!enabled}
                    />
                    <p className="text-xs text-slate-400">{tt.randomSourcesHint}</p>
                </div>

                <Toggle
                    checked={autoQueue}
                    onChange={setAutoQueue}
                    label={tt.autoQueue}
                    disabled={!enabled}
                />

                <div className="space-y-1">
                    <Select
                        label={tt.importMode}
                        value={importMode}
                        onChange={(e) => setImportMode(e.target.value as ImportMode)}
                        disabled={!enabled}
                        className="sm:w-96"
                    >
                        <option value="all">{tt.importModeOptions.all}</option>
                        <option value="with_email">{tt.importModeOptions.with_email}</option>
                        <option value="companies_only">{tt.importModeOptions.companies_only}</option>
                    </Select>
                    <p className="text-xs text-slate-400">{tt.importModeHint}</p>
                </div>

                <p className="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-800">
                    {tt.summary(time, summaryKeywords, locationName, tt.importModeShort[importMode])}
                </p>
            </div>

            <Button type="submit" loading={updateAutoScrape.isPending}>
                {t.common.save}
            </Button>
        </form>
    );
}

function AutoScrapeCard() {
    const { data: settings } = useSettings();

    return (
        <Card title={tt.title} className="mb-5" bodyClassName="p-5">
            {settings ? (
                <AutoScrapeForm autoScrape={settings.auto_scrape} />
            ) : (
                <Skeleton className="h-72 w-full" />
            )}
        </Card>
    );
}

function NewSearchPanel() {
    const { data: sources } = useSources();
    const runSource = useRunSource();
    const { toast } = useToast();

    const enabledSources = (sources ?? []).filter((s) => s.enabled);
    const [sourceId, setSourceId] = useState('');
    const [keywords, setKeywords] = useState('');
    const [location, setLocation] = useState('');
    const [maxItems, setMaxItems] = useState('');

    // اختيار أول مصدر تلقائيًا وتعبئة الافتراضيات
    useEffect(() => {
        if (!sourceId && enabledSources.length > 0) {
            const first = enabledSources[0];
            setSourceId(String(first.id));
            setKeywords(first.default_keywords ?? '');
            setLocation(first.default_location ?? '');
            setMaxItems(first.max_items != null ? String(first.max_items) : '');
        }
    }, [sourceId, enabledSources]);

    const onSourceChange = (value: string) => {
        setSourceId(value);
        const source = enabledSources.find((s) => String(s.id) === value);
        if (source) {
            setKeywords(source.default_keywords ?? '');
            setLocation(source.default_location ?? '');
            setMaxItems(source.max_items != null ? String(source.max_items) : '');
        }
    };

    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        const id = Number(sourceId);
        if (!Number.isFinite(id) || id <= 0) return;
        runSource.mutate(
            {
                id,
                body: {
                    keywords: keywords || undefined,
                    location: location || undefined,
                    max_items: maxItems ? Number(maxItems) : undefined,
                },
            },
            {
                onSuccess: () => toast(t.searches.newSearch.startedOk),
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    return (
        <Card title={t.searches.newSearch.title} className="mb-5" bodyClassName="p-5">
            {enabledSources.length === 0 ? (
                <p className="text-sm text-slate-500">{t.searches.newSearch.noSources}</p>
            ) : (
                <form onSubmit={onSubmit} className="flex flex-wrap items-end gap-3">
                    <Select
                        label={t.searches.newSearch.source}
                        value={sourceId}
                        onChange={(e) => onSourceChange(e.target.value)}
                        className="w-48"
                    >
                        {enabledSources.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label={t.searches.newSearch.keywords}
                        value={keywords}
                        onChange={(e) => setKeywords(e.target.value)}
                        className="w-64"
                    />
                    <Input
                        label={t.searches.newSearch.location}
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                        className="w-44"
                    />
                    <Input
                        label={t.searches.newSearch.maxItems}
                        type="number"
                        min={1}
                        value={maxItems}
                        onChange={(e) => setMaxItems(e.target.value)}
                        className="w-32"
                    />
                    <Button type="submit" loading={runSource.isPending}>
                        <IconPlay className="size-4" />
                        {t.searches.newSearch.start}
                    </Button>
                </form>
            )}
        </Card>
    );
}

function InputSummary({ run }: { run: ApifyRun }) {
    const [open, setOpen] = useState(false);
    if (!run.input) return <span className="text-slate-400">—</span>;

    const keywords = typeof run.input.keywords === 'string' ? run.input.keywords : null;

    return (
        <div className="max-w-56">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline"
            >
                <IconChevronDown className={`size-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
                {keywords ?? t.searches.showInput}
            </button>
            {open && (
                <pre
                    dir="ltr"
                    className="mt-1.5 max-h-40 overflow-auto rounded-lg bg-slate-50 p-2 text-[11px] leading-snug text-slate-600"
                >
                    {JSON.stringify(run.input, null, 2)}
                </pre>
            )}
        </div>
    );
}

export function SearchesPage() {
    const [purpose, setPurpose] = useState('');
    const [origin, setOrigin] = useState('');
    const [page, setPage] = useState(1);

    const { data: result, isLoading } = useRuns({ purpose, origin, page });
    const { data: sources } = useSources();
    const importRun = useImportRun();
    const syncRuns = useSyncRuns();
    const rerun = useRunSource();
    const { toast } = useToast();

    const [importTarget, setImportTarget] = useState<ApifyRun | null>(null);
    const [importSourceId, setImportSourceId] = useState('');

    const setFilter = (setter: (v: string) => void) => (value: string) => {
        setter(value);
        setPage(1);
    };

    const onSync = () => {
        syncRuns.mutate(undefined, {
            onSuccess: (data) => toast(t.searches.syncedOk(data.synced)),
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    };

    const onRerun = (run: ApifyRun) => {
        if (!run.source_id) return;
        const input = run.input ?? {};
        rerun.mutate(
            {
                id: run.source_id,
                body: {
                    keywords: typeof input.keywords === 'string' ? input.keywords : undefined,
                    location: typeof input.location === 'string' ? input.location : undefined,
                    max_items: typeof input.max_items === 'number' ? input.max_items : undefined,
                },
            },
            {
                onSuccess: () => toast(t.searches.rerunOk),
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const submitImport = () => {
        if (!importTarget || !importSourceId) return;
        importRun.mutate(
            { runId: importTarget.id, sourceId: Number(importSourceId) },
            {
                onSuccess: (data) => {
                    toast(t.searches.importModal.importedOk(data.imported));
                    setImportTarget(null);
                },
                onError: (err) => toast(errorMessage(err), 'error'),
            },
        );
    };

    const canImport = (run: ApifyRun) =>
        !run.imported_at && run.status.toLowerCase() === 'succeeded';

    const canRerun = (run: ApifyRun) => run.purpose === 'scrape' && run.source_id !== null;

    const columns: Column<ApifyRun>[] = [
        {
            key: 'actor',
            header: t.searches.columns.actor,
            render: (run) => (
                <div>
                    <p className="font-semibold text-slate-700" dir="ltr">
                        {run.actor_name ?? run.actor_id}
                    </p>
                    {run.source_name && <p className="text-xs text-slate-500">{run.source_name}</p>}
                </div>
            ),
        },
        {
            key: 'type',
            header: t.searches.columns.type,
            render: (run) => (
                <div className="flex flex-wrap gap-1">
                    <Badge color={run.purpose === 'scrape' ? 'indigo' : run.purpose === 'enrich' ? 'teal' : 'slate'}>
                        {t.searches.purpose[run.purpose] ?? run.purpose}
                    </Badge>
                    <Badge color="slate">{t.searches.origin[run.origin] ?? run.origin}</Badge>
                </div>
            ),
        },
        {
            key: 'input',
            header: t.searches.columns.input,
            render: (run) => <InputSummary run={run} />,
        },
        {
            key: 'startedAt',
            header: t.searches.columns.startedAt,
            render: (run) => (
                <span className="whitespace-nowrap text-slate-500">{formatDateTime(run.started_at)}</span>
            ),
        },
        {
            key: 'items',
            header: t.searches.columns.items,
            render: (run) => (
                <span className="tabular-nums text-slate-700">{run.item_count ?? '—'}</span>
            ),
        },
        {
            key: 'imported',
            header: t.searches.columns.imported,
            render: (run) =>
                run.imported_at ? (
                    <span className="text-xs whitespace-nowrap text-slate-500">
                        {formatDateTime(run.imported_at)}
                    </span>
                ) : (
                    <span className="text-slate-400">—</span>
                ),
        },
        {
            key: 'status',
            header: t.searches.columns.status,
            render: (run) => <StatusBadge status={run.status} />,
        },
        {
            key: 'actions',
            header: t.searches.columns.actions,
            render: (run) => (
                <div className="flex flex-wrap gap-1.5">
                    {canImport(run) && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                setImportTarget(run);
                                setImportSourceId(run.source_id ? String(run.source_id) : '');
                            }}
                        >
                            <IconDownload className="size-3.5" />
                            {t.searches.actions.import}
                        </Button>
                    )}
                    {canRerun(run) && (
                        <Button
                            size="sm"
                            variant="secondary"
                            loading={rerun.isPending && rerun.variables?.id === run.source_id}
                            onClick={() => onRerun(run)}
                        >
                            <IconRefresh className="size-3.5" />
                            {t.searches.actions.rerun}
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <div>
            <PageHeader
                title={t.searches.title}
                description={t.searches.subtitle}
                actions={
                    <Button variant="secondary" loading={syncRuns.isPending} onClick={onSync}>
                        <IconRefresh className="size-4" />
                        {t.searches.sync}
                    </Button>
                }
            />

            <AutoScrapeCard />

            <NewSearchPanel />

            <FilterBar>
                <Select
                    label={t.searches.filters.purpose}
                    value={purpose}
                    onChange={(e) => setFilter(setPurpose)(e.target.value)}
                    className="w-36"
                >
                    <option value="">{t.common.all}</option>
                    <option value="scrape">{t.searches.purpose.scrape}</option>
                    <option value="enrich">{t.searches.purpose.enrich}</option>
                    <option value="external">{t.searches.purpose.external}</option>
                </Select>
                <Select
                    label={t.searches.filters.origin}
                    value={origin}
                    onChange={(e) => setFilter(setOrigin)(e.target.value)}
                    className="w-36"
                >
                    <option value="">{t.common.all}</option>
                    <option value="system">{t.searches.origin.system}</option>
                    <option value="synced">{t.searches.origin.synced}</option>
                </Select>
            </FilterBar>

            <DataTable
                columns={columns}
                result={result}
                isLoading={isLoading}
                page={page}
                onPageChange={setPage}
                rowKey={(run) => run.id}
                emptyTitle={t.searches.empty}
                emptyDescription={t.searches.emptyDesc}
                emptyIcon={<IconSearch className="size-6" />}
            />

            <Modal
                open={importTarget !== null}
                onClose={() => setImportTarget(null)}
                title={t.searches.importModal.title}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setImportTarget(null)}>
                            {t.common.cancel}
                        </Button>
                        <Button
                            onClick={submitImport}
                            disabled={!importSourceId}
                            loading={importRun.isPending}
                        >
                            {t.searches.importModal.submit}
                        </Button>
                    </>
                }
            >
                <Select
                    label={t.searches.importModal.source}
                    value={importSourceId}
                    onChange={(e) => setImportSourceId(e.target.value)}
                >
                    <option value="">—</option>
                    {(sources ?? []).map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </Select>
            </Modal>
        </div>
    );
}
