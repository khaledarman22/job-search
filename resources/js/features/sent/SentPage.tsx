import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useEmails, useMarkReplied } from '@/api/queries/emails';
import type { OutreachEmail } from '@/api/types';
import { DataTable } from '@/components/shared/DataTable';
import type { Column } from '@/components/shared/DataTable';
import { FilterBar } from '@/components/shared/FilterBar';
import { PageHeader } from '@/components/shared/PageHeader';
import { Badge } from '@/components/shared/StatusBadge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Toggle } from '@/components/ui/Toggle';
import { Tooltip } from '@/components/ui/Tooltip';
import { IconSend } from '@/components/ui/icons';
import { formatDateTime } from '@/lib/format';
import { useDebouncedValue } from '@/lib/hooks';
import { t } from '@/lib/i18n';

export function SentPage() {
    const [opened, setOpened] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const q = useDebouncedValue(search, 400);
    const { data: result, isLoading } = useEmails({ status: 'sent', opened, q, page });
    const markReplied = useMarkReplied();

    const setFilter = (setter: (v: string) => void) => (value: string) => {
        setter(value);
        setPage(1);
    };

    const columns: Column<OutreachEmail>[] = [
        {
            key: 'recipient',
            header: t.sent.columns.recipient,
            render: (email) => (
                <div>
                    <p className="font-semibold text-slate-700" dir="ltr">
                        {email.to_email}
                    </p>
                    {email.contact.name && <p className="text-xs text-slate-500">{email.contact.name}</p>}
                </div>
            ),
        },
        {
            key: 'company',
            header: t.sent.columns.company,
            render: (email) => <span className="text-slate-700">{email.company.name}</span>,
        },
        {
            key: 'job',
            header: t.sent.columns.job,
            render: (email) => <span className="text-slate-500">{email.job_post?.title ?? '—'}</span>,
        },
        {
            key: 'sentAt',
            header: t.sent.columns.sentAt,
            render: (email) => (
                <span className="whitespace-nowrap text-slate-500">{formatDateTime(email.sent_at)}</span>
            ),
        },
        {
            key: 'opens',
            header: t.sent.columns.opens,
            render: (email) =>
                email.opened_at ? (
                    <Tooltip content={t.sent.firstOpen(formatDateTime(email.opened_at))}>
                        <Badge color="emerald">{t.sent.openedTimes(email.open_count)}</Badge>
                    </Tooltip>
                ) : (
                    <span className="text-xs text-slate-400">{t.sent.notOpened}</span>
                ),
        },
        {
            key: 'replied',
            header: t.sent.columns.replied,
            render: (email) => (
                <Toggle
                    checked={email.replied_at !== null}
                    onChange={(value) => markReplied.mutate({ id: email.id, replied: value })}
                    disabled={markReplied.isPending}
                />
            ),
        },
        {
            key: 'requeued',
            header: t.sent.columns.requeued,
            render: (email) =>
                email.is_manual ? (
                    <Badge color="indigo">{t.sent.manualBadge}</Badge>
                ) : (
                    <span className="text-slate-400">—</span>
                ),
        },
        {
            key: 'actions',
            header: t.sent.columns.actions,
            render: (email) => (
                <Link
                    to={`/sent/${email.id}`}
                    className="text-sm font-semibold text-indigo-600 hover:underline"
                >
                    {t.sent.details}
                </Link>
            ),
        },
    ];

    return (
        <div>
            <PageHeader title={t.sent.title} description={t.sent.subtitle} />

            <FilterBar>
                <Input
                    label={t.common.search}
                    placeholder={t.sent.filters.search}
                    value={search}
                    onChange={(e) => setFilter(setSearch)(e.target.value)}
                    className="w-64"
                />
                <Select
                    label={t.sent.filters.opened}
                    value={opened}
                    onChange={(e) => setFilter(setOpened)(e.target.value)}
                    className="w-36"
                >
                    <option value="">{t.common.all}</option>
                    <option value="1">{t.sent.filters.openedYes}</option>
                    <option value="0">{t.sent.filters.openedNo}</option>
                </Select>
            </FilterBar>

            <DataTable
                columns={columns}
                result={result}
                isLoading={isLoading}
                page={page}
                onPageChange={setPage}
                rowKey={(email) => email.id}
                emptyTitle={t.sent.empty}
                emptyIcon={<IconSend className="size-6" />}
            />
        </div>
    );
}
