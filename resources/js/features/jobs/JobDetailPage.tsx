import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { errorCode, errorMessage, errorStatus } from '@/api/client';
import { useEnrichCompany, useJob, useQueueJob } from '@/api/queries/jobs';
import type { Contact } from '@/api/types';
import { PageHeader } from '@/components/shared/PageHeader';
import { Badge, StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { useToast } from '@/components/ui/Toast';
import {
    IconAlert,
    IconExternalLink,
    IconPlus,
    IconRefresh,
    IconWhatsApp,
} from '@/components/ui/icons';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-2">
            <span className="shrink-0 text-xs font-semibold text-slate-500">{label}</span>
            <span className="text-end text-sm text-slate-700">{value}</span>
        </div>
    );
}

function ContactRow({ contact, rank }: { contact: Contact; rank: number }) {
    return (
        <li className="flex items-start gap-3 py-2.5">
            <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">
                {rank}
            </span>
            <div className="min-w-0 grow">
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-sm font-semibold text-slate-700">
                        {contact.name ?? contact.email ?? '—'}
                    </span>
                    {contact.is_alternate && <Badge color="slate">{t.jobs.detail.alternate}</Badge>}
                    <StatusBadge status={contact.status} />
                </div>
                {contact.email && (
                    <p className="text-xs text-slate-500" dir="ltr">
                        {contact.email}
                    </p>
                )}
                {contact.position && <p className="text-xs text-slate-400">{contact.position}</p>}
            </div>
            {contact.whatsapp_url && (
                <a
                    href={contact.whatsapp_url}
                    target="_blank"
                    rel="noreferrer"
                    className="shrink-0 rounded-md p-1.5 text-green-600 hover:bg-green-50"
                    title={contact.phone ?? 'WhatsApp'}
                >
                    <IconWhatsApp className="size-4" />
                </a>
            )}
        </li>
    );
}

export function JobDetailPage() {
    const { id } = useParams();
    const jobId = Number(id);
    const { data: job, isLoading } = useJob(jobId);
    const queueJob = useQueueJob();
    const enrich = useEnrichCompany();
    const { toast } = useToast();
    const [conflict, setConflict] = useState<{ message: string; code?: string } | null>(null);

    const onQueue = () => {
        setConflict(null);
        queueJob.mutate(jobId, {
            onSuccess: () => toast(t.jobs.detail.queuedOk),
            onError: (err) => {
                if (errorStatus(err) === 409) {
                    setConflict({ message: errorMessage(err), code: errorCode(err) });
                } else {
                    toast(errorMessage(err), 'error');
                }
            },
        });
    };

    const onEnrich = () => {
        if (!job?.company) return;
        enrich.mutate(job.company.id, {
            onSuccess: () => toast(t.jobs.detail.enrichStarted),
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    };

    if (isLoading) {
        return (
            <div className="space-y-4">
                <Skeleton className="h-8 w-72" />
                <Skeleton className="h-64 w-full" />
            </div>
        );
    }

    if (!job) {
        return (
            <EmptyState
                title={t.jobs.detail.notFound}
                action={
                    <Link to="/jobs" className="text-sm font-semibold text-indigo-600 hover:underline">
                        {t.jobs.detail.back}
                    </Link>
                }
            />
        );
    }

    return (
        <div>
            <div className="mb-2">
                <Link to="/jobs" className="text-xs font-semibold text-slate-400 hover:text-indigo-600">
                    ← {t.jobs.detail.back}
                </Link>
            </div>
            <PageHeader
                title={job.title}
                description={job.company?.name ?? undefined}
                actions={
                    <>
                        {job.url && (
                            <a href={job.url} target="_blank" rel="noreferrer">
                                <Button variant="secondary">
                                    <IconExternalLink className="size-4" />
                                    {t.jobs.detail.openExternal}
                                </Button>
                            </a>
                        )}
                        {job.company && (
                            <Button variant="secondary" onClick={onEnrich} loading={enrich.isPending}>
                                <IconRefresh className="size-4" />
                                {t.jobs.detail.reEnrich}
                            </Button>
                        )}
                        <Button onClick={onQueue} loading={queueJob.isPending}>
                            <IconPlus className="size-4" />
                            {t.jobs.detail.addToQueue}
                        </Button>
                    </>
                }
            />

            {conflict && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                    <IconAlert className="size-4 shrink-0" />
                    <span>{conflict.message}</span>
                    {conflict.code === 'already_sent' && (
                        <Link
                            to="/contacts"
                            className="font-bold text-indigo-600 underline hover:text-indigo-800"
                        >
                            {t.jobs.detail.goToContacts}
                        </Link>
                    )}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card title={t.jobs.detail.jobInfo} className="lg:col-span-2" bodyClassName="px-5 py-3">
                    <div className="divide-y divide-slate-100">
                        <InfoRow label={t.jobs.columns.status} value={<StatusBadge status={job.status} />} />
                        <InfoRow label={t.jobs.columns.source} value={job.source?.name ?? '—'} />
                        <InfoRow label={t.jobs.columns.location} value={job.location ?? '—'} />
                        <InfoRow label={t.jobs.detail.salary} value={job.salary ?? '—'} />
                        <InfoRow label={t.jobs.detail.postedAt} value={formatDate(job.posted_at)} />
                        <InfoRow label={t.jobs.detail.addedAt} value={formatDateTime(job.created_at)} />
                    </div>
                    {job.description && (
                        <div className="mt-3 border-t border-slate-100 pt-3">
                            <p className="mb-1.5 text-xs font-semibold text-slate-500">
                                {t.jobs.detail.description}
                            </p>
                            <p className="text-sm leading-relaxed whitespace-pre-line text-slate-600">
                                {job.description}
                            </p>
                        </div>
                    )}
                </Card>

                <Card title={t.jobs.detail.companyCard} bodyClassName="p-5">
                    {job.company ? (
                        <div>
                            <div className="mb-3">
                                <p className="font-bold text-slate-800">{job.company.name}</p>
                                {job.company.website && (
                                    <a
                                        href={job.company.website}
                                        target="_blank"
                                        rel="noreferrer"
                                        dir="ltr"
                                        className="text-xs text-indigo-600 hover:underline"
                                    >
                                        {job.company.website}
                                    </a>
                                )}
                                <div className="mt-2">
                                    <StatusBadge status={job.company.enrichment_status} />
                                </div>
                            </div>
                            <p className="mb-1 border-t border-slate-100 pt-3 text-xs font-bold text-slate-500">
                                {t.jobs.detail.contactsList}
                            </p>
                            {job.company.contacts.length === 0 ? (
                                <p className="py-2 text-sm text-slate-400">{t.jobs.detail.noContacts}</p>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {job.company.contacts.map((contact, i) => (
                                        <ContactRow key={contact.id} contact={contact} rank={i + 1} />
                                    ))}
                                </ul>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-slate-400">—</p>
                    )}
                </Card>
            </div>
        </div>
    );
}
