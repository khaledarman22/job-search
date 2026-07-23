import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '@/api/client';
import { useEmail, useRequeueEmail } from '@/api/queries/emails';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { useToast } from '@/components/ui/Toast';
import { IconEye, IconRefresh } from '@/components/ui/icons';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-2">
            <span className="shrink-0 text-xs font-semibold text-slate-500">{label}</span>
            <span className="text-end text-sm text-slate-700">{value}</span>
        </div>
    );
}

export function SentDetailPage() {
    const { id } = useParams();
    const emailId = Number(id);
    const { data: email, isLoading } = useEmail(emailId);
    const requeue = useRequeueEmail();
    const { toast } = useToast();
    const navigate = useNavigate();
    const [confirmOpen, setConfirmOpen] = useState(false);

    const onResend = () => {
        requeue.mutate(emailId, {
            onSuccess: () => {
                toast(t.sent.detail.resentOk);
                setConfirmOpen(false);
                navigate('/queue');
            },
            onError: (err) => {
                toast(errorMessage(err), 'error');
                setConfirmOpen(false);
            },
        });
    };

    if (isLoading) {
        return (
            <div className="space-y-4">
                <Skeleton className="h-8 w-72" />
                <Skeleton className="h-96 w-full" />
            </div>
        );
    }

    if (!email) {
        return (
            <EmptyState
                title={t.sent.detail.notFound}
                action={
                    <Link to="/sent" className="text-sm font-semibold text-indigo-600 hover:underline">
                        {t.sent.detail.back}
                    </Link>
                }
            />
        );
    }

    return (
        <div>
            <div className="mb-2">
                <Link to="/sent" className="text-xs font-semibold text-slate-400 hover:text-indigo-600">
                    ← {t.sent.detail.back}
                </Link>
            </div>
            <PageHeader
                title={email.subject}
                description={email.to_email}
                actions={
                    <Button onClick={() => setConfirmOpen(true)}>
                        <IconRefresh className="size-4" />
                        {t.sent.detail.resend}
                    </Button>
                }
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4">
                    <Card title={t.sent.detail.info} bodyClassName="px-5 py-3">
                        <div className="divide-y divide-slate-100">
                            <InfoRow
                                label={t.sent.columns.recipient}
                                value={
                                    <span dir="ltr">{email.to_email}</span>
                                }
                            />
                            <InfoRow label={t.sent.columns.company} value={email.company.name} />
                            <InfoRow label={t.sent.columns.job} value={email.job_post?.title ?? '—'} />
                            <InfoRow label={t.sent.columns.sentAt} value={formatDateTime(email.sent_at)} />
                            <InfoRow label={t.jobs.columns.status} value={<StatusBadge status={email.status} />} />
                            <InfoRow label={t.queue.attempts} value={email.attempts} />
                        </div>
                        {email.error && (
                            <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" dir="ltr">
                                {email.error}
                            </p>
                        )}
                    </Card>

                    <Card title={t.sent.detail.opensTimeline} bodyClassName="p-5">
                        {email.opens.length === 0 ? (
                            <p className="text-sm text-slate-400">{t.sent.detail.noOpens}</p>
                        ) : (
                            <ul className="space-y-3">
                                {email.opens.map((open, i) => (
                                    <li key={`${open.opened_at}-${i}`} className="flex items-start gap-2.5">
                                        <span className="mt-1 flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                                            <IconEye className="size-3.5" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-slate-700">
                                                {formatDateTime(open.opened_at)}
                                            </p>
                                            <p className="truncate text-xs text-slate-400" dir="ltr">
                                                {[open.ip, open.user_agent].filter(Boolean).join(' · ') || '—'}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <Card title={t.sent.detail.body} className="lg:col-span-2" bodyClassName="p-0">
                    <iframe
                        title={t.sent.detail.body}
                        sandbox=""
                        srcDoc={email.body_html}
                        className="h-[560px] w-full rounded-b-xl bg-white"
                    />
                </Card>
            </div>

            <ConfirmDialog
                open={confirmOpen}
                title={t.sent.detail.resendTitle}
                message={t.sent.detail.resendConfirm(email.to_email)}
                loading={requeue.isPending}
                onConfirm={onResend}
                onClose={() => setConfirmOpen(false)}
            />
        </div>
    );
}
