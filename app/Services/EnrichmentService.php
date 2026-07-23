<?php

namespace App\Services;

use App\Enums\ContactStatus;
use App\Enums\DiscoveredVia;
use App\Enums\EnrichmentStatus;
use App\Enums\JobPostStatus;
use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactJobSighting;
use App\Models\JobPost;
use App\Services\Apify\ApifyClient;
use App\Support\SettingsRepository;
use Illuminate\Support\Carbon;

class EnrichmentService
{
    public function __construct(
        private ApifyClient $apify,
        private SettingsRepository $settings,
        private EmailRanker $ranker,
    ) {}

    public function startBatch(): ?ApifyRun
    {
        $this->markCompaniesWithoutWebsite();

        if (! $this->apify->isConfigured()) {
            return null;
        }

        $batchSize = max(1, $this->settings->getInt('enrich_batch_size', 10));

        $companies = Company::query()
            ->where('enrichment_status', EnrichmentStatus::Pending)
            ->whereNotNull('domain')
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        if ($companies->isEmpty()) {
            return null;
        }

        // الحقول مطابقة لـ input schema بتاع contact-info-scraper: زحف صغير داخل نفس الدومين
        $input = [
            'startUrls' => $companies->map(fn (Company $c) => ['url' => "https://{$c->domain}"])->values()->all(),
            'maxRequestsPerStartUrl' => 5,
            'maxDepth' => 2,
            'sameDomain' => true,
        ];

        $actorId = (string) $this->settings->get('enrich_actor_id');
        $data = $this->apify->startActor($actorId, $input);

        $run = ApifyRun::create([
            'apify_run_id' => $data['id'],
            'actor_id' => $actorId,
            'purpose' => RunPurpose::Enrich,
            'origin' => RunOrigin::System,
            'status' => $data['status'] ?? 'READY',
            'input' => $input,
            'default_dataset_id' => $data['defaultDatasetId'] ?? null,
            'default_kv_store_id' => $data['defaultKeyValueStoreId'] ?? null,
            'started_at' => isset($data['startedAt']) ? Carbon::parse($data['startedAt']) : now(),
        ]);

        Company::whereIn('id', $companies->pluck('id'))->update([
            'enrichment_status' => EnrichmentStatus::Running->value,
            'enrich_run_id' => $run->id,
        ]);

        return $run;
    }

    public function importRun(ApifyRun $run): void
    {
        $groups = $this->collectGroups($run);

        $companies = Company::query()
            ->where('enrich_run_id', $run->id)
            ->where('enrichment_status', EnrichmentStatus::Running)
            ->get();

        foreach ($companies as $company) {
            $this->importCompany($company, $this->groupFor($groups, $company->domain));
        }

        $run->imported_at = now();
        $run->save();
    }

    public function handleFailedRun(ApifyRun $run): void
    {
        Company::query()
            ->where('enrich_run_id', $run->id)
            ->where('enrichment_status', EnrichmentStatus::Running)
            ->update(['enrichment_status' => EnrichmentStatus::Failed->value]);
    }

    /** شركات بلا موقع: مفيش حاجة نزحفها — نقفلها هي ووظائفها */
    private function markCompaniesWithoutWebsite(): void
    {
        $ids = Company::query()
            ->where('enrichment_status', EnrichmentStatus::Pending)
            ->whereNull('domain')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        Company::whereIn('id', $ids)->update(['enrichment_status' => EnrichmentStatus::NoWebsite->value]);

        JobPost::whereIn('company_id', $ids)
            ->whereIn('status', [JobPostStatus::New->value, JobPostStatus::Enriching->value])
            ->update(['status' => JobPostStatus::NoContact->value]);
    }

    /**
     * يلضم عناصر الداتاسيت في مجموعات حسب دومين الصفحة (بدون www).
     *
     * @return array<string, array{emails: string[], phones: string[]}>
     */
    private function collectGroups(ApifyRun $run): array
    {
        $groups = [];

        if (! $run->default_dataset_id) {
            return $groups;
        }

        $offset = 0;
        $limit = 500;

        do {
            $items = $this->apify->getDatasetItems($run->default_dataset_id, $offset, $limit);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $domain = $this->itemDomain($item);
                if (! $domain) {
                    continue;
                }

                $groups[$domain] ??= ['emails' => [], 'phones' => []];

                foreach ($this->extractValues($item, ['emails', 'email']) as $email) {
                    $groups[$domain]['emails'][] = $email;
                }
                foreach ($this->extractValues($item, ['phones', 'phone']) as $phone) {
                    $groups[$domain]['phones'][] = $phone;
                }
            }

            $offset += count($items);
        } while (count($items) === $limit);

