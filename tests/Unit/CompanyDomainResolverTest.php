<?php

namespace Tests\Unit;

use App\Services\CompanyDomainResolver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyDomainResolverTest extends TestCase
{
    private const ENDPOINT = 'autocomplete.clearbit.com/*';

    private CompanyDomainResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CompanyDomainResolver;
    }

    /** يزوّد رد Clearbit المزيّف — ملاحظة: Http::fake بيدمج، فبننده مرة واحدة بس في كل تست */
    private function fakeSuggest(mixed $body, int $status = 200): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);
    }

    public function test_exact_match_prefers_dot_com(): void
    {
        $this->fakeSuggest([
            ['name' => 'Yassir', 'domain' => 'yassir.tn', 'logo' => null],
            ['name' => 'Yassir', 'domain' => 'yassir.com', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'yassir.com', 'name' => 'Yassir'],
            $this->resolver->resolve('Yassir'),
        );
    }

    public function test_scans_past_non_exact_first_result(): void
    {
        // "Octane AI" بيرجع الأول لكنه شركة تانية — لازم نكمّل لحد "Octane" بالظبط
        $this->fakeSuggest([
            ['name' => 'Octane AI', 'domain' => 'octaneai.com', 'logo' => null],
            ['name' => 'Octane Lending', 'domain' => 'octanelending.com', 'logo' => null],
            ['name' => 'Octane', 'domain' => 'octane.co', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'octane.co', 'name' => 'Octane'],
            $this->resolver->resolve('Octane'),
        );
    }

    public function test_returns_null_when_no_exact_match(): void
    {
        // مفيش fallback على أول نتيجة
        $this->fakeSuggest([
            ['name' => 'Nilesoft Shell', 'domain' => 'nilesoft.org', 'logo' => null],
        ]);

        $this->assertNull($this->resolver->resolve('Nile Soft'));
    }

    public function test_ignores_company_suffix_on_the_query_side(): void
    {
        $this->fakeSuggest([
            ['name' => 'Acme', 'domain' => 'acme.com', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'acme.com', 'name' => 'Acme'],
            $this->resolver->resolve('Acme Technologies'),
        );
    }

    public function test_ignores_company_suffix_on_the_candidate_side(): void
    {
        $this->fakeSuggest([
            ['name' => 'Talabat Group', 'domain' => 'talabat.com', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'talabat.com', 'name' => 'Talabat Group'],
            $this->resolver->resolve('Talabat LLC'),
        );
    }

    public function test_punctuation_and_casing_do_not_block_a_match(): void
    {
        $this->fakeSuggest([
            ['name' => 'Robusta Studio', 'domain' => 'robustastudio.com', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'robustastudio.com', 'name' => 'Robusta Studio'],
            $this->resolver->resolve('  ROBUSTA-STUDIO  '),
        );
    }

    /** @return array<string, array{string}> */
    public static function blockedDomains(): array
    {
        return [
            'linkedin' => ['linkedin.com'],
            'linkedin with www' => ['www.linkedin.com'],
            'linkedin country subdomain' => ['eg.linkedin.com'],
            'facebook' => ['facebook.com'],
            'twitter' => ['twitter.com'],
            'x' => ['x.com'],
            'instagram' => ['instagram.com'],
            'youtube' => ['youtube.com'],
            'glassdoor tld variant' => ['glassdoor.co.uk'],
            'indeed' => ['indeed.com'],
            'indeed tld variant' => ['indeed.eg'],
            'wuzzuf' => ['wuzzuf.net'],
            'jobs board subdomain' => ['jobs.example.com'],
            'crunchbase' => ['crunchbase.com'],
            'wikipedia' => ['wikipedia.org'],
            'bayt' => ['bayt.com'],
        ];
    }

    #[DataProvider('blockedDomains')]
    public function test_rejects_social_and_aggregator_domains(string $domain): void
    {
        // ده بالظبط اللي السكرابر بيرجّعه غلط — ممنوع نعتبره دومين الشركة
        $this->fakeSuggest([
            ['name' => 'Foobar', 'domain' => $domain, 'logo' => null],
        ]);

        $this->assertNull($this->resolver->resolve('Foobar'));
    }

    public function test_falls_back_to_a_clean_domain_when_a_blocked_one_matches_first(): void
    {
        $this->fakeSuggest([
            ['name' => 'Foobar', 'domain' => 'linkedin.com', 'logo' => null],
            ['name' => 'Foobar', 'domain' => 'foobar.com', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'foobar.com', 'name' => 'Foobar'],
            $this->resolver->resolve('Foobar'),
        );
    }

    public function test_returns_null_on_server_error(): void
    {
        $this->fakeSuggest('', 500);

        $this->assertNull($this->resolver->resolve('Yassir'));
    }

    public function test_returns_null_on_client_error(): void
    {
        $this->fakeSuggest('', 429);

        $this->assertNull($this->resolver->resolve('Yassir'));
    }

    public function test_returns_null_on_empty_result_set(): void
    {
        $this->fakeSuggest([]);

        $this->assertNull($this->resolver->resolve('Yassir'));
    }

    public function test_returns_null_on_non_json_body(): void
    {
        $this->fakeSuggest('not json at all');

        $this->assertNull($this->resolver->resolve('Yassir'));
    }

    public function test_survives_malformed_candidate_entries(): void
    {
        // عناصر ناقصة أو بأنواع غلط — لازم null من غير exception
        $this->fakeSuggest([
            'garbage',
            null,
            ['name' => 'Yassir'],
            ['domain' => 'yassir.com'],
            ['name' => 'Yassir', 'domain' => 12345],
            ['name' => 'Yassir', 'domain' => ''],
            ['name' => 'Yassir', 'domain' => 'not-a-domain'],
            ['name' => 12345, 'domain' => 'yassir.com'],
        ]);

        $this->assertNull($this->resolver->resolve('Yassir'));
    }

    public function test_short_names_return_null_without_any_http_call(): void
    {
        Http::fake();

        $this->assertNull($this->resolver->resolve('AI'));
        $this->assertNull($this->resolver->resolve('  '));
        $this->assertNull($this->resolver->resolve(''));
        // كله كلمات عامة → مفيش اسم مميز نطابق بيه
        $this->assertNull($this->resolver->resolve('Tech Group LLC'));

        Http::assertNothingSent();
    }

    public function test_sends_the_company_name_as_the_query_parameter(): void
    {
        $this->fakeSuggest([
            ['name' => 'Yassir', 'domain' => 'yassir.com', 'logo' => null],
        ]);

        $this->resolver->resolve('  Yassir  ');

        Http::assertSent(static function ($request): bool {
            return str_starts_with($request->url(), 'https://autocomplete.clearbit.com/v1/companies/suggest')
                && $request['query'] === 'Yassir';
        });
    }

    public function test_normalizes_the_returned_domain_to_lowercase(): void
    {
        $this->fakeSuggest([
            ['name' => 'Yassir', 'domain' => 'WWW.Yassir.COM', 'logo' => null],
        ]);

        $this->assertSame(
            ['domain' => 'yassir.com', 'name' => 'Yassir'],
            $this->resolver->resolve('Yassir'),
        );
    }
}
