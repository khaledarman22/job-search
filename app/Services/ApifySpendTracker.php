<?php

namespace App\Services;

use App\Enums\RunOrigin;
use App\Models\ApifyRun;
use App\Support\SettingsRepository;

/**
 * يتتبع إنفاق Apify من تشغيلات النظام (system) بعد آخر «تصفير».
 * لما الإنفاق يتخطى السقف، الاسكراب التلقائي يتوقف لحد ما المستخدم
 * يغيّر التوكن (تغيير التوكن = تصفير + استئناف).
 */
class ApifySpendTracker
{
    public function __construct(private SettingsRepository $settings) {}

    public function cap(): float
    {
        return (float) $this->settings->get('apify_spend_cap', '4');
    }

    /** كل التشغيلات الأحدث من هذا الـ id بتُحسب في الإنفاق الحالي */
    public function baselineId(): int
    {
        return $this->settings->getInt('apify_spend_baseline_id', 0);
    }

    public function spent(): float
    {
        return (float) ApifyRun::where('origin', RunOrigin::System)
            ->where('id', '>', $this->baselineId())
            ->sum('usage_usd');
    }

    public function remaining(): float
    {
        return max(0.0, $this->cap() - $this->spent());
    }

    public function capReached(): bool
    {
        return $this->cap() > 0 && $this->spent() >= $this->cap();
    }

    public function isPaused(): bool
    {
        return $this->settings->getBool('apify_scrape_paused');
    }

    public function pause(string $reason): void
    {
        $this->settings->set('apify_scrape_paused', true);
        $this->settings->set('apify_scrape_paused_reason', $reason);
    }

    /** تغيير حساب Apify → تصفير العدّاد واستئناف الاسكراب التلقائي */
    public function reset(): void
    {
        $this->settings->set('apify_spend_baseline_id', (int) (ApifyRun::max('id') ?? 0));
        $this->settings->set('apify_scrape_paused', false);
        $this->settings->forget('apify_scrape_paused_reason');
    }

    /**
     * بوابة الاسكراب التلقائي — ترجّع true لو مسموح.
     * لو السقف اتخطى، توقف الاسكراب تلقائيًا وتسجّل السبب.
     */
    public function allowScrape(): bool
    {
        if ($this->isPaused()) {
            return false;
        }

        if ($this->capReached()) {
            $this->pause(sprintf(
                'تجاوز حد إنفاق Apify: $%s من $%s. غيّر حساب/توكن Apify لاستئناف الاسكراب التلقائي.',
                number_format($this->spent(), 2),
                number_format($this->cap(), 2),
            ));

            return false;
        }

        return true;
    }

    /** الحالة المعروضة في الداشبورد */
    public function state(): array
    {
        return [
            'spent' => round($this->spent(), 2),
            'cap' => round($this->cap(), 2),
            'remaining' => round($this->remaining(), 2),
            'cap_reached' => $this->capReached(),
            'paused' => $this->isPaused(),
            'paused_reason' => $this->settings->get('apify_scrape_paused_reason'),
        ];
    }
}
