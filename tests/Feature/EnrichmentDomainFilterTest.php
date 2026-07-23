<?php

namespace Tests\Feature;

use App\Enums\EnrichmentStatus;
use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Models\Contact;
use App\Services\EnrichmentService;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الزاحف بيجيب كل إيميل على موقع الشركة — بما فيها إيميلات أطراف تانية
 * (شركاء، أو أمثلة في التوثيق). المفروض نستبعدها ونسيب بس اللي على دومين الشركة.
 */
class EnrichmentDomainFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_keeps_emails_on_the_company_own_domain(): void
    {
        app(SettingsRepository::class)->set('apify_token', 'tok');

        $run = ApifyRun::create([
            'apify_run_id' => 'ENR1',
            'actor_id' => 'vdrmota/contact-info-scraper',
            'purpose' => RunPurpose::Enrich,
            'origin' => RunOrigin::System,
            'status' => 'SUCCEEDED',
            'default_dataset_id' => 'ds-1',
        ]);

        $company = Company::create([
            'name' => 'Kapa',
            'normalized_name' => 'kapa',
            'website' => 'https://kapa.ai',
            'domain' => 'kapa.ai',
            'enrichment_status' => EnrichmentStatus::Running,
            'enrich_run_id' => $run->id,
        ]);

        Http::fake([
            'api.apify.com/v2/datasets/ds-1/items*' => Http::response([[
                'url' => 'https://kapa.ai/contact',
                'emails' => [
                    'careers@kapa.ai',        // بتاع الشركة → يُقبل
                    'hr@jobs.kapa.ai',        // subdomain → يُقبل
                    'support@acme.com',       // مثال في التوثيق → يُستبعد
                    'sales@partner-corp.com', // طرف تالت → يُستبعد
                ],
                'phones' => [],
            ]]),
            'api.apify.com/v2/datasets/ds-1' => Http::response(['data' => ['itemCount' => 1]]),
        ]);

        app(EnrichmentService::class)->importRun($run);

        $emails = Contact::whereNotNull('email')->pluck('email')->sort()->values()->all();

        $this->assertSame(['careers@kapa.ai', 'hr@jobs.kapa.ai'], $emails);
        $this->assertSame(EnrichmentStatus::Enriched, $company->fresh()->enrichment_status);
    }

    public function test_company_with_only_foreign_emails_ends_as_no_contact(): void
    {
        app(SettingsRepository::class)->set('apify_token', 'tok');

        $run = ApifyRun::create([
            'apify_run_id' => 'ENR2',
            'actor_id' => 'vdrmota/contact-info-scraper',
            'purpose' => RunPurpose::Enrich,
            'origin' => RunOrigin::System,
            'status' => 'SUCCEEDED',
            'default_dataset_id' => 'ds-2',
        ]);

        $company = Company::create([
            'name' => 'Sumerge',
            'normalized_name' => 'sumerge',
            'website' => 'https://sumerge.com',
            'domain' => 'sumerge.com',
            'enrichment_status' => EnrichmentStatus::Running,
            'enrich_run_id' => $run->id,
        ]);

        Http::fake([
            'api.apify.com/v2/datasets/ds-2/items*' => Http::response([[
                'url' => 'https://sumerge.com/partners',
                'emails' => ['sales@sans.com'], // شريك — مش بتاع الشركة
                'phones' => [],
            ]]),
            'api.apify.com/v2/datasets/ds-2' => Http::response(['data' => ['itemCount' => 1]]),
        ]);

        app(EnrichmentService::class)->importRun($run);

        $this->assertSame(0, Contact::whereNotNull('email')->count());
        $this->assertSame(EnrichmentStatus::NoContact, $company->fresh()->enrichment_status);
    }
}
