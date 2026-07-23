import { useRef, useState } from 'react';
import { errorMessage } from '@/api/client';
import { useSaveProfile, useUploadCv } from '@/api/queries/settings';
import type { CvInfo, ProfileSettings } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { useToast } from '@/components/ui/Toast';
import { IconDownload, IconFile, IconUpload } from '@/components/ui/icons';
import { formatFileSize } from '@/lib/format';
import { t } from '@/lib/i18n';

export function ProfileTab({ profile, cv }: { profile: ProfileSettings; cv: CvInfo }) {
    const [form, setForm] = useState<ProfileSettings>({ ...profile });
    const [file, setFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const saveProfile = useSaveProfile();
    const uploadCv = useUploadCv();
    const { toast } = useToast();

    const set = (key: keyof ProfileSettings, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const onSave = () => {
        saveProfile.mutate(form, {
            onSuccess: () => toast(t.common.saved),
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    };

    const onUpload = () => {
        if (!file) return;
        uploadCv.mutate(file, {
            onSuccess: () => {
                toast(t.settings.profile.uploadedOk);
                setFile(null);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    };

    return (
        <div className="space-y-5">
            <Card title={t.settings.profile.section} bodyClassName="p-5 space-y-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label={t.settings.profile.myName}
                        value={form.my_name}
                        onChange={(e) => set('my_name', e.target.value)}
                    />
                    <Input
                        label={t.settings.profile.myTitle}
                        value={form.my_title}
                        onChange={(e) => set('my_title', e.target.value)}
                    />
                    <Input
                        label={t.settings.profile.whatsapp}
                        dir="ltr"
                        value={form.whatsapp}
                        onChange={(e) => set('whatsapp', e.target.value)}
                    />
                    <Input
                        label={t.settings.profile.phone}
                        dir="ltr"
                        value={form.phone}
                        onChange={(e) => set('phone', e.target.value)}
                    />
                    <Input
                        label={t.settings.profile.portfolio}
                        dir="ltr"
                        value={form.portfolio}
                        onChange={(e) => set('portfolio', e.target.value)}
                    />
                    <Input
                        label={t.settings.profile.github}
                        dir="ltr"
                        value={form.github}
                        onChange={(e) => set('github', e.target.value)}
                    />
                    <Input
                        label={t.settings.profile.linkedin}
                        dir="ltr"
                        value={form.linkedin}
                        onChange={(e) => set('linkedin', e.target.value)}
                    />
                </div>
                <Button onClick={onSave} loading={saveProfile.isPending}>
                    {t.common.save}
                </Button>
            </Card>

            <Card title={t.settings.profile.cvSection} bodyClassName="p-5 space-y-4">
                {cv.uploaded ? (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <IconFile className="size-5 text-indigo-500" />
                        <div className="grow">
                            <p className="text-sm font-bold text-slate-700" dir="ltr">
                                {cv.original_name}
                            </p>
                            <p className="text-xs text-slate-400">{formatFileSize(cv.size)}</p>
                        </div>
                        <a
                            href="/api/settings/cv"
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:underline"
                        >
                            <IconDownload className="size-4" />
                            {t.settings.profile.download}
                        </a>
                    </div>
                ) : (
                    <p className="text-sm text-slate-400">{t.settings.profile.noCv}</p>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <IconFile className="size-4" />
                        {file ? file.name : t.settings.profile.choose}
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="application/pdf,.pdf"
                            className="hidden"
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                    </label>
                    <Button onClick={onUpload} disabled={!file} loading={uploadCv.isPending}>
                        <IconUpload className="size-4" />
                        {t.settings.profile.upload}
                    </Button>
                </div>
            </Card>
        </div>
    );
}
