<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\OutreachStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailOpen;
use App\Models\OutreachEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrackingPixelTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmail(OutreachStatus $status): OutreachEmail
    {
        $company = Company::create(['name' => 'Acme', 'normalized_name' => 'acme']);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => 'hr@acme.com',
            'status' => ContactStatus::Contacted,
        ]);

        return OutreachEmail::create([
            'contact_id' => $contact->id,
            'company_id' => $company->id,
            'to_email' => 'hr@acme.com',
            'status' => $status,
            'tracking_token' => Str::random(40),
            'position' => 1,
            'queued_at' => now(),
            'sent_at' => $status === OutreachStatus::Sent ? now() : null,
        ]);
    }

    public function test_valid_token_on_sent_email_counts_the_open(): void
    {
        $email = $this->makeEmail(OutreachStatus::Sent);

        $response = $this->get('/t/'.$email->tracking_token.'.gif');

        $response->assertOk()->assertHeader('Content-Type', 'image/gif');

        $email->refresh();
        $this->assertSame(1, $email->open_count);
        $this->assertNotNull($email->opened_at);
        $this->assertNotNull($email->last_opened_at);
        $this->assertSame(1, EmailOpen::where('outreach_email_id', $email->id)->count());
    }

    public function test_second_hit_keeps_first_opened_at_and_increments_count(): void
    {
        $email = $this->makeEmail(OutreachStatus::Sent);

        $this->get('/t/'.$email->tracking_token.'.gif')->assertOk();
        $firstOpenedAt = $email->fresh()->opened_at;

        $this->travelTo(now()->addMinutes(10));
        $this->get('/t/'.$email->tracking_token.'.gif')->assertOk();

        $email->refresh();
        $this->assertSame(2, $email->open_count);
        $this->assertTrue($email->opened_at->equalTo($firstOpenedAt));
        $this->assertTrue($email->last_opened_at->greaterThan($firstOpenedAt));
        $this->assertSame(2, EmailOpen::where('outreach_email_id', $email->id)->count());
    }

    public function test_invalid_token_still_returns_gif_without_counting(): void
    {
        $response = $this->get('/t/'.Str::random(40).'.gif');

        $response->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertSame(0, EmailOpen::count());
    }

    public function test_queued_email_token_is_not_counted(): void
    {
        $email = $this->makeEmail(OutreachStatus::Queued);

        $response = $this->get('/t/'.$email->tracking_token.'.gif');

        $response->assertOk()->assertHeader('Content-Type', 'image/gif');

        $email->refresh();
        $this->assertSame(0, $email->open_count);
        $this->assertNull($email->opened_at);
        $this->assertSame(0, EmailOpen::count());
    }
}
