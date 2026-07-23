<?php

namespace App\Services;

use App\Enums\ContactStatus;
use App\Enums\OutreachStatus;
use App\Models\Contact;
use App\Models\JobPost;
use App\Models\OutreachEmail;
use App\Support\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;

class OutreachService
{
    public function __construct(
        private SettingsRepository $settings,
        private MailerService $mailer,
        private EmailValidator $validator,
    ) {}

    /** يملأ الطابور من جهات الاتصال الجديدة الأساسية (غير البديلة) */
    public function fillQueue(): int
    {
        $created = 0;
        $position = (int) OutreachEmail::max('position');

        $contacts = Contact::query()
            ->where('status', ContactStatus::New)
            ->whereNotNull('email')
            ->where('is_alternate', false)
            ->orderBy('id')
            ->get();

        foreach ($contacts as $contact) {
            if (! $this->validator->isValid((string) $contact->email)) {
                $contact->update(['status' => ContactStatus::Invalid]);

                continue;
            }

            $alreadyActive = OutreachEmail::where('to_email', $contact->email)
                ->whereIn('status', [OutreachStatus::Queued, OutreachStatus::Sending, OutreachStatus::Sent])
                ->exists();
            if ($alreadyActive) {
                // بيفضل new — شارة الـ sighting بتغطي العرض في الواجهة
                continue;
            }

            $this->createQueueEntry($contact, null, false, ++$position);
            $created++;
        }

        return $created;
    }

    /**
     * إدراج يدوي — بيسمح عمدًا بإعادة إدراج عنوان اترسل له قبل كده.
     *
     * @throws \DomainException codes: suppressed | no_email | already_queued
     */
    public function queueContact(Contact $contact, ?JobPost $job = null, bool $manual = true): OutreachEmail
    {
        if ($contact->status === ContactStatus::Suppressed) {
            throw new \DomainException('suppressed');
        }

        if (! $contact->email) {
            throw new \DomainException('no_email');
        }

        $inQueue = OutreachEmail::where('to_email', $contact->email)
            ->whereIn('status', [OutreachStatus::Queued, OutreachStatus::Sending])
            ->exists();
        if ($inQueue) {
            throw new \DomainException('already_queued');
        }

        return $this->createQueueEntry($contact, $job, $manual);
    }

    /** نبضة الدقيقة — يبعت الإيميل التالي لو كل البوابات سامحة */
    public function sendNextIfDue(): ?OutreachEmail
    {
        if (! $this->settings->getBool('sending_enabled')) {
            return null;
        }

        if (! $this->mailer->isConfigured() || blank($this->settings->get('email_body_template'))) {
            return null;
        }

        $now = Carbon::now($this->timezone());
        if (! $this->inWindow($now)) {
            return null;
        }

        if ($this->sentTodayCount($now) >= $this->settings->getInt('daily_send_cap', 40)) {
            return null;
        }

        $nextSendAt = $this->settings->get('next_send_at');
        if (! blank($nextSendAt)) {
            try {
                if (Carbon::now('UTC')->lt(Carbon::parse($nextSendAt)->utc())) {
                    return null;
                }
            } catch (\Throwable) {
                // قيمة تالفة — نتجاهلها ونكمل
            }
        }

        $candidate = OutreachEmail::where('status', OutreachStatus::Queued)
            ->orderBy('position')
            ->orderBy('id')
            ->first();
        if (! $candidate) {
            // طابور فاضي — نسيب next_send_at زي ما هي عشان أول إدخال جديد يتبعت فورًا
            return null;
        }

        // claim شرطي — صف واحد بس يكسب لو فيه أكتر من tick شغال
        $claimed = OutreachEmail::whereKey($candidate->id)
            ->where('status', OutreachStatus::Queued->value)
            ->update(['status' => OutreachStatus::Sending->value]);
        if ($claimed === 0) {
            return null;
        }

        $candidate->refresh();

        try {
            $this->mailer->send($candidate);
        } catch (TransportException $e) {
            if (! $this->isRecipientRejection($e)) {
                // فشل نظامي (اتصال/مصادقة) — الصف يرجع للطابور والإرسال كله يتوقف
                $candidate->forceFill(['status' => OutreachStatus::Queued])->save();
                $this->pause('SMTP: '.Str::limit($e->getMessage(), 180));

                return null;
            }

            // رفض خاص بالمستلم (mailbox غير موجود مثلًا) — دائم، فشل فوري بلا إعادة
            return $this->recordSendFailure($candidate, $e, permanent: true);
        } catch (\Throwable $e) {
            return $this->recordSendFailure($candidate, $e);
        }

        $candidate->forceFill([
            'status' => OutreachStatus::Sent,
            'sent_at' => Carbon::now(),
            'error' => null,
        ])->save();
        $candidate->contact?->update(['status' => ContactStatus::Contacted]);

        $this->scheduleNextSend();

        return $candidate;
    }

