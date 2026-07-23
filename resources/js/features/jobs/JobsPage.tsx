import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useJobs } from '@/api/queries/jobs';
import { useSources } from '@/api/queries/runs';
import type { JobPost } from '@/api/types';
import { DataTable } from '@/components/shared/DataTable';
import type { Column } from '@/components/shared/DataTable';
import { FilterBar } from '@/components/shared/FilterBar';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { IconBriefcase, IconCheck } from '@/components/ui/icons';
import { formatDate } from '@/lib/format';
import { useDebouncedValue } from '@/lib/hooks';
import { t } from '@/lib/i18n';

const JOB_STATUSES = ['new', 'enriching', 'enriched', 'no_contact'];

export function JobsPage() {
    const [status, setStatus] = useState('');
    const [sourceId, setSourceId] = useState('');
    const [hasContact, setHasContact] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const q = useDebouncedValue(search, 400);
    const { data: sources } = useSources();
    const { data: result, isLoading } = useJobs({
        status,
        source_id: sourceId,
        has_contact: hasContact,
        q,
        page,
    });

    const setFilter = (setter: (v: string) => void) => (value: string) => {
        setter(value);
        setPage(1);
    };

    const columns: Column<JobPost>[] = [
        {
            key: 'title',
            header: t.jobs.columns.title,
            render: (job) => (
                <Link
                    to={`/jobs/${job.id}`}
                    className="font-semibold text-indigo-600 hover:text-indigo-800 hover:underline"
                >
                    {job.title}
                </Link>
            ),
        },
        {
            key: 'company',
            header: t.jobs.columns.company,
            render: (job) => <span className="text-slate-700">{job.company?.name ?? '—'}</span>,
        },
        {
            key: 'source',
            header: t.jobs.columns.source,
            render: (job) => <span className="text-slate-500">{job.source?.name ?? '—'}</span>,
        },
        {
            key: 'location',
            header: t.jobs.columns.location,
            render: (job) => <span className="text-slate-500">{job.location ?? '—'}</span>,
        },
        {
            key: 'status',
            header: t.jobs.columns.status,
            render: (job) => <StatusBadge status={job.status} />,
        },
        {
            key: 'contact',
            header: t.jobs.columns.contact,
            render: (job) =>
                job.company && job.company.contacts_count > 0 ? (
                    <span className="inline-flex items-center gap-1 font-semibold text-emerald-600">
                        <IconCheck className="size-4" />
                        {job.company.contacts_count}
                    </span>
                ) : (
                    <span className="text-slate-400">—</span>
                ),
        },
        {
            key: 'date',
            header: t.jobs.columns.date,
            render: (job) => (
                <span className="whitespace-nowrap text-slate-500">{formatDate(job.created_at)}</span>
            ),
        },
    ];

    return (
        <div>
            <PageHeader title={t.jobs.title} description={t.jobs.subtitle} />

            <FilterBar>
                <Input
                    label={t.common.search}
                    placeholder={t.jobs.filters.search}
                    value={search}
                    onChange={(e) => setFilter(setSearch)(e.target.value)}
                    className="w-64"
                />
                <Select
                    label={t.jobs.filters.source}
                    value={sourceId}
                    onChange={(e) => setFilter(setSourceId)(e.target.value)}
                    className="w-44"
                >
                    <option value="">{t.common.all}</option>
                    {(sources ?? []).map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t.jobs.filters.status}
                    value={status}
                    onChange={(e) => setFilter(setStatus)(e.target.value)}
                    className="w-40"
                >
                    <option value="">{t.common.all}</option>
                    {JOB_STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {t.status[s]}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t.jobs.filters.hasContact}
                    value={hasContact}
                    onChange={(e) => setFilter(setHasContact)(e.target.value)}
                    className="w-32"
                >
                    <option value="">{t.common.all}</option>
                    <option value="1">{t.common.yes}</option>
                    <option value="0">{t.common.no}</option>
                </Select>
            </FilterBar>

            <DataTable
                columns={columns}
                result={result}
                isLoading={isLoading}
                page={page}
                onPageChange={setPage}
                rowKey={(job) => job.id}
                emptyTitle={t.jobs.empty}
                emptyDescription={t.jobs.emptyDesc}
                emptyIcon={<IconBriefcase className="size-6" />}
            />
        </div>
    );
}
