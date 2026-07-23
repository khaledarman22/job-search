<?php

namespace Tests\Feature;

use App\Models\ApifyRun;
use App\Models\Source;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RandomScrapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $s = app(SettingsRepository::class);
        $s->set('apify_token', 'tok');
        $s->set('auto_scrape_enabled', true);
        $s->set('auto_scrape_time', now(config('app.timezone'))->format('H').':00');
        $s->set('send_timezone', config('app.timezone'));
    }

    private function makeSource(string $name): Source
    {
        return Source::create([
            'name' => $name,
            'actor_id' => 'acme/'.strtolower($name),
            'input_template' => ['position' => '{keywords}', 'country' => '{country}', 'location' => '{location}'],
            'field_map' => ['title' => 'title', 'url' => 'url'],
            'default_keywords' => 'Flutter Developer',
            'default_location' => 'Egypt',
            'max_items' => 50,
            'enabled' => true,
        ]);
    }

    public function test_scheduled_scrape_uses_random_arab_location_and_country(): void
    {
        $this->makeSource('LinkedIn');

        Http::fake([
            'api.apify.com/*' => Http::response([
                'data' => ['id' => 'RUN1', 'status' => 'READY', 'defaultDatasetId' => 'ds'],
            ], 201),
        ]);

        $this->artisan('outreach:scrape --scheduled')->assertSuccessful();

        $run = ApifyRun::first();
        $this->assertNotNull($run);
        $input = $run->input;
        // location اتحقن من قائمة الوطن العربي، و country رمز دولتين
        $this->assertNotSame('{location}', $input['location']);
        $this->assertNotSame('{country}', $input['country']);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $input['country']);
    }

    public function test_scheduled_scrape_randomly_subsets_sources(): void
    {
        // 4 مصادر، والاختيار العشوائي ياخد من 1 لـ 4 — نتأكد إن مفيش تكرار وكله ضمن المتاح
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $this->makeSource($n);
        }

        // id فريد لكل طلب — لازم closure (مش استجابة ثابتة)
        Http::fake([
            'api.apify.com/*' => fn () => Http::response([
                'data' => ['id' => 'r'.bin2hex(random_bytes(8)), 'status' => 'READY', 'defaultDatasetId' => 'ds'],
            ], 201),
        ]);

        $this->artisan('outreach:scrape --scheduled')->assertSuccessful();

        $count = ApifyRun::count();
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertLessThanOrEqual(4, $count);
    }

    public function test_manual_run_keeps_explicit_location(): void
    {
        $this->makeSource('LinkedIn');

        Http::fake([
            'api.apify.com/*' => Http::response([
                'data' => ['id' => 'RUN2', 'status' => 'READY', 'defaultDatasetId' => 'ds'],
            ], 201),
        ]);

        $this->artisan('outreach:scrape --location="Dubai"')->assertSuccessful();

        $this->assertSame('Dubai', ApifyRun::first()->input['location']);
    }
}
