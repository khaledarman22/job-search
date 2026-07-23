<?php

namespace App\Services;

use App\Mail\OutreachMail;
use App\Models\OutreachEmail;
use App\Support\SettingsRepository;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class MailerService
{
    public function __construct(
        private SettingsRepository $settings,
        private TemplateRenderer $renderer,
    ) {}

    public function isConfigured(): bool
    {
        foreach (['smtp_host', 'smtp_username', 'smtp_password', 'from_email'] as $key) {
            if (blank($this->settings->get($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * الـ SMTP transport في Laravel 13 بيتجاهل مفتاح encryption تمامًا —
     * MailManager::createSmtpTransport بيبني Dsn من scheme (smtps = TLS ضمني،
     * smtp = STARTTLS تلقائي) وEsmtpTransportFactory بيقرأ خيار auto_tls،
     * فبنترجم إعداد smtp_encryption للمفاتيح اللي بتشتغل فعلًا.
     */
    private function buildMailer(): Mailer
    {
        $encryption = $this->settings->get('smtp_encryption');
        $port = $this->settings->getInt('smtp_port', 587);

        $config = [
            'transport' => 'smtp',
            'host' => $this->settings->get('smtp_host'),
            'port' => $port,
            'username' => $this->settings->get('smtp_username'),
            'password' => $this->settings->get('smtp_password'),
            'timeout' => 30,
        ];

        if ($encryption === 'ssl' || $port === 465) {
            $config['scheme'] = 'smtps';
        } elseif ($encryption === 'none' || $encryption === null || $encryption === '') {
            $config['scheme'] = 'smtp';
            $config['auto_tls'] = false;
        } else {
            // tls (الافتراضي) — STARTTLS بيتفاوض عليه تلقائيًا
            $config['scheme'] = 'smtp';
        }

        return Mail::build($config);
    }

    public function send(OutreachEmail $email): void
    {
        $context = $this->renderer->contextFor($email);
        $subject = $this->renderer->render((string) $this->settings->get('email_subject_template'), $context);
        $body = $this->wrapBody(
            $this->renderer->render((string) ($this->settings->get('email_body_template') ?? ''), $context)
        );

        $pixel = '<img src="'.url('/t/'.$email->tracking_token.'.gif').'" width="1" height="1" style="display:none" alt="">';
        $body = str_contains($body, '</body>')
            ? str_replace('</body>', $pixel.'</body>', $body)
            : $body.$pixel;

        // لقطة قبل الإرسال — السجل يفضل صادق حتى لو الإرسال نفسه فشل
        $email->forceFill(['subject' => $subject, 'body_html' => $body])->save();

        $cvPath = $this->cvPath();

        $sent = $this->buildMailer()->to($email->to_email)->send(new OutreachMail(
            subjectLine: $subject,
            htmlBody: $body,
            fromEmail: (string) $this->settings->get('from_email'),
            fromName: (string) ($this->settings->get('from_name') ?? ''),
            cvPath: $cvPath,
            cvName: $cvPath ? $this->settings->get('cv_original_name') : null,
        ));

        if ($sent instanceof SentMessage) {
            $email->forceFill(['smtp_message_id' => $sent->getMessageId()])->save();
        }
    }

    /**
     * إرسال تجريبي للقالب الحالي ببيانات عيّنة — بنفس مرفق الـ CV
     * عشان التجربة تتحقق من الرسالة كاملة زي ما هتوصل فعلًا.
     */
    public function sendTest(string $to): void
    {
        $context = $this->renderer->sampleContext();
        $subject = '[تجربة] '.$this->renderer->render((string) $this->settings->get('email_subject_template'), $context);
        $body = $this->wrapBody(
            $this->renderer->render((string) ($this->settings->get('email_body_template') ?? ''), $context)
        );

        $cvPath = $this->cvPath();

        $this->buildMailer()->to($to)->send(new OutreachMail(
            subjectLine: $subject,
            htmlBody: $body,
            fromEmail: (string) $this->settings->get('from_email'),
            fromName: (string) ($this->settings->get('from_name') ?? ''),
            cvPath: $cvPath,
            cvName: $cvPath ? $this->settings->get('cv_original_name') : null,
        ));
    }

    /** مسار الـ CV لو مرفوع وموجود فعلًا على القرص، وإلا null */
    private function cvPath(): ?string
    {
        $path = $this->settings->get('cv_path');

        return ($path && Storage::disk('local')->exists($path)) ? $path : null;
    }

    /** قالب نصي بدون هيكل HTML → نغلّفه بـ div آمن للـ RTL */
    private function wrapBody(string $body): string
    {
        if (preg_match('/<\s*(html|body)[\s>]/i', $body)) {
            return $body;
        }

        return '<div dir="auto" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.8;">'
            .nl2br($body)
            .'</div>';
    }
}
