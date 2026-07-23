<?php

namespace Tests\Unit;

use App\Services\ContactExtractor;
use App\Services\EmailRanker;
use Tests\TestCase;

class ContactExtractorTest extends TestCase
{
    private function extractor(): ContactExtractor
    {
        return new ContactExtractor(new EmailRanker);
    }

    public function test_extracts_and_ranks_emails_from_text(): void
    {
        $text = 'To apply send your CV to careers@acme.com or info@acme.com. noreply@acme.com ignored.';
        $emails = $this->extractor()->emails($text, 'acme.com');

        // careers@ يتفوق على info@، و noreply مستبعد
        $this->assertSame(['careers@acme.com', 'info@acme.com'], $emails);
    }

    public function test_filters_garbage_and_returns_empty_when_none(): void
    {
        $this->assertSame([], $this->extractor()->emails('no contacts here, just text', null));
        $this->assertSame([], $this->extractor()->emails('logo@2x.png is an image', null));
    }

    public function test_extracts_egyptian_phone_numbers_to_e164(): void
    {
        $this->assertSame(['+201001234567'], $this->extractor()->phones('واتساب: 01001234567'));
        $this->assertSame(['+201112223334'], $this->extractor()->phones('call +20 111 222 3334 for details'));
        $this->assertSame([], $this->extractor()->phones('salary 15000 EGP, posted 2026'));
    }
}
