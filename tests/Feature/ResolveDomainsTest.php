<?php

namespace Tests\Feature;

use App\Enums\EnrichmentStatus;
use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Services\CompanyDomainResolver;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ResolveDomainsTest extends TestCase
{
    use RefreshDatabase;

    /** شركة بلا موقع — الحالة اللي بيسيبها الإثراء عليها */
    private function makeCompany(string $name, EnrichmentStatus $status = EnrichmentStatus::NoWebsite): Company
    {
        return Company::create([
            'name' => $name,
            'normalized_name' => Company::normalizeName($name),
            'enrichment_status' => $status,
        ]);
    }

    /**
     * ريزولفر مزيّف في الكونتينر: اسم بيتطابق واسم لأ.
     * بيسجّل كل نداء عشان نتأكد إن الشركات المفحوصة ما بتتعادش.
     *
     * @param  array<string, array{domain: string, name: string}>  $map
     * @param  list<string>  $calls
     */
    private function fakeResolver(array $map, array &$calls = []): void
    {
        $mock = Mockery::mock(CompanyDomainResolver::class);
        $mock->shouldReceive('resolve')->andReturnUsing(function (string $name) use ($map, &$calls) {
            $calls[] = $name;

            return $map[$name] ?? null;
        });

        $this->app->instance(CompanyDomainResolver::class, $mock);
    }

    public function test_matched_company_gets_domain_and_goes_back_to_pending(): void
    {
        $this->fakeResolver(['Yassir' => ['domain' => 'yassir.com', 'name' => 'Yassir']]);

        $hit = $this->makeCompany('Yassir');

        $this->artisan('outreach:resolve-domains')
            ->expectsOutputToContain('resolved 1 / checked 1')
            ->assertSuccessful();

        $hit->refresh();
        $this->assertSame('yassir.com', $hit->domain);
        $this->assertSame('https://yassir.com', $hit->website);
        $this->assertSame(EnrichmentStatus::Pending, $hit->enrichment_status, 'لازم ترجع Pending عشان باتش الإثراء يلقطها');
        $this->assertNotNull($hit->domain_checked_at);
    }

    public function test_unmatched_company_is_only_marked_as_checked(): void
    {
        $this->fakeResolver([]); // مفيش أي تطابق

        $miss = $this->makeCompany('Some Ambiguous Startup');

        $this->artisan('outreach:resolve-domains')
            ->expectsOutputToContain('resolved 0 / checked 1')
            ->assertSuccessful();

        $miss->refresh();
        $this->assertNull($miss->domain);
        $this->assertNull($miss->website);
        $this->assertSame(EnrichmentStatus::NoWebsite, $miss->enrichment_status, 'الحالة ما تتغيرش لما مفيش تطابق');
        $this->assertNotNull($miss->domain_checked_at);
    }

    public function test_already_checked_companies_are_never_retried(): void
    {
        $calls = [];
        $this->fakeResolver(['Yassir' => ['domain' => 'yassir.com', 'name' => 'Yassir']], $calls);

        $this->makeCompany('Yassir');
        $this->makeCompany('Ghost Co');

        $this->artisan('outreach:resolve-domains')->assertSuccessful();
        $this->assertSame(['Yassir', 'Ghost Co'], $calls);

        // التشغيلة التانية: الاتنين اتفحصوا خلاص (واحد بدومين وواحد من غير) → مفيش أي نداء
        $this->artisan('outreach:resolve-domains')
            ->expectsOutputToContain('resolved 0 / checked 0')
            ->assertSuccessful();

        $this->assertCount(2, $calls, 'الشركات المفحوصة ما تتنادىش تاني');
    }

    public function test_limit_option_caps_the_batch(): void
    {
        $calls = [];
        $this->fakeResolver([], $calls);

        foreach (['A Co', 'B Co', 'C Co'] as $name) {
            $this->makeCompany($name);
        }

        $this->artisan('outreach:resolve-domains --limit=2')
            ->expectsOutputToContain('resolved 0 / checked 2')
            ->assertSuccessful();

        $this->assertSame(['A Co', 'B Co'], $calls); // بترتيب الـ id
        $this->assertNotNull(Company::where('name', 'B Co')->first()->domain_checked_at);
        $this->assertNull(Company::where('name', 'C Co')->first()->domain_checked_at);
    }

    public function test_enrich_is_blocked_when_apify_spend_cap_is_reached(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('apify_token', 'tok');
        $settings->set('enrich_actor_id', 'vdrmota/contact-info-scraper');

        // شركة جاهزة للإثراء — من غير البوابة كان هيبدأ run
        $company = Company::create([
            'name' => 'Ready Co',
            'normalized_name' => 'ready co',
            'website' => 'https://ready.example.com',
            'domain' => 'ready.example.com',
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);

        ApifyRun::create([
            'apify_run_id' => 'SPEND1',
            'actor_id' => 'acme/actor',
            'purpose' => RunPurpose::Scrape,
            'origin' => RunOrigin::System,
            'status' => 'SUCCEEDED',
            'usage_usd' => 5.00, // فوق السقف الافتراضي $4
        ]);

        Http::fake();

        $this->artisan('outreach:enrich')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(EnrichmentStatus::Pending, $company->fresh()->enrichment_status, 'الشركة تفضل في الطابور');
    }
}
