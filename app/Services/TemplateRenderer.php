<?php

namespace App\Services;

use App\Models\OutreachEmail;
use App\Support\SettingsRepository;

class TemplateRenderer
{
    /** مفاتيح البروفايل اللي بتتسحب من الإعدادات */
    private const PROFILE_KEYS = ['my_name', 'my_title', 'whatsapp', 'phone', 'portfolio', 'github', 'linkedin'];

    public function __construct(private SettingsRepository $settings) {}

    /** استبدال {key} بأقواس مفردة لكل مفتاح في السياق */
    public function render(string $template, array $context): string
    {
        foreach ($context as $key => $value) {
            $template = str_replace('{'.$key.'}', (string) $value, $template);
        }

        // إزالة أي متغيرات متبقية بين أقواس {} لم يتم استبدالها لكي لا تظهر كنص للمستخدم
        $template = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $template);

        return $template;
    }

    public function contextFor(OutreachEmail $email): array
    {
        $job = $email->jobPost;

        $companyName = $email->company?->name ?? $email->contact?->company?->name ?? '';
        $genericDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'mail.com', 'live.com', 'msn.com'];
        if (in_array(strtolower($companyName), $genericDomains, true) || str_contains($companyName, '@')) {
            $companyName = '';
        }
        $companyName = $companyName ?: 'your company';

        return array_merge($this->profileContext(), [
            'company' => $companyName,
            'job_title' => $job?->title ?: 'open',
            'job_url' => $job?->url ?? '',
            'contact_name' => $email->contact?->name ?: 'فريق التوظيف',
        ]);
    }

    /** سياق عيّنة للمعاينة والإرسال التجريبي — بيانات البروفايل الحقيقية */
    public function sampleContext(): array
    {
        return array_merge($this->profileContext(), [
            'company' => 'شركة تجريبية',
            'job_title' => 'Senior Software Engineer',
            'job_url' => 'https://example.com/job',
            'contact_name' => 'HR Team',
        ]);
    }

    private function profileContext(): array
    {
        $context = [];
        foreach (self::PROFILE_KEYS as $key) {
            $context[$key] = $this->settings->get($key) ?? '';
        }

        return $context;
    }
}
