<?php

namespace Tests\Feature;

use App\Enums\EnrichmentStatus;
use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * لو استيراد الإثراء المتزامن فشل بعد ما الـ run بقى SUCCEEDED،
 * الشركات لازم تطلع من حالة running (مش تفضل عالقة للأبد).
 */
class EnrichmentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_enrichment_import_releases_running_companies(): void
    {
        app(SettingsRepository::class)->set('apify_token', 'test-token');

        $run = ApifyRun::create([
            'apify_run_id' => 'ENRICH123',
            'actor_id' => 'vdrmota/contact-info-scraper',
            'purpose' => RunPurpose::Enrich,
            'origin' => RunOrigin::System,
            'status' => 'RUNNING',
            'default_dataset_id' => 'ds-enrich',
        ]);

        $company = Company::create([
            'name' => 'Stuck Co',
            'normalized_name' => 'stuck co',
            'website' => 'https://stuck.example.com',
            'domain' => 'stuck.example.com',
            'enrichment_status' => EnrichmentStatus::Running,
            'enrich_run_id' => $run->id,
        ]);

        // الـ run بقى SUCCEEDED، لكن جلب الداتاسِت بيفشل
        Http::fake([
            'api.apify.com/v2/actor-runs/ENRICH123*' => Http::response([
                'data' => ['status' => 'SUCCEEDED', 'defaultDatasetId' => 'ds-enrich', 'finishedAt' => now()->toIso8601String()],
            ]),
            'api.apify.com/v2/datasets/*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->artisan('apify:poll')->assertSuccessful();

        $company->refresh();
        $this->assertSame(EnrichmentStatus::Failed, $company->enrichment_status, 'الشركة لازم تطلع من running لـ failed');
        $this->assertNotNull($run->fresh()->error);
    }
}
