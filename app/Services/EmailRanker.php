<?php

namespace App\Services;

class EmailRanker
{
    /** كلمات في الـ local part أو الدومين تدل على إيميلات آلية */
    private const NOREPLY_TOKENS = ['noreply', 'no-reply', 'donotreply'];

    private const GARBAGE_DOMAIN_TOKENS = ['sentry', 'wixpress', 'cloudflare', 'godaddy', 'example.'];

    private const ASSET_EXTENSIONS = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg'];

    private const FREEMAIL_DOMAINS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com'];

    /** درجات حسب بداية الـ local part — الأعلى أولًا */
    private const PREFIX_SCORES = [
        'careers' => 90,
        'jobs' => 85,
        'hr' => 85,
        'recruitment' => 80,
        'recruiting' => 80,
        'talent' => 80,
        'hiring' => 80,
        'apply' => 75,
        'cv' => 75,
        'resume' => 75,
        'join' => 75,
        'info' => 40,
        'contact' => 35,
        'hello' => 35,
        'sales' => 10,
        'support' => 10,
        'marketing' => 10,
        'billing' => 10,
        'admin' => 10,
        'office' => 10,
    ];

    /** درجة إيميل يبدو شخصيًا (اسم شخص مثلًا) */
    private const PERSONAL_SCORE = 60;

    public function isGarbage(string $email): bool
    {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return true;
        }

        if (strlen($email) > 100) {
            return true;
        }

        // الإيميلات المستخرجة من الصفحات كثيرًا ما تكون أسماء ملفات صور بالغلط
        foreach (self::ASSET_EXTENSIONS as $extension) {
            if (str_ends_with($email, $extension)) {
                return true;
            }
        }

        [$local, $domain] = $this->split($email);

        foreach (self::NOREPLY_TOKENS as $token) {
            if (str_contains($local, $token) || str_contains($domain, $token)) {
                return true;
            }
        }

        foreach (self::GARBAGE_DOMAIN_TOKENS as $token) {
            if (str_contains($domain, $token)) {
                return true;
            }
        }

        return false;
    }

    public function score(string $email, ?string $companyDomain): int
    {
        $email = strtolower(trim($email));
        [$local, $domain] = $this->split($email);
        $domain = $this->stripWww($domain);

        $score = self::PERSONAL_SCORE;
        foreach (self::PREFIX_SCORES as $prefix => $value) {
            if (str_starts_with($local, $prefix)) {
                $score = $value;
                break;
            }
        }

        if ($companyDomain !== null && $domain !== '' && $domain === $this->stripWww(strtolower(trim($companyDomain)))) {
            $score += 20;
        }

        if (in_array($domain, self::FREEMAIL_DOMAINS, true)) {
            $score -= 10;
        }

        return $score;
    }

    /** @return array{0: string, 1: string} [local, domain] */
    private function split(string $email): array
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return [$email, ''];
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }

    private function stripWww(string $domain): string
    {
        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
