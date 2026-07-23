<?php

namespace App\Services\Apify;

use App\Enums\ContactStatus;
use App\Enums\DiscoveredVia;
use App\Enums\EnrichmentStatus;
use App\Enums\JobPostStatus;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactJobSighting;
use App\Models\JobPost;
use App\Models\Source;
use App\Services\ContactExtractor;
use App\Support\SettingsRepository;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class RunImporter
{
    private const PAGE_SIZE = 500;

    /** كل الوظائف والشركات + جهات الاتصال (الافتراضي) */
    public const MODE_ALL = 'all';

    /** الوظائف اللي طلع منها إيميل فقط — الباقي يتجاهل تمامًا */
    public const MODE_WITH_EMAIL = 'with_email';

    /** الوظائف والشركات للتصفح بس — بدون أي جهة اتصال */
    public const MODE_COMPANIES_ONLY = 'companies_only';

    public const MODES = [self::MODE_ALL, self::MODE_WITH_EMAIL, self::MODE_COMPANIES_ONLY];

    public function __construct(
        private ApifyClient $client,
        private ContactExtractor $extractor,
        private SettingsRepository $settings,
    ) {}

    /** يستورد نتائج الـ run لجداول الوظائف والشركات، ويرجّع عدد الوظائف الجديدة */
    public function import(ApifyRun $run): int
    {
        $source = $run->source;
        if (! $source instanceof Source) {
            throw new InvalidArgumentException("ApifyRun #{$run->id} has no source; cannot import.");
        }
        if (! $run->default_dataset_id) {
            throw new InvalidArgumentException("ApifyRun #{$run->id} has no dataset id; cannot import.");
        }

        $fieldMap = $source->field_map ?? [];
        // نقرا وضع الاستيراد مرة واحدة لكل عملية import
        $mode = $this->settings->get('import_mode', self::MODE_ALL);
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::MODE_ALL;
        }

        $created = 0;
        $counted = 0;
        $offset = 0;

        do {
            $items = $this->client->getDatasetItems($run->default_dataset_id, $offset, self::PAGE_SIZE);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $counted++;
                if ($this->importItem($run, $source, $fieldMap, $item, $mode)) {
                    $created++;
                }
            }

            $offset += self::PAGE_SIZE;
        } while (count($items) === self::PAGE_SIZE);

        try {
            $dataset = $this->client->getDataset($run->default_dataset_id);
            $run->item_count = $dataset['itemCount'] ?? $counted;
        } catch (\Throwable) {
            $run->item_count = $counted;
        }

        $run->imported_at = now();
        $run->save();

        return $created;
    }

    /** يرجّع true لو اتعملت وظيفة جديدة */
    private function importItem(ApifyRun $run, Source $source, array $fieldMap, array $item, string $mode): bool
    {
        $mapped = [];
        foreach ($fieldMap as $key => $path) {
            $mapped[$key] = $path ? data_get($item, $path) : null;
        }

        $url = trim((string) ($mapped['url'] ?? ''));
        $title = trim((string) ($mapped['title'] ?? ''));
        if ($url === '' || $title === '') {
            return false;
        }

        $urlHash = JobPost::hashUrl($url);

        $existing = JobPost::where('source_id', $source->id)->where('url_hash', $urlHash)->first();
        if ($existing) {
            $existing->last_seen_at = now();
            $existing->save();

            return false;
        }

        // قرار الإيميل قبل إنشاء أي شركة/وظيفة — عشان وضع with_email يتجاهل العنصر تمامًا
        [$emails, $phone] = $this->candidateContacts($mapped, $title);

        if ($mode === self::MODE_WITH_EMAIL && empty($emails)) {
            return false;
        }

        $company = $this->resolveCompany($mapped);

        $job = JobPost::create([
            'source_id' => $source->id,
            'company_id' => $company?->id,
            'apify_run_id' => $run->id,
            'external_id' => $this->stringOrNull($mapped['external_id'] ?? null),
            'url' => $url,
            'url_hash' => $urlHash,
            'title' => $title,
            'location' => $this->stringOrNull($mapped['location'] ?? null),
            'salary' => $this->stringOrNull($mapped['salary'] ?? null),
            'description' => $this->stringOrNull($mapped['description'] ?? null),
            'posted_at' => $this->parseDate($mapped['posted_at'] ?? null),
            'last_seen_at' => now(),
            'raw' => $item,
            'status' => JobPostStatus::New,
        ]);

        // companies_only: الوظيفة والشركة بس — بدون أي جهة اتصال، فالحالة تفضل New
        if ($mode !== self::MODE_COMPANIES_ONLY) {
            $this->attachContacts($company, $job, $emails, $phone);

            if ($company && $company->contacts()->whereNotNull('email')->where('email', '!=', '')->exists()) {
                $job->status = JobPostStatus::Enriched;
                $job->save();
            }
        }

        return true;
    }

    /**
     * يحسب الإيميلات/التليفون المرشّحة من البيانات المُخرَّطة قبل إنشاء أي صف:
     * يفضّل الإيميل الجاهز من الأكتور، وإلا يستخرج من نص العنوان + الوصف (إثراء مجاني).
     *
     * ملاحظة: الشركة لسه مش متعمولة في المرحلة دي، فبنمرّر companyDomain = null
     * للـ extractor. ده بيأثر على *ترتيب* الإيميلات (ranking) بس، مش على وجودها
     * من عدمه — وده مقبول مقابل إننا نقدر نتجاهل العنصر بالكامل في وضع with_email.
     *
     * @return array{0: string[], 1: ?string}
     */
    private function candidateContacts(array $mapped, string $title): array
    {
        $mappedEmail = strtolower(trim((string) ($mapped['email'] ?? '')));
        $mappedPhone = $this->stringOrNull($mapped['phone'] ?? null);
        $description = $this->stringOrNull($mapped['description'] ?? null);

        if ($mappedEmail !== '') {
            // إيميل جاهز من الأكتور — نستخدمه زي ما هو
            return [[$mappedEmail], $mappedPhone];
        }

        // استخراج من الوصف (والعنوان) — بحث نصي
        $haystack = trim($title.' '.($description ?? ''));
        $emails = $this->extractor->emails($haystack, null);
        $phones = $this->extractor->phones($description);

        return [$emails, $mappedPhone ?? ($phones[0] ?? null)];
    }

    /**
     * يربط جهات الاتصال المحسوبة مسبقًا بالوظيفة.
     *
     * @param  string[]  $emails  الأفضل أولًا
     */
    private function attachContacts(?Company $company, JobPost $job, array $emails, ?string $phone): void
    {
        if (empty($emails)) {
            // مفيش إيميل — نعمل جهة اتصال بالتليفون فقط (لو فيه) للعرض كـ«واتساب فقط»
            if ($phone !== null && $company) {
                Contact::create([
                    'company_id' => $company->id,
                    'job_post_id' => $job->id,
                    'phone' => $phone,
                    'discovered_via' => DiscoveredVia::JobPosting,
                    'status' => ContactStatus::New,
                ]);
            }

            return;
        }

        $enrichedCompany = false;
        foreach ($emails as $index => $email) {
            $existing = Contact::where('email', $email)->first();

            if ($existing) {
                ContactJobSighting::firstOrCreate(
                    ['contact_id' => $existing->id, 'job_post_id' => $job->id],
                    ['seen_at' => now()],
                );

                continue;
            }

            if (! $company) {
                continue;
            }

            Contact::create([
                'company_id' => $company->id,
                'job_post_id' => $job->id,
                'email' => $email,
                // التليفون يروح لأفضل إيميل (الأول) بس
                'phone' => $index === 0 ? $phone : null,
                'is_alternate' => $index > 0,
                'discovered_via' => DiscoveredVia::JobPosting,
                'status' => ContactStatus::New,
            ]);
            $enrichedCompany = true;
        }

        // إيميل جاي من الإعلان → مفيش داعي نعمل scraping لموقع الشركة
        if ($enrichedCompany && $company) {
            $company->enrichment_status = EnrichmentStatus::Enriched;
            $company->enriched_at = now();
            $company->save();
        }
    }

    private function resolveCompany(array $mapped): ?Company
    {
        $name = trim((string) ($mapped['company_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $normalized = Company::normalizeName($name);
        $website = $this->stringOrNull($mapped['company_website'] ?? null);

        $company = Company::where('normalized_name', $normalized)->first();

        if (! $company) {
            return Company::create([
                'name' => $name,
                'normalized_name' => $normalized,
                'website' => $website,
                'domain' => Company::extractDomain($website),
                'enrichment_status' => EnrichmentStatus::Pending,
            ]);
        }

        if ($website && ! $company->website) {
            $company->website = $website;
            $company->domain = $company->domain ?? Company::extractDomain($website);
            $company->save();
        }

        return $company;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
