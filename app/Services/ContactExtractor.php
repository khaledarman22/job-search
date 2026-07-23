<?php

namespace App\Services;

class ContactExtractor
{
    /** إيميل داخل النص: صيغة عامة، بنفلترها بعدين عبر EmailRanker */
    private const EMAIL_RE = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * موبايل مصري: 01x xxxx xxxx (11 رقم يبدأ 01) مع بادئة اختيارية +20/0020.
     * متحفّظ عمدًا عشان منلتقطش أرقام عشوائية من الوصف.
     */
    private const EG_PHONE_RE = '/(?<![\d])(?:\+?20[\s\-]?|0020[\s\-]?)?0?1[0125](?:[\s\-]?\d){8}(?![\d])/';

    public function __construct(private EmailRanker $ranker) {}

    /**
     * يستخرج الإيميلات من النص، يشيل القمامة، ويرتّبها الأفضل أولًا.
     *
     * @return string[]
     */
    public function emails(?string $text, ?string $companyDomain = null): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        preg_match_all(self::EMAIL_RE, $text, $matches);

        $emails = array_values(array_unique(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            $matches[0],
        )));

        $emails = array_values(array_filter(
            $emails,
            fn (string $e): bool => ! $this->ranker->isGarbage($e),
        ));

        usort(
            $emails,
            fn (string $a, string $b): int => $this->ranker->score($b, $companyDomain) <=> $this->ranker->score($a, $companyDomain),
        );

        return $emails;
    }

    /**
     * يستخرج أرقام موبايل مصرية بصيغة E.164 (+20...).
     *
     * @return string[]
     */
    public function phones(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        preg_match_all(self::EG_PHONE_RE, $text, $matches);

        $phones = [];
        foreach ($matches[0] as $raw) {
            $digits = preg_replace('/\D+/', '', $raw);
            $normalized = $this->normalizeEgyptian($digits);
            if ($normalized !== null) {
                $phones[$normalized] = true;
            }
        }

        return array_keys($phones);
    }

    /** يوحّد الرقم المصري لصيغة +20XXXXXXXXXX أو يرجّع null لو مش صالح */
    private function normalizeEgyptian(string $digits): ?string
    {
        // 0020... → 20...
        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 2);
        }
        // 201XXXXXXXXX (12 رقم مع كود الدولة)
        if (strlen($digits) === 12 && str_starts_with($digits, '20') && $digits[2] === '1') {
            return '+'.$digits;
        }
        // 01XXXXXXXXX (11 رقم محلي)
        if (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            return '+20'.substr($digits, 1);
        }
        // 1XXXXXXXXX (10 رقم بدون صفر)
        if (strlen($digits) === 10 && $digits[0] === '1') {
            return '+20'.$digits;
        }

        return null;
    }
}
