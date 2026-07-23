<?php

namespace Tests\Feature;

use App\Enums\ContactStatus;
use App\Enums\OutreachStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\OutreachEmail;
use App\Services\MailerService;
use App\Services\OutreachService;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** فيك بيسجل الإرسالات بدل ما يكلم SMTP فعلًا */
class RecordingMailer extends MailerService
{
    /** @var int[] */
    public array $sentIds = [];

    public ?\Throwable $throwOnSend = null;

    public function __construct()
    {
        // بنتخطى تبعيات الأب عمدًا — الفيك مش بيستخدمها
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutreachEmail $email): void
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }
        $this->sentIds[] = $email->id;
    }
}

class SendTickTest extends TestCase
{
    use RefreshDatabase;

    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        // أربعاء 2026-07-15 — الساعة 12:00 أو 13:00 بتوقيت القاهرة حسب الـ DST، جوه النافذة في الحالتين
        $this->travelTo(Carbon::parse('2026-07-15 10:00:00', 'UTC'));

        $this->mailer = new RecordingMailer;
        $this->app->instance(MailerService::class, $this->mailer);

        $this->seedSettings();
    }

    private function seedSettings(array $overrides = []): void
    {
        $settings = app(SettingsRepository::class);
        $values = array_merge([
            'sending_enabled' => '1',
            'email_subject_template' => 'Application for {job_title}',
            'email_body_template' => 'Hello {contact_name}',
            'send_window_start' => '09:00',
            'send_window_end' => '18:00',
            'send_days' => [0, 1, 2, 3, 4],
            'send_timezone' => 'Africa/Cairo',
            'daily_send_cap' => 2,
            'send_min_interval_minutes' => 15,
            'send_max_interval_minutes' => 45,
        ], $overrides);

        foreach ($values as $key => $value) {
            $settings->set($key, $value);
        }
    }

    private function queuedEmail(string $to, int $position = 1): OutreachEmail
    {
        $company = Company::create([
            'name' => 'Acme '.$to,
            'normalized_name' => 'acme '.$to,
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => $to,
            'status' => ContactStatus::Queued,
        ]);

        return OutreachEmail::create([
            'contact_id' => $contact->id,
            'company_id' => $company->id,
            'to_email' => $to,
            'status' => OutreachStatus::Queued,
            'tracking_token' => Str::random(40),
            'position' => $position,
            'queued_at' => now(),
        ]);
    }

    private function sentEmail(string $to): OutreachEmail
    {
        $email = $this->queuedEmail($to);
        $email->forceFill(['status' => OutreachStatus::Sent, 'sent_at' => now()])->save();

        return $email;
    }

    public function test_queued_email_gets_sent_and_next_send_scheduled(): void
    {
        $email = $this->queuedEmail('hr@acme.com');

        $result = app(OutreachService::class)->sendNextIfDue();

        $this->assertNotNull($result);
        $this->assertSame($email->id, $result->id);
        $this->assertSame([$email->id], $this->mailer->sentIds);

        $email->refresh();
        $this->assertSame(OutreachStatus::Sent, $email->status);
        $this->assertNotNull($email->sent_at);
        $this->assertSame(ContactStatus::Contacted, $email->contact->status);

        $next = Carbon::parse((string) app(SettingsRepository::class)->get('next_send_at'));
        $this->assertTrue($next->greaterThanOrEqualTo(now()->addMinutes(15)));
        $this->assertTrue($next->lessThanOrEqualTo(now()->addMinutes(45)));
    }

    public function test_second_immediate_tick_is_gated_by_next_send_at(): void
    {
        $this->queuedEmail('first@acme.com', 1);
        $second = $this->queuedEmail('second@acme.com', 2);

        $service = app(OutreachService::class);
        $this->assertNotNull($service->sendNextIfDue());
        $this->assertNull($service->sendNextIfDue());

        $this->assertSame(OutreachStatus::Queued, $second->fresh()->status);
        $this->assertCount(1, $this->mailer->sentIds);
    }

    public function test_no_send_when_daily_cap_reached(): void
    {
        $this->sentEmail('a@acme.com');
        $this->sentEmail('b@acme.com');
        $queued = $this->queuedEmail('c@acme.com');

        $this->assertNull(app(OutreachService::class)->sendNextIfDue());
        $this->assertSame(OutreachStatus::Queued, $queued->fresh()->status);
        $this->assertSame([], $this->mailer->sentIds);
    }

    public function test_no_send_when_sending_disabled(): void
    {
        $this->seedSettings(['sending_enabled' => '0']);
        $queued = $this->queuedEmail('hr@acme.com');

        $this->assertNull(app(OutreachService::class)->sendNextIfDue());
        $this->assertSame(OutreachStatus::Queued, $queued->fresh()->status);
        $this->assertSame([], $this->mailer->sentIds);
    }

    public function test_no_send_outside_window(): void
    {
        // نافذة صباحية خلصت قبل وقت الاختبار المجمّد
        $this->seedSettings(['send_window_start' => '06:00', 'send_window_end' => '08:00']);
        $queued = $this->queuedEmail('hr@acme.com');

        $this->assertNull(app(OutreachService::class)->sendNextIfDue());
        $this->assertSame(OutreachStatus::Queued, $queued->fresh()->status);
        $this->assertSame([], $this->mailer->sentIds);
    }

    public function test_transport_exception_requeues_email_and_pauses_sending(): void
    {
        $this->mailer->throwOnSend = new TransportException('Connection refused');
        $email = $this->queuedEmail('hr@acme.com');

        $this->assertNull(app(OutreachService::class)->sendNextIfDue());

        $this->assertSame(OutreachStatus::Queued, $email->fresh()->status);

        $settings = app(SettingsRepository::class);
        $this->assertFalse($settings->getBool('sending_enabled'));
        $this->assertStringContainsString('SMTP', (string) $settings->get('sending_paused_reason'));
        $this->assertStringContainsString('Connection refused', (string) $settings->get('sending_paused_reason'));
    }
}