        return $groups;
    }

    /** أسماء الحقول بتختلف بين نسخ الـ actor — نجرب الأكثر شيوعًا بالترتيب */
    private function itemDomain(array $item): ?string
    {
        foreach (['domain', 'url', 'originalStartUrl', 'startUrl'] as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $domain = Company::extractDomain(trim($value));
                if ($domain) {
                    return $domain;
                }
            }
        }

        return null;
    }

    /**
     * الحقل ممكن ييجي string مفرد أو array من strings أو objects — نطبّعه لقائمة strings.
     *
     * @return string[]
     */
    private function extractValues(array $item, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $raw = $item[$key] ?? null;
            if ($raw === null) {
                continue;
            }

            foreach (is_array($raw) ? $raw : [$raw] as $entry) {
                if (is_array($entry)) {
                    $entry = $entry['email'] ?? $entry['phone'] ?? $entry['value'] ?? null;
                }
                if (is_string($entry) && trim($entry) !== '') {
                    $values[] = trim($entry);
                }
            }
        }

        return $values;
    }

    /**
     * مجموعة الشركة = دومينها بالظبط أو أي subdomain منه.
     *
     * @return array{emails: string[], phones: string[]}
     */
    private function groupFor(array $groups, ?string $domain): array
    {
        $merged = ['emails' => [], 'phones' => []];

        if (! $domain) {
            return $merged;
        }

        foreach ($groups as $key => $group) {
            if ($key === $domain || str_ends_with($key, ".{$domain}")) {
                $merged['emails'] = array_merge($merged['emails'], $group['emails']);
                $merged['phones'] = array_merge($merged['phones'], $group['phones']);
            }
        }

        return $merged;
    }

    /**
     * الإيميل لازم يكون على دومين الشركة نفسه أو subdomain منه.
     * الزاحف بياخد كل إيميل على الصفحة، وكتير منها بتاع أطراف تانية —
     * إرسال تقديم لعنوان زي ده = إحراج، فالاتجاه الآمن هو الاستبعاد.
     */
    private function belongsToCompanyDomain(string $email, ?string $companyDomain): bool
    {
        $companyDomain = strtolower(trim((string) $companyDomain));
        if ($companyDomain === '') {
            return true; // مفيش دومين نقارن بيه
        }

        $emailDomain = strtolower(trim((string) substr((string) strrchr($email, '@'), 1)));

        return $emailDomain === $companyDomain || str_ends_with($emailDomain, ".{$companyDomain}");
    }

    /** @param array{emails: string[], phones: string[]} $group */
    private function importCompany(Company $company, array $group): void
    {
        $newestJob = $company->jobPosts()->orderByDesc('id')->first();

        $emails = [];
        foreach ($group['emails'] as $email) {
            $email = strtolower(trim($email));
            if ($email === '' || in_array($email, $emails, true) || $this->ranker->isGarbage($email)) {
                continue;
            }
            // إيميل على دومين تاني اتلقى على موقع الشركة = طرف ثالث
            // (شريك، أو مثال في التوثيق زي support@acme.com) — مش بتاع الشركة
            if (! $this->belongsToCompanyDomain($email, $company->domain)) {
                continue;
            }
            $emails[] = $email;
        }

        // ترتيب بالـ score تنازليًا — usort مستقرة فالتعادل يفضل بترتيب الاكتشاف
        $scores = [];
        foreach ($emails as $email) {
            $scores[$email] = $this->ranker->score($email, $company->domain);
        }
        usort($emails, fn (string $a, string $b) => $scores[$b] <=> $scores[$a]);

        $winner = null; // صاحب أعلى score — سواء contact موجود قبل كده أو متسجل جديد
        $primaryCreated = false;

        foreach ($emails as $email) {
            $existing = Contact::where('email', $email)->first();

            if ($existing) {
                // الإيميل معروف — نسجّل مشاهدة على أحدث وظيفة للشركة بس
                if ($newestJob) {
                    ContactJobSighting::firstOrCreate(
                        ['contact_id' => $existing->id, 'job_post_id' => $newestJob->id],
                        ['seen_at' => now()],
                    );
                }
                $winner ??= $existing;

                continue;
            }

            $contact = Contact::create([
                'company_id' => $company->id,
                'job_post_id' => $newestJob?->id,
                'email' => $email,
                'discovered_via' => DiscoveredVia::Enrichment,
                'status' => ContactStatus::New,
                'is_alternate' => $primaryCreated,
            ]);
            $primaryCreated = true;
            $winner ??= $contact;
        }

        $phones = [];
        foreach ($group['phones'] as $phone) {
            $clean = $this->cleanPhone($phone);
            if ($clean !== null && ! in_array($clean, $phones, true)) {
                $phones[] = $clean;
            }
        }

        if ($phones !== []) {
            if ($winner !== null) {
                if (! $winner->phone) {
                    $winner->phone = $phones[0];
                    $winner->save();
                }
            } else {
                // مفيش أي إيميل — contact بالتليفون بس
                Contact::create([
                    'company_id' => $company->id,
                    'job_post_id' => $newestJob?->id,
                    'phone' => $phones[0],
                    'discovered_via' => DiscoveredVia::Enrichment,
                    'status' => ContactStatus::New,
                    'is_alternate' => false,
                ]);
            }
        }

        $hasContact = $company->contacts()->exists();
        $company->enrichment_status = $hasContact ? EnrichmentStatus::Enriched : EnrichmentStatus::NoContact;
        if ($hasContact) {
            $company->enriched_at = now();
        }
        $company->save();

        $hasEmailContact = $company->contacts()->whereNotNull('email')->exists();
        $company->jobPosts()
            ->whereIn('status', [JobPostStatus::New->value, JobPostStatus::Enriching->value])
            ->update(['status' => ($hasEmailContact ? JobPostStatus::Enriched : JobPostStatus::NoContact)->value]);
    }

    /** أرقام فقط مع + اختيارية في الأول */
    private function cleanPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        return (str_starts_with(trim($phone), '+') ? '+' : '').$digits;
    }
}
