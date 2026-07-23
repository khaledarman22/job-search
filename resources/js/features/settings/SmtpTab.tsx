import { useState } from 'react';
import { errorMessage } from '@/api/client';
import { useSaveSmtp, useSmtpTest } from '@/api/queries/settings';
import type { SmtpEncryption, SmtpSettings } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { IconAlert, IconSend } from '@/components/ui/icons';
import { t } from '@/lib/i18n';

export function SmtpTab({ smtp }: { smtp: SmtpSettings }) {
    const [form, setForm] = useState({
        host: smtp.host,
        port: String(smtp.port || ''),
        encryption: smtp.encryption,
        username: smtp.username,
        password: '',
        from_email: smtp.from_email,
        from_name: smtp.from_name,
    });
    const [saveError, setSaveError] = useState<string | null>(null);
    const [testTo, setTestTo] = useState('');
    const [testError, setTestError] = useState<string | null>(null);

    const saveSmtp = useSaveSmtp();
    const smtpTest = useSmtpTest();
    const { toast } = useToast();

    const set = (key: keyof typeof form, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const onSave = () => {
        setSaveError(null);
        saveSmtp.mutate(
            {
                host: form.host,
                port: Number(form.port),
                encryption: form.encryption,
                username: form.username,
                password: form.password || undefined,
                from_email: form.from_email,
                from_name: form.from_name,
            },
            {
                onSuccess: () => {
                    toast(t.common.saved);
                    setForm((prev) => ({ ...prev, password: '' }));
                },
                onError: (err) => setSaveError(errorMessage(err)),
            },
        );
    };

    const onTest = () => {
        setTestError(null);
        smtpTest.mutate(testTo, {
            onSuccess: () => toast(t.settings.smtp.testOk),
            onError: (err) => setTestError(errorMessage(err)),
        });
    };

    return (
        <div className="space-y-5">
            <Card title={t.settings.smtp.section} bodyClassName="p-5 space-y-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label={t.settings.smtp.host}
                        dir="ltr"
                        value={form.host}
                        onChange={(e) => set('host', e.target.value)}
                    />
                    <Input
                        label={t.settings.smtp.port}
                        type="number"
                        dir="ltr"
                        value={form.port}
                        onChange={(e) => set('port', e.target.value)}
                    />
                    <Select
                        label={t.settings.smtp.encryption}
                        value={form.encryption}
                        onChange={(e) => set('encryption', e.target.value as SmtpEncryption)}
                    >
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">{t.settings.smtp.none}</option>
                    </Select>
                    <Input
                        label={t.settings.smtp.username}
                        dir="ltr"
                        autoComplete="off"
                        value={form.username}
                        onChange={(e) => set('username', e.target.value)}
                    />
                    <Input
                        label={t.settings.smtp.password}
                        type="password"
                        dir="ltr"
                        autoComplete="new-password"
                        placeholder={smtp.password_set ? '••••••••' : ''}
                        hint={smtp.password_set ? t.settings.smtp.passwordHint : undefined}
                        value={form.password}
                        onChange={(e) => set('password', e.target.value)}
                    />
                    <Input
                        label={t.settings.smtp.fromEmail}
                        type="email"
                        dir="ltr"
                        value={form.from_email}
                        onChange={(e) => set('from_email', e.target.value)}
                    />
                    <Input
                        label={t.settings.smtp.fromName}
                        value={form.from_name}
                        onChange={(e) => set('from_name', e.target.value)}
                    />
                </div>
                {saveError && (
                    <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
                        <IconAlert className="size-4 shrink-0" />
                        {saveError}
                    </div>
                )}
                <Button onClick={onSave} loading={saveSmtp.isPending}>
                    {t.common.save}
                </Button>
            </Card>

            <Card title={t.settings.smtp.testSection} bodyClassName="p-5 space-y-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Input
                        label={t.settings.smtp.testTo}
                        type="email"
                        dir="ltr"
                        value={testTo}
                        onChange={(e) => setTestTo(e.target.value)}
                        className="w-72"
                    />
                    <Button
                        variant="secondary"
                        onClick={onTest}
                        loading={smtpTest.isPending}
                        disabled={!testTo}
                    >
                        <IconSend className="size-4" />
                        {t.settings.smtp.testSend}
                    </Button>
                </div>
                {testError && (
                    <pre
                        dir="ltr"
                        className="overflow-x-auto rounded-lg border border-red-200 bg-red-50 p-3 text-xs leading-snug whitespace-pre-wrap text-red-700"
                    >
                        {testError}
                    </pre>
                )}
            </Card>
        </div>
    );
}
