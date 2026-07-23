<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\OutreachStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\OutreachEmail;
use App\Services\EmailValidator;
use App\Services\OutreachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** فيك بيستغنى عن استعلامات الـ DNS الحقيقية في الاختبارات */
class FakeEmailValidator extends EmailValidator
{
    /** @var string[] عناوين نعتبرها بلا MX */
    public array $invalid = [];

    public function isValid(string $email): bool
    {
        if (in_array($email, $this->invalid, true)) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

class NoResendInvariantTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmailValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FakeEmailValidator;
        $this->app->instance(EmailValidator::class, $this->validator);
    }

    private function makeContact(string $email, array $attributes = []): Contact
    {
        $company = Company::create([
            'name' => 'Acme '.$email,
            'normalized_name' => 'acme '.$email,
        ]);

        return Contact::create(array_merge([
            'company_id' => $company->id,
            'email' => $email,
            'status' => ContactStatus::New,
            'is_alternate' => false,
        ], $attributes));
    }

    private function sentRowFor(Contact $contact): OutreachEmail
    {
        return OutreachEmail::create([
            'contact_id' => $contact->id,
            'company_id' => $contact->company_id,
            'to_email' => $contact->email,
            'status' => OutreachStatus::Sent,
            'tracking_token' => Str::random(40),
            'position' => 1,
            'queued_at' => now()->subDay(),
            'sent_at' => now()->subDay(),
        ]);
    }

    public function test_queue_fill_never_requeues_an_address_already_sent(): void
    {
        $contact = $this->makeContact('hr@acme.com');
        $this->sentRowFor($contact);

        $this->artisan('outreach:queue-fill')->assertSuccessful();

        // صف الـ sent القديم بس — مفيش إدخال جديد
        $this->assertSame(1, OutreachEmail::count());
        $this->assertSame(ContactStatus::New, $contact->fresh()->status);
    }

    public function test_manual_queue_contact_allows_resending_a_sent_address(): void
    {
        $contact = $this->makeContact('hr@acme.com', ['status' => ContactStatus::Contacted]);
        $this->sentRowFor($contact);

        $email = app(OutreachService::class)->queueContact($contact, manual: true);

        $this->assertSame(2, OutreachEmail::count());
        $this->assertSame(OutreachStatus::Queued, $email->status);
        $this->assertTrue($email->is_manual);
        $this->assertSame(ContactStatus::Queued, $contact->fresh()->status);
    }

    public function test_suppressed_contact_throws_even_on_manual_queue(): void
    {
        $contact = $this->makeContact('hr@acme.com', ['status' => ContactStatus::Suppressed]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('suppressed');

        app(OutreachService::class)->queueContact($contact, manual: true);
    }

    public function test_alternate_contacts_are_not_auto_queued(): void
    {
        $this->makeContact('alt@acme.com', ['is_alternate' => true]);

        $this->artisan('outreach:queue-fill')->assertSuccessful();

        $this->assertSame(0, OutreachEmail::count());
        $this->assertSame(ContactStatus::New, Contact::sole()->status);
    }

    public function test_invalid_mx_email_marks_contact_invalid(): void
    {
        $this->validator->invalid = ['jobs@no-mx-domain.test'];
        $contact = $this->makeContact('jobs@no-mx-domain.test');

        $this->artisan('outreach:queue-fill')->assertSuccessful();

        $this->assertSame(ContactStatus::Invalid, $contact->fresh()->status);
        $this->assertSame(0, OutreachEmail::count());
    }
}
