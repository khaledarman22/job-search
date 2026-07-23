<?php

namespace Tests\Feature;

use App\Enums\JobPostStatus;
use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use App\Models\ApifyRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JobPost;
use App\Models\Source;
use App\Services\Apify\RunImporter;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsRepository::class)->set('apify_token', 'test-token');
    }

    private function makeSource(): Source
    {
        return Source::create([
            'name' => 'Test Board',
            'actor_id' => 'acme/test-actor',
            'input_template' => ['q' => '{keywords}'],
            'field_map' => [
                'title' => 'title',
                'url' => 'link',
                'external_id' => 'id',
                'company_name' => 'company.name',
                'company_website' => 'company.info.website',
                'location' => 'loc',
                'description' => 'desc',
                'posted_at' => 'postedAt',
                'email' => 'contact.email',
                'phone' => 'contact.phone',
            ],
            'enabled' => true,
        ]);
    }

    private function makeRun(Source $source, string $datasetId = 'ds-1'): ApifyRun
    {
        return ApifyRun::create([
            'apify_run_id' => 'run-'.Str::random(12),
            'actor_id' => $source->actor_id,
            'source_id' => $source->id,
            'purpose' => RunPurpose::Scrape,
            'origin' => RunOrigin::System,
            'status' => 'SUCCEEDED',
            'default_dataset_id' => $datasetId,
        ]);
    }

    /** @param array<string, array> $datasets datasetId => items */
    private function fakeDatasets(array $datasets): void
    {
        $stubs = [];
        foreach ($datasets as $id => $items) {
            $stubs["api.apify.com/v2/datasets/{$id}/items*"] = Http::response($items);
        }
        foreach ($datasets as $id => $items) {
            $stubs["api.apify.com/v2/datasets/{$id}"] = Http::response(['data' => ['itemCount' => count($items)]]);
        }
        Http::fake($stubs);
    }

    /** عنصر فيه إيميل في نص الوصف + عنصر بلا أي إيميل */
    private function items(): array
    {
        return [
            [
                'id' => 'ext-1',
                'title' => 'PHP Developer',
                'link' => 'https://board.example.com/jobs/1',
                'company' => [
                    'name' => 'Acme Labs',
                    'info' => ['website' => 'https://www.acme-labs.com/about'],
                ],
                'loc' => 'Cairo',
                'desc' => 'Great team. send CV to careers@acme-labs.com before Friday.',
                'postedAt' => '2026-07-01T00:00:00Z',
            ],
            [
                'id' => 'ext-2',
                'title' => 'Laravel Developer',
                'link' => 'https://board.example.com/jobs/2',
                'company' => [
                    'name' => 'Beta Works',
                    'info' => ['website' => 'https://www.beta-works.com'],
                ],
                'loc' => 'Giza',
                'desc' => 'Apply through the portal on our website.',
                'postedAt' => '2026-07-02T00:00:00Z',
            ],
        ];
    }

    private function importWithMode(string $mode): int
    {
        app(SettingsRepository::class)->set('import_mode', $mode);

        $source = $this->makeSource();
        $run = $this->makeRun($source);
        $this->fakeDatasets(['ds-1' => $this->items()]);

        return app(RunImporter::class)->import($run);
    }

    public function test_mode_all_imports_every_job_and_creates_contact_for_the_one_with_email(): void
    {
        $created = $this->importWithMode(RunImporter::MODE_ALL);

        $this->assertSame(2, $created);
        $this->assertSame(2, JobPost::count());
        $this->assertSame(2, Company::count());

        $contact = Contact::sole();
        $this->assertSame('careers@acme-labs.com', $contact->email);

        $withEmail = JobPost::where('external_id', 'ext-1')->sole();
        $this->assertSame(JobPostStatus::Enriched, $withEmail->status);

        $withoutEmail = JobPost::where('external_id', 'ext-2')->sole();
        $this->assertSame(JobPostStatus::New, $withoutEmail->status);
    }

    public function test_mode_with_email_skips_the_emailless_job_and_its_company_entirely(): void
    {
        $created = $this->importWithMode(RunImporter::MODE_WITH_EMAIL);

        $this->assertSame(1, $created);

        $job = JobPost::sole();
        $this->assertSame('ext-1', $job->external_id);

        $this->assertSame('careers@acme-labs.com', Contact::sole()->email);

        // الشركة بتاعة الوظيفة اللي بلا إيميل مااتعملتش أصلًا
        $this->assertSame(1, Company::count());
        $this->assertSame('Acme Labs', Company::sole()->name);
        $this->assertFalse(Company::where('normalized_name', Company::normalizeName('Beta Works'))->exists());
    }

    public function test_mode_companies_only_imports_jobs_and_companies_without_any_contact(): void
    {
        $created = $this->importWithMode(RunImporter::MODE_COMPANIES_ONLY);

        $this->assertSame(2, $created);
        $this->assertSame(2, JobPost::count());
        $this->assertSame(2, Company::count());

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, JobPost::where('status', JobPostStatus::Enriched)->count());
    }
}