    /**
     * TransportException بترمى للاتنين: أعطال الاتصال/المصادقة (نظامية)
     * ورفض المستلم الواحد (550/551/553). الأولى توقف الإرسال كله، التانية
     * تفشّل الصف الحالي بس.
     */
    private function isRecipientRejection(TransportException $e): bool
    {
        $message = $e->getMessage();

        return (bool) preg_match(
            '/\b55[013]\b|RCPT TO|recipient|mailbox (unavailable|not found|does not exist)|user unknown|no such user/i',
            $message
        );
    }

    private function recordSendFailure(OutreachEmail $candidate, \Throwable $e, bool $permanent = false): OutreachEmail
    {
        $attempts = $candidate->attempts + 1;
        $failedForGood = $permanent || $attempts >= 2;

        $candidate->forceFill([
            'status' => $failedForGood ? OutreachStatus::Failed : OutreachStatus::Queued,
            'attempts' => $attempts,
            'failed_at' => $failedForGood ? Carbon::now() : null,
            'error' => Str::limit($e->getMessage(), 1000),
        ])->save();
        // جهة الاتصال بتفضل queued — مسار إعادة الإدراج اليدوي موجود

        $this->scheduleNextSend();

        return $candidate;
    }

    public function pause(?string $reason = null): void
    {
        $this->settings->set('sending_enabled', false);
        $this->settings->set('sending_paused_reason', $reason);
    }

    public function resume(): void
    {
        $this->settings->set('sending_enabled', true);
        $this->settings->forget('sending_paused_reason');
    }

    /** شكل dashboard.sending بالظبط زي API_CONTRACT.md */
    public function sendingState(): array
    {
        $timezone = $this->timezone();
        $now = Carbon::now($timezone);

        $nextSendAt = null;
        $stored = $this->settings->get('next_send_at');
        if (! blank($stored)) {
            try {
                $nextSendAt = Carbon::parse($stored)->utc()->toIso8601ZuluString();
            } catch (\Throwable) {
                $nextSendAt = null;
            }
        }

        return [
            'enabled' => $this->settings->getBool('sending_enabled'),
            'paused_reason' => $this->settings->get('sending_paused_reason'),
            'next_send_at' => $nextSendAt,
            'in_window' => $this->inWindow($now),
            'window' => [
                'start' => $this->settings->get('send_window_start'),
                'end' => $this->settings->get('send_window_end'),
                'days' => array_map(intval(...), $this->settings->getArray('send_days', [0, 1, 2, 3, 4])),
                'timezone' => $timezone,
            ],
            'cap_reached' => $this->sentTodayCount($now) >= $this->settings->getInt('daily_send_cap', 40),
            'smtp_configured' => $this->mailer->isConfigured(),
            'apify_configured' => $this->settings->has('apify_token'),
            'template_configured' => ! blank($this->settings->get('email_body_template')),
        ];
    }

    private function createQueueEntry(Contact $contact, ?JobPost $job, bool $manual, ?int $position = null): OutreachEmail
    {
        $jobPostId = $job?->id
            ?? $contact->job_post_id
            ?? JobPost::where('company_id', $contact->company_id)->latest('id')->value('id');

        $email = OutreachEmail::create([
            'contact_id' => $contact->id,
            'company_id' => $contact->company_id,
            'job_post_id' => $jobPostId,
            'to_email' => $contact->email,
            'status' => OutreachStatus::Queued,
            'is_manual' => $manual,
            'tracking_token' => Str::random(40),
            'position' => $position ?? ((int) OutreachEmail::max('position') + 1),
            'queued_at' => Carbon::now(),
        ]);

        $contact->update(['status' => ContactStatus::Queued]);

        return $email;
    }

    private function timezone(): string
    {
        return $this->settings->get('send_timezone') ?? 'Africa/Cairo';
    }

    /** اليوم + الوقت جوه نافذة الإرسال؟ ($now لازم يكون بتايم زون الإرسال) */
    private function inWindow(Carbon $now): bool
    {
        $days = array_map(intval(...), $this->settings->getArray('send_days', [0, 1, 2, 3, 4]));
        if (! in_array($now->dayOfWeek, $days, true)) {
            return false;
        }

        // مقارنة نصية لصيغة H:i بتشتغل ترتيبيًا
        $time = $now->format('H:i');

        return $time >= ($this->settings->get('send_window_start') ?? '09:00')
            && $time <= ($this->settings->get('send_window_end') ?? '18:00');
    }

    private function sentTodayCount(Carbon $nowInSendTz): int
    {
        $todayStartUtc = $nowInSendTz->copy()->startOfDay()->utc();

        return OutreachEmail::where('status', OutreachStatus::Sent)
            ->where('sent_at', '>=', $todayStartUtc)
            ->count();
    }

    private function scheduleNextSend(): void
    {
        $min = max(1, $this->settings->getInt('send_min_interval_minutes', 15));
        $max = max($min, $this->settings->getInt('send_max_interval_minutes', 45));

        $this->settings->set(
            'next_send_at',
            Carbon::now('UTC')->addMinutes(random_int($min, $max))->toIso8601ZuluString(),
        );
    }
}
