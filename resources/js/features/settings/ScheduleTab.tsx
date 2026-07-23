import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { errorMessage } from '@/api/client';
import { useSaveSchedule } from '@/api/queries/settings';
import type { ScheduleSettings } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { useToast } from '@/components/ui/Toast';
import { Toggle } from '@/components/ui/Toggle';
import { t } from '@/lib/i18n';

const errs = t.settings.schedule.errors;

const scheduleSchema = z
    .object({
        min_interval: z.number(errs.required).int().min(1, errs.required),
        max_interval: z.number(errs.required).int().min(1, errs.required),
        daily_cap: z.number(errs.required).int().min(1, errs.capRange).max(100, errs.capRange),
        window_start: z.string().min(1, errs.required),
        window_end: z.string().min(1, errs.required),
        days: z.array(z.number()).min(1, errs.pickDay),
        timezone: z.string().min(1, errs.required),
        auto_scrape_enabled: z.boolean(),
        auto_scrape_time: z.string(),
    })
    .refine((v) => v.min_interval < v.max_interval, {
        message: errs.minLtMax,
        path: ['max_interval'],
    });

type ScheduleForm = z.infer<typeof scheduleSchema>;

export function ScheduleTab({ schedule }: { schedule: ScheduleSettings }) {
    const saveSchedule = useSaveSchedule();
    const { toast } = useToast();

    const {
        register,
        handleSubmit,
        watch,
        setValue,
        formState: { errors },
    } = useForm<ScheduleForm>({
        resolver: zodResolver(scheduleSchema),
        defaultValues: schedule,
    });

    const values = watch();

    const toggleDay = (day: number) => {
        const current = new Set(values.days);
        if (current.has(day)) {
            current.delete(day);
        } else {
            current.add(day);
        }
        setValue('days', [...current].sort((a, b) => a - b), {
            shouldValidate: true,
            shouldDirty: true,
        });
    };

    const onSubmit = handleSubmit((data) => {
        saveSchedule.mutate(data, {
            onSuccess: () => toast(t.common.saved),
            onError: (err) => toast(errorMessage(err), 'error'),
        });
    });

    const summaryReady =
        Number.isFinite(values.min_interval) &&
        Number.isFinite(values.max_interval) &&
        Number.isFinite(values.daily_cap) &&
        values.window_start &&
        values.window_end;

    return (
        <form onSubmit={onSubmit}>
            <Card title={t.settings.tabs.schedule} bodyClassName="p-5 space-y-5">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label={t.settings.schedule.minInterval}
                        type="number"
                        min={1}
                        error={errors.min_interval?.message}
                        {...register('min_interval', { valueAsNumber: true })}
                    />
                    <Input
                        label={t.settings.schedule.maxInterval}
                        type="number"
                        min={1}
                        error={errors.max_interval?.message}
                        {...register('max_interval', { valueAsNumber: true })}
                    />
                    <Input
                        label={t.settings.schedule.dailyCap}
                        type="number"
                        min={1}
                        max={100}
                        error={errors.daily_cap?.message}
                        {...register('daily_cap', { valueAsNumber: true })}
                    />
                    <Input
                        label={t.settings.schedule.windowStart}
                        type="time"
                        dir="ltr"
                        error={errors.window_start?.message}
                        {...register('window_start')}
                    />
                    <Input
                        label={t.settings.schedule.windowEnd}
                        type="time"
                        dir="ltr"
                        error={errors.window_end?.message}
                        {...register('window_end')}
                    />
                    <Input
                        label={t.settings.schedule.timezone}
                        dir="ltr"
                        error={errors.timezone?.message}
                        {...register('timezone')}
                    />
                </div>

                <div>
                    <p className="mb-2 text-xs font-semibold text-slate-600">
                        {t.settings.schedule.daysLabel}
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {t.weekOrder.map((day) => {
                            const active = values.days.includes(day);
                            return (
                                <button
                                    key={day}
                                    type="button"
                                    onClick={() => toggleDay(day)}
                                    className={`rounded-full border px-3.5 py-1.5 text-xs font-bold transition-colors ${
                                        active
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {t.days[day]}
                                </button>
                            );
                        })}
                    </div>
                    {errors.days && <p className="mt-1 text-xs text-red-600">{errors.days.message}</p>}
                </div>

                <div className="flex flex-wrap items-end gap-4 border-t border-slate-100 pt-4">
                    <Toggle
                        checked={values.auto_scrape_enabled}
                        onChange={(v) =>
                            setValue('auto_scrape_enabled', v, { shouldValidate: true, shouldDirty: true })
                        }
                        label={t.settings.schedule.autoScrape}
                    />
                    {values.auto_scrape_enabled && (
                        <Input
                            label={t.settings.schedule.autoScrapeTime}
                            type="time"
                            dir="ltr"
                            className="w-40"
                            {...register('auto_scrape_time')}
                        />
                    )}
                </div>

                {summaryReady && (
                    <p className="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-800">
                        {t.settings.schedule.summary(
                            values.min_interval,
                            values.max_interval,
                            values.window_start,
                            values.window_end,
                            values.daily_cap,
                        )}
                    </p>
                )}

                <Button type="submit" loading={saveSchedule.isPending}>
                    {t.common.save}
                </Button>
            </Card>
        </form>
    );
}
