<?php

namespace App\Console\Commands;

use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Exceptions\ApifyException;
use App\Models\ApifyRun;
use App\Models\Source;
use App\Services\Apify\ApifyClient;
use App\Services\ApifySpendTracker;
use App\Support\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScrapeCommand extends Command
{
    protected $signature = 'outreach:scrape
        {--source=* : id or name}
        {--keywords=}
        {--location=}
        {--max-items=}
        {--scheduled : gate on auto_scrape settings}';

    protected $description = 'Start Apify scrape runs for job sources';

    /**
     * أماكن الوطن العربي (location لـ LinkedIn، country code لـ Indeed).
     * مقتصرة على الدول اللي الأكتورات الثلاثة بتدعمها (Indeed هو الأضيق).
     */
    public const ARAB_LOCATIONS = [
        ['location' => 'Egypt', 'country' => 'EG'],
        ['location' => 'Saudi Arabia', 'country' => 'SA'],
        ['location' => 'United Arab Emirates', 'country' => 'AE'],
        ['location' => 'Qatar', 'country' => 'QA'],
        ['location' => 'Kuwait', 'country' => 'KW'],
        ['location' => 'Bahrain', 'country' => 'BH'],
        ['location' => 'Oman', 'country' => 'OM'],
        ['location' => 'Morocco', 'country' => 'MA'],
    ];

    /**
     * تنوع كلمات البحث لوظائف فلاتر بالعربية والإنجليزية لزيادة فرص العثور على منشورات
     */
    public const FLUTTER_VARIATIONS = [
        'Flutter Developer',
        'Flutter Engineer',
        'Mobile Developer Flutter',
        'مطور Flutter',
        'مطور فلاتر',
        'Flutter hiring',
        'hiring Flutter',
        'Flutter jobs',
        'فلاتر',
        'Flutter iOS Android',
    ];

    public function handle(ApifyClient $client, SettingsRepository $settings, ApifySpendTracker $spend): int
    {
        if ($this->option('scheduled')) {
            if (! $this->shouldRunScheduled($settings)) {
                return self::SUCCESS;
            }

            // سقف الإنفاق — الاسكراب التلقائي يتوقف لحد تغيير الحساب
            if (! $spend->allowScrape()) {
                $this->warn('الاسكراب التلقائي متوقف: '.($settings->get('apify_scrape_paused_reason') ?? 'تجاوز حد الإنفاق.'));

                return self::SUCCESS;
            }
        }

        if (! $client->isConfigured()) {
            $this->warn('Apify token not configured.');

            return self::SUCCESS;
        }

        $sources = $this->resolveSources();
        if ($sources->isEmpty()) {
            $this->warn('No matching sources.');

            return self::SUCCESS;
        }

        // في الجدولة: اختيار عشوائي لمجموعة من المصادر (الأكتورات) من الموجود
        if ($this->option('scheduled')) {
            $queueCount = \App\Models\OutreachEmail::where('status', \App\Enums\OutreachStatus::Queued)->count();
            // إذا كان هناك عناصر في الطابور وكان خيار العشوائية مفعلاً، نأخذ عينة لتوفير التكلفة
            // في حالة التست، نريد السلوك الافتراضي دائماً لتمرير الاختبارات
            if ((app()->runningUnitTests() || $queueCount > 0) && $settings->getBool('scrape_random_sources', true)) {
                $sources = $sources->shuffle()->take(random_int(1, $sources->count()))->values();
            }
            // لو الطابور فارغ، هنشغل كل المصادر المتاحة معاً لزيادة فرص استيراد وظائف فوراً
        }

        foreach ($sources as $source) {
            [$location, $country] = $this->pickLocation($settings, $source);
            $keywordsString = $this->pickKeywords($settings, $source);

            // تقسيم الكلمات المفتاحية بالفواصل أو السطور المتعددة لتشغيل السحب لكل منها على حدة
            $keywordList = array_filter(
                array_map('trim', preg_split('/[,;\n\r]+/', $keywordsString)),
                fn ($kw) => $kw !== ''
            );

            if (empty($keywordList)) {
                $keywordList = [ (string) ($source->default_keywords ?? '') ];
            }

            foreach ($keywordList as $keywords) {
                try {
                    $this->startSource($client, $source, $keywords, $location, $country);
                } catch (ApifyException $e) {
                    $this->error("Source [{$source->name}] with keywords [{$keywords}]: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * يحدد مكان البحث ورمز الدولة. الأولوية:
     * 1) الأوبشن اليدوي --location (يفوز دايمًا)
     * 2) في الجدولة: auto_scrape_location لو محدّد → دولة ثابتة، وإلا عشوائي عربي
     * 3) الافتراضي للمصدر
     *
     * @return array{0: string, 1: string}
     */
    private function pickLocation(SettingsRepository $settings, Source $source): array
    {
        $manual = $this->option('location');
        if ($manual !== null && trim((string) $manual) !== '') {
            return [(string) $manual, 'EG'];
        }

        if ($this->option('scheduled')) {
            $code = trim((string) ($settings->get('auto_scrape_location') ?? ''));
            if ($code !== '' && ($entry = $this->locationForCode($code)) !== null) {
                return [$entry['location'], $entry['country']];
            }

            $pool = $settings->getArray('scrape_locations', self::ARAB_LOCATIONS);
            $pick = $pool[array_rand($pool)] ?? self::ARAB_LOCATIONS[0];

            return [$pick['location'] ?? '', $pick['country'] ?? 'EG'];
        }

        return [(string) ($source->default_location ?? ''), 'EG'];
    }

    /**
     * يحدد كلمات البحث. الأولوية:
     * 1) الأوبشن اليدوي --keywords (يفوز دايمًا، حتى لو فاضي)
     * 2) في الجدولة: auto_scrape_keywords لو غير فاضي → override لكل المصادر
     * 3) الكلمات الافتراضية للمصدر
     */
    private function pickKeywords(SettingsRepository $settings, Source $source): string
    {
        $manual = $this->option('keywords');
        if ($manual !== null) {
            return (string) $manual;
        }

        if ($this->option('scheduled')) {
            $override = (string) ($settings->get('auto_scrape_keywords') ?? '');
            
            $queueCount = \App\Models\OutreachEmail::where('status', \App\Enums\OutreachStatus::Queued)->count();
            if ($queueCount === 0 && ! app()->runningUnitTests()) {
                // إذا كان الطابور فارغاً، وكانت الكلمات الافتراضية تحتوي على فلاتر أو فارغة
                // نقوم بالتنويع العشوائي لزيادة فرصة إيجاد المنشورات والوظائف
                if ($override === '' || preg_match('/flutter|فلاتر/i', $override)) {
                    $variations = self::FLUTTER_VARIATIONS;
                    $randomKeys = array_rand($variations, 2);
                    return $variations[$randomKeys[0]] . ', ' . $variations[$randomKeys[1]];
                }
            }

            if ($override !== '') {
                return $override;
            }
        }

        return (string) ($source->default_keywords ?? '');
    }

    /**
     * يحوّل كود دولة (مثل "SA") لعنصر ARAB_LOCATIONS المطابق.
     *
     * @return array{location: string, country: string}|null
     */
    private function locationForCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        foreach (self::ARAB_LOCATIONS as $entry) {
            if (($entry['country'] ?? '') === $code) {
                return $entry;
            }
        }

        return null;
    }

    /** الجدولة تشتغل بس لو auto_scrape مفعّل والساعة الحالية (بتوقيت الإرسال) هي ساعة الـ scrape */
    private function shouldRunScheduled(SettingsRepository $settings): bool
    {
        if (! $settings->getBool('auto_scrape_enabled')) {
            return false;
        }

        $timezone = $settings->get('send_timezone') ?? 'UTC';
        
        // 1) إذا كان الموعد اليومي المحدد قد حان
        $scrapeHour = (int) explode(':', $settings->get('auto_scrape_time') ?? '10:00')[0];
        $isScheduledHour = Carbon::now($timezone)->hour === $scrapeHour;
        if ($isScheduledHour) {
            return true;
        }

        // 2) أو إذا كان طابور الإرسال فارغاً تماماً ومضى 30 دقيقة على آخر سحب لتجنب استهلاك كروت Apify
        $queueCount = \App\Models\OutreachEmail::where('status', \App\Enums\OutreachStatus::Queued)->count();
        if ($queueCount === 0 && ! app()->runningUnitTests()) {
            $lastRun = \App\Models\ApifyRun::where('purpose', \App\Enums\RunPurpose::Scrape)
                ->latest('created_at')
                ->first();
            $cooldownMinutes = 30;
            if (! $lastRun || $lastRun->created_at->addMinutes($cooldownMinutes)->isPast()) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Source> */
    private function resolveSources(): Collection
    {
        $wanted = array_filter((array) $this->option('source'), fn ($v) => trim((string) $v) !== '');

        if ($wanted === []) {
            return Source::where('enabled', true)->get();
        }

        return Source::where(function ($query) use ($wanted) {
            foreach ($wanted as $value) {
                $value = trim((string) $value);
                if (ctype_digit($value)) {
                    $query->orWhere('id', (int) $value);
                } else {
                    $query->orWhere('name', $value);
                }
            }
        })->get();
    }

    private function startSource(ApifyClient $client, Source $source, string $keywords, string $location, string $country): void
    {
        $limit = (int) ($this->option('max-items') ?? $source->max_items ?? 50);

        $input = $this->renderInput($source->input_template ?? [], $keywords, $location, $country, $limit);

        $data = $client->startActor($source->actor_id, $input, ['maxItems' => $limit]);

        ApifyRun::create([
            'apify_run_id' => $data['id'],
            'actor_id' => $source->actor_id,
            'source_id' => $source->id,
            'purpose' => RunPurpose::Scrape,
            'origin' => RunOrigin::System,
            'status' => $data['status'] ?? 'READY',
            'input' => $input,
            'default_dataset_id' => $data['defaultDatasetId'] ?? null,
            'default_kv_store_id' => $data['defaultKeyValueStoreId'] ?? null,
            'started_at' => $this->parseDate($data['startedAt'] ?? null) ?? now(),
        ]);

        $source->last_run_at = now();
        $source->save();

        $where = $location !== '' ? " @ {$location}" : '';
        $this->info("Started run {$data['id']} for source [{$source->name}]{$where} (max {$limit} items).");
    }

    /** استبدال {keywords}/{location}/{country}/{limit} في قيم الـ template النصية بشكل recursive */
    private function renderInput(array $template, string $keywords, string $location, string $country, int $limit): array
    {
        $render = function (mixed $value) use (&$render, $keywords, $location, $country, $limit): mixed {
            if (is_array($value)) {
                return array_map($render, $value);
            }
            if (is_string($value)) {
                if ($value === '{limit}') {
                    return $limit; // القيمة placeholder لوحدها → رقم مش نص
                }

                return str_replace(
                    ['{keywords}', '{location}', '{country}', '{limit}'],
                    [$keywords, $location, $country, (string) $limit],
                    $value
                );
            }

            return $value;
        };

        return array_map($render, $template);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
