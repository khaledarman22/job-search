<?php

namespace Tests\Unit;

use App\Services\EmailRanker;
use PHPUnit\Framework\TestCase;

class EmailRankerTest extends TestCase
{
    private EmailRanker $ranker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ranker = new EmailRanker;
    }

    public function test_noreply_addresses_are_garbage(): void
    {
        $this->assertTrue($this->ranker->isGarbage('noreply@acme.com'));
        $this->assertTrue($this->ranker->isGarbage('no-reply@acme.com'));
        $this->assertTrue($this->ranker->isGarbage('donotreply@acme.com'));
        // token in the domain part counts too
        $this->assertTrue($this->ranker->isGarbage('contact@noreply.acme.com'));
    }

    public function test_image_filenames_are_garbage(): void
    {
        $this->assertTrue($this->ranker->isGarbage('logo@2x.png'));
        $this->assertTrue($this->ranker->isGarbage('hero@banner.jpg'));
        $this->assertTrue($this->ranker->isGarbage('icon@site.svg'));
        $this->assertTrue($this->ranker->isGarbage('photo@team.webp'));
    }

    public function test_infrastructure_domains_are_garbage(): void
    {
        $this->assertTrue($this->ranker->isGarbage('abc123@sentry.io'));
        $this->assertTrue($this->ranker->isGarbage('team@shoutout.wixpress.com'));
        $this->assertTrue($this->ranker->isGarbage('x@cloudflare.com'));
    }

    public function test_syntactically_invalid_emails_are_garbage(): void
    {
        $this->assertTrue($this->ranker->isGarbage('not-an-email'));
        $this->assertTrue($this->ranker->isGarbage('two@@ats.com'));
        $this->assertTrue($this->ranker->isGarbage(str_repeat('a', 95).'@long-domain.com'));
    }

    public function test_normal_hiring_emails_are_not_garbage(): void
    {
        $this->assertFalse($this->ranker->isGarbage('careers@acme.com'));
        $this->assertFalse($this->ranker->isGarbage('hr@acme.com'));
        $this->assertFalse($this->ranker->isGarbage('john.doe@acme.com'));
    }

    public function test_score_orders_careers_above_hr_above_info_above_sales(): void
    {
        $careers = $this->ranker->score('careers@acme.com', null);
        $hr = $this->ranker->score('hr@acme.com', null);
        $info = $this->ranker->score('info@acme.com', null);
        $sales = $this->ranker->score('sales@acme.com', null);

        $this->assertGreaterThan($hr, $careers);
        $this->assertGreaterThan($info, $hr);
        $this->assertGreaterThan($sales, $info);
    }

    public function test_matching_company_domain_adds_bonus(): void
    {
        $without = $this->ranker->score('careers@acme.com', null);
        $with = $this->ranker->score('careers@acme.com', 'acme.com');

        $this->assertSame($without + 20, $with);
        // www prefix on either side is ignored
        $this->assertSame($with, $this->ranker->score('careers@www.acme.com', 'www.acme.com'));
        // different domain gets no bonus
        $this->assertSame($without, $this->ranker->score('careers@acme.com', 'other.com'));
    }

    public function test_freemail_domains_get_penalty(): void
    {
        $corporate = $this->ranker->score('john@acme.com', null);
        $freemail = $this->ranker->score('john@gmail.com', null);

        $this->assertSame($corporate - 10, $freemail);
        $this->assertLessThan($corporate, $this->ranker->score('john@yahoo.com', null));
    }
}
