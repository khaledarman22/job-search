<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\OutreachEmail;
use App\Models\Source;
use App\Services\EmailValidator;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoScrapeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    private function makeSource(): Source
    {
        return Source::create([
            'name' => 'LinkedIn',
            'actor_id' => 'acme/linkedin',
            'input_template' => ['position' => '{keywords}', 'country' => '{country}', 'location' => '{location}'],
            'field_map' => ['title' => 'title', 'url' => 'url'],
            'default_keywords' => 'Flutter Developer',
            'default_location' => 'Egypt',
            'max_items' => 50,
            'enabled' => true,
        ]);
    }

    private function makeContact(string $email): Contact
    {
        $company = Company::create([
            'name' => 'Acme '.$email,
            'normalized_name' => 'acme '.$email,
        ]);

        return Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'status' => ContactStatus::New,
            'is_alternate' => false,
        ]);
    }

    /** يخلّي فحص الـ MX يعدّي دايمًا بدون استعلام DNS حقيقي */
    private function fakeValidEmails(): void
    {
        $validator = new class extends EmailValidator
        {
            public function isValid(string $email): bool
            {
                return true;
            }
        };

        $this->app->instance(EmailValidator::class, $validator);
    }

    public function test_scheduled_scrape_honors_settings_keywords_and_location(): void
    {
        $this->makeSource();

        $s = $this->settings();
        $s->set('apify_token', 'tok');
        $s->set('auto_scrape_enabled', true);
        $s->set('auto_scrape_time', now(config('app.timezone'))->format('H').':00');
        $s->set('send_timezone', config('app.timezone'));
        $s->set('auto_scrape_keywords', 'Backend Dev');
        $s->set('auto_scrape_location', 'SA');

        // id فريد لكل طلب — لازم closure
        Http::fake([
            'api.apify.com/*' => fn () => Http::response([
                'data' => ['id' => 'r'.bin2hex(random_bytes(8)), 'status' => 'READY', 'defaultDatasetId' => 'ds'],
            ], 201),
        ]);

        $this->artisan('outreach:scrape --scheduled')->assertSuccessful();

        $run = ApifyRun::first();
        $this->assertNotNull($run);
        // الكلمة المحددة تُستخدم بدل default_keywords، والموقع ثابت على السعودية
        $this->assertSame('Backend Dev', $run->input['position']);
        $this->assertSame('Saudi Arabia', $run->input['location']);
        $this->assertSame('SA', $run->input['country']);
    }

    public function test_queue_fill_skips_when_auto_queue_disabled(): void
    {
        $this->fakeValidEmails();
        $this->makeContact('hr@acme.com');

        $this->settings()->set('auto_queue_enabled', false);

        $this->artisan('outreach:queue-fill')->assertSuccessful();

        $this->assertSame(0, OutreachEmail::count());
        $this->assertSame(ContactStatus::New, Contact::sole()->status);
    }

    public function test_queue_fill_runs_when_auto_queue_enabled(): void
    {
        $this->fakeValidEmails();
        $this->makeContact('hr@acme.com');

        $this->settings()->set('auto_queue_enabled', true);

        $this->artisan('outreach:queue-fill')->assertSuccessful();

        $this->assertSame(1, OutreachEmail::count());
    }
}
