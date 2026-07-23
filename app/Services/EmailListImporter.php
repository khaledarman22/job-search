<?php

namespace App\Services;

use App\Enums\ContactStatus;
use App\Enums\DiscoveredVia;
use App\Enums\EnrichmentStatus;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Support\Carbon;

class EmailListImporter
{
    /** إيميل داخل النص — نفس صيغة ContactExtractor */
    private const EMAIL_RE = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    public function __construct(
        private EmailValidator $validator,
        private OutreachService $outreach,
    ) {}

    /**
     * يستخرج كل الإيميلات من النص: lowercase + trim + unique.
     *
     * @return string[]
     */
    public function parseEmails(string $text): array
    {
        preg_match_all(self::EMAIL_RE, $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            $matches[0],
        )));
    }

    /**
     * يستورد الإيميلات: ينشئ شركة/جهة اتصال لكل إيميل صالح وغير مكرر،
     * ويضيفه اختياريًا لطابور الإرسال.
     *
     * @return array{found: int, imported: int, queued: int, invalid: int, duplicates: int}
     */
    public function import(string $text, bool $queue = true): array
    {
        $emails = $this->parseEmails($text);

        $stats = [
            'found' => count($emails),
            'imported' => 0,
            'queued' => 0,
            'invalid' => 0,
            'duplicates' => 0,
        ];

        foreach ($emails as $email) {
            if (Contact::where('email', $email)->exists()) {
                $stats['duplicates']++;

                continue;
            }

            if (! $this->validator->isValid($email)) {
                $stats['invalid']++;

                continue;
            }

            $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));

            $company = Company::firstOrCreate(
                ['normalized_name' => Company::normalizeName($domain)],
                [
                    'name' => $domain,
                    'domain' => $domain,
                    'website' => "https://{$domain}",
                    'enrichment_status' => EnrichmentStatus::Enriched,
                    'enriched_at' => Carbon::now(),
                ],
            );

            $contact = Contact::create([
                'company_id' => $company->id,
                'email' => $email,
                'discovered_via' => DiscoveredVia::Manual,
                'status' => ContactStatus::New,
            ]);

            $stats['imported']++;

            if ($queue) {
                try {
                    $this->outreach->queueContact($contact, null, true);
                    $stats['queued']++;
                } catch (\DomainException) {
                    // suppressed | no_email | already_queued — بنتجاهله ونكمل
                }
            }
        }

        return $stats;
    }
}
