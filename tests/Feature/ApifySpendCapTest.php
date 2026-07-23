<?php

namespace Tests\Feature;

use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Services\ApifySpendTracker;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApifySpendCapTest extends TestCase
{
    use RefreshDatabase;

    private function seedRun(float $usd, RunOrigin $origin = RunOrigin::System): ApifyRun
    {
        return ApifyRun::create([
            'apify_run_id' => 'R'.uniqid(),
            'actor_id' => 'acme/actor',
            'purpose' => RunPurpose::Scrape,
            'origin' => $origin,
            'status' => 'SUCCEEDED',
            'usage_usd' => $usd,
        ]);
    }

    public function test_spent_sums_only_system_runs_after_baseline(): void
    {
        $tracker = app(ApifySpendTracker::class);
        $this->seedRun(1.50);
        $this->seedRun(2.00);
        $this->seedRun(9.99, RunOrigin::Synced); // synced لا يُحسب

        $this->assertEqualsWithDelta(3.50, $tracker->spent(), 0.001);
        $this->assertFalse($tracker->capReached()); // السقف الافتراضي 4
    }

    public function test_cap_reached_pauses_and_blocks_scrape(): void
    {
        $tracker = app(ApifySpendTracker::class);
        $this->seedRun(2.50);
        $this->seedRun(2.00); // الإجمالي 4.50 > 4

        $this->assertTrue($tracker->capReached());
        $this->assertTrue($tracker->allowScrape() === false);
        $this->assertTrue($tracker->isPaused());
        $this->assertNotNull(app(SettingsRepository::class)->get('apify_scrape_paused_reason'));
    }

    public function test_scheduled_scrape_skips_when_paused(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('apify_token', 'tok');
        $settings->set('auto_scrape_enabled', true);
        $settings->set('auto_scrape_time', now(config('app.timezone'))->format('H').':00');
        $settings->set('send_timezone', config('app.timezone'));
        $this->seedRun(5.00); // فوق السقف

        Http::fake(); // ما ينفعش يبدأ أي actor

        $this->artisan('outreach:scrape --scheduled')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_reset_clears_pause_and_rebaselines(): void
    {
        $tracker = app(ApifySpendTracker::class);
        $this->seedRun(5.00);
        $tracker->allowScrape(); // يوقف
        $this->assertTrue($tracker->isPaused());

        $tracker->reset(); // = تغيير الحساب

        $this->assertFalse($tracker->isPaused());
        $this->assertEqualsWithDelta(0.0, $tracker->spent(), 0.001); // البيسلاين اتحرّك للأمام
        $this->assertTrue($tracker->allowScrape());
    }
}
