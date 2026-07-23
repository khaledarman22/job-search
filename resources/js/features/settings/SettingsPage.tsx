import { useState } from 'react';
import { useSettings } from '@/api/queries/settings';
import { PageHeader } from '@/components/shared/PageHeader';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs } from '@/components/ui/Tabs';
import { ApifyTab } from '@/features/settings/ApifyTab';
import { ProfileTab } from '@/features/settings/ProfileTab';
import { ScheduleTab } from '@/features/settings/ScheduleTab';
import { SmtpTab } from '@/features/settings/SmtpTab';
import { TemplateTab } from '@/features/settings/TemplateTab';
import { t } from '@/lib/i18n';

export function SettingsPage() {
    const [tab, setTab] = useState('apify');
    const { data: settings, isLoading } = useSettings();

    return (
        <div>
            <PageHeader title={t.settings.title} description={t.settings.subtitle} />

            <Tabs
                className="mb-5"
                value={tab}
                onChange={setTab}
                items={[
                    { key: 'apify', label: t.settings.tabs.apify },
                    { key: 'smtp', label: t.settings.tabs.smtp },
                    { key: 'template', label: t.settings.tabs.template },
                    { key: 'schedule', label: t.settings.tabs.schedule },
                    { key: 'profile', label: t.settings.tabs.profile },
                ]}
            />

            {isLoading || !settings ? (
                <div className="space-y-4">
                    <Skeleton className="h-40 w-full" />
                    <Skeleton className="h-64 w-full" />
                </div>
            ) : tab === 'apify' ? (
                <ApifyTab apify={settings.apify} />
            ) : tab === 'smtp' ? (
                <SmtpTab smtp={settings.smtp} />
            ) : tab === 'template' ? (
                <TemplateTab template={settings.template} cv={settings.cv} />
            ) : tab === 'schedule' ? (
                <ScheduleTab schedule={settings.schedule} />
            ) : (
                <ProfileTab profile={settings.profile} cv={settings.cv} />
            )}
        </div>
    );
}
