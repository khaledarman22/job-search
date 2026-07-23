<?php

namespace Tests\Feature;

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
use App\Models\Source;
use App\Services\Apify\RunImporter;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunImporterTest extends TestCase
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
            // dot notation on purpose — incl. nested company website
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

    private function baseItem(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'ext-1',
            'title' => 'PHP Developer',
            'link' => 'https://board.example.com/jobs/1',
            'company' => [
                'name' => 'Acme  Labs',
                'info' => ['website' => 'https://www.acme-labs.com/about'],
            ],
            'loc' => 'Cairo',
            'desc' => 'Build things.',
            'postedAt' => '2026-07-01T00:00:00Z',
        ], $overrides);
    }

    public function test_import_creates_job_and_company_with_normalized_name_and_domain(): void
    {
        $source = $this->makeSource();
        $run = $this->makeRun($source);
        $this->fakeDatasets(['ds-1' => [$this->baseItem()]]);

        $created = app(RunImporter::class)->import($run);

        $this->assertSame(1, $created);

        $company = Company::sole();
        $this->assertSame('Acme  Labs', $company->name);
        $this->assertSame('acme labs', $company->normalized_name);
        $this->assertSame('acme-labs.com', $company->domain);
        $this->assertSame(EnrichmentStatus::Pending, $company->enrichment_status);

        $job = JobPost::sole();
        $this->assertSame('PHP Developer', $job->title);
        $this->assertSame($company->id, $job->company_id);
        $this->assertSame(JobPostStatus::New, $job->status);
        $this->assertNotNull($job->last_seen_at);

        $run->refresh();
        $this->assertSame(1, $run->item_count);
        $this->assertNotNull($run->imported_at);
    }

    public function test_second_import_of_same_url_updates_last_seen_at_without_duplicating(): void
    {
        $source = $this->makeSource();
        $this->fakeDatasets([
            'ds-1' => [$this->baseItem()],
            // نفس الرابط مع باراميتر تتبع — التطبيع لازم يوصل لنفس الـ hash
            'ds-2' => [$this->baseItem(['link' => 'https://board.example.com/jobs/1?utm_source=newsletter'])],
        ]);

        app(RunImporter::class)->import($this->makeRun($source, 'ds-1'));
        $firstSeen = JobPost::sole()->last_seen_at;

        $this->travelTo(now()->addHour());
        $created = app(RunImporter::class)->import($this->makeRun($source, 'ds-2'));

        $this->assertSame(0, $created);
        $this->assertSame(1, JobPost::count());
        $this->assertTrue(JobPost::sole()->last_seen_at->greaterThan($firstSeen));
    }

    public function test_item_with_direct_email_creates_contact_and_marks_company_and_job_enriched(): void
    {
        $source = $this->makeSource();
        $run = $this->makeRun($source);
        $this->fakeDatasets(['ds-1' => [$this->baseItem([
            'contact' => ['email' => ' HR@Acme-Labs.com ', 'phone' => '+20 100 000 0000'],
        ])]]);

        app(RunImporter::class)->import($run);

        $contact = Contact::sole();
        $this->assertSame('hr@acme-labs.com', $contact->email);
        $this->assertSame(DiscoveredVia::JobPosting, $contact->discovered_via);
        $this->assertSame(ContactStatus::New, $contact->status);
        $this->assertSame(JobPost::sole()->id, $contact->job_post_id);

        $company = Company::sole();
        $this->assertSame(EnrichmentStatus::Enriched, $company->enrichment_status);
        $this->assertNotNull($company->enriched_at);

        $this->assertSame(JobPostStatus::Enriched, JobPost::sole()->status);
    }

    public function test_rediscovered_email_creates_sighting_not_a_new_contact(): void
    {
        $source = $this->makeSource();
        $company = Company::create([
            'name' => 'Acme Labs',
            'normalized_name' => 'acme labs',
            'website' => 'https://acme-labs.com',
            'domain' => 'acme-labs.com',
        ]);
        $existing = Contact::create([
            'company_id' => $company->id,
            'email' => 'hr@acme-labs.com',
            'discovered_via' => DiscoveredVia::JobPosting,
            'status' => ContactStatus::Contacted,
        ]);

        $run = $this->makeRun($source);
        $this->fakeDatasets(['ds-1' => [$this->baseItem([
            'link' => 'https://board.example.com/jobs/2',
            'contact' => ['email' => 'hr@acme-labs.com'],
        ])]]);

        app(RunImporter::class)->import($run);

        $this->assertSame(1, Contact::count());
        $this->assertSame(ContactStatus::Contacted, $existing->fresh()->status);

        $job = JobPost::sole();
        $sighting = ContactJobSighting::sole();
        $this->assertSame($existing->id, $sighting->contact_id);
        $this->assertSame($job->id, $sighting->job_post_id);
    }
}
