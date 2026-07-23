<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * يحوّل اسم شركة لدومين حقيقي عبر Clearbit autocomplete (endpoint عام ومجاني، من غير API key).
 *
 * السكرابر بيرجّع صفحة LinkedIn/Indeed مش الموقع الفعلي، فالشركات بتفضل domain = NULL
 * والـ enrichment مش بيشتغل. الخدمة دي بتسدّ الفجوة دي.
 *
 * المطابقة متحفّظة عن قصد: دومين غلط = إيميل لشركة تانية، وده أسوأ بكتير من إننا منلاقيش حاجة.
 */
class CompanyDomainResolver
{
    private const ENDPOINT = 'https://autocomplete.clearbit.com/v1/companies/suggest';

    /** أقل طول للاسم بعد التطبيع — أقصر من كده يبقى عام أوي ومش آمن نطابق بيه */
    private const MIN_NAME_LENGTH = 3;

    /**
     * كلمات بتتشال من الطرفين قبل المقارنة (لواحق قانونية / كلمات عامة).
     * "Acme Technologies" و "Acme" لازم يطابقوا بعض.
     */
    private const NOISE_WORDS = [
        'inc', 'llc', 'ltd', 'limited', 'co', 'corp', 'corporation', 'company',
        'group', 'holding', 'holdings', 'technologies', 'technology', 'tech',
        'solutions', 'systems', 'services', 'egypt', 'sa', 'llp', 'plc',
        'gmbh', 'sarl', 'fz', 'fzc', 'fze', 'wll',
    ];

    /** مواقع سوشيال/أجريجيتور — دي نفس مشكلة السكرابر الأصلية، ممنوع نرجّعها */
    private const BLOCKED_HOSTS = [
        'linkedin.com', 'facebook.com', 'fb.com', 'twitter.com', 'x.com',
        'instagram.com', 'youtube.com', 'crunchbase.com', 'wikipedia.org',
        'bayt.com', 'wuzzuf.net', 'glassdoor.com', 'indeed.com',
    ];

    /** أنماط بادئة: أي هوست بيبدأ بواحدة منهم مرفوض (glassdoor.*, indeed.*, jobs.* ...) */
    private const BLOCKED_PREFIXES = [
        'glassdoor.', 'indeed.', 'wuzzuf.', 'jobs.', 'linkedin.', 'facebook.',
    ];

    /**
     * يرجّع الدومين المؤكد للشركة، أو null لو مفيش مطابقة واثقة.
     *
     * @return array{domain: string, name: string}|null
     */
    public function resolve(string $companyName): ?array
    {
        $query = trim($companyName);
        $needle = $this->normalize($query);

        // اسم قصير/فاضي بعد التطبيع → عام أوي، ومش بنستهلك request أصلًا
        if (mb_strlen($needle) < self::MIN_NAME_LENGTH) {
            return null;
        }

        $candidates = $this->suggest($query);

        $matches = [];
        foreach ($candidates as $candidate) {
            $match = $this->matchCandidate($candidate, $needle);
            if ($match !== null) {
                $matches[] = $match;
            }
        }

        if ($matches === []) {
            return null;
        }

        // لو أكتر من مطابقة: .com الأول، وبعدين الأقصر (والأبجدي عشان النتيجة ثابتة)
        usort($matches, static function (array $a, array $b): int {
            return [str_ends_with($a['domain'], '.com') ? 0 : 1, strlen($a['domain']), $a['domain']]
                <=> [str_ends_with($b['domain'], '.com') ? 0 : 1, strlen($b['domain']), $b['domain']];
        });

        return $matches[0];
    }

    /**
     * ينده الـ endpoint ويرجّع قائمة المرشحين — أي فشل بيرجّع [] من غير ما يرمي exception،
     * لأن ده بيتنفّذ في loop على مئات الشركات.
     *
     * @return array<int, mixed>
     */
    private function suggest(string $query): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                // إعادة المحاولة على أخطاء الاتصال و 5xx بس، وبدون رمي RequestException
                ->retry(2, 300, function (\Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError());
                }, throw: false)
                ->get(self::ENDPOINT, ['query' => $query]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * يقارن مرشّح واحد بالاسم المطبّع — لازم تطابق تام بعد التطبيع، مفيش fuzzy خالص.
     *
     * @return array{domain: string, name: string}|null
     */
    private function matchCandidate(mixed $candidate, string $needle): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $name = $candidate['name'] ?? null;
        if (! is_string($name) || $this->normalize($name) !== $needle) {
            return null;
        }

        $domain = $this->sanitizeDomain($candidate['domain'] ?? null);
        if ($domain === null || $this->isBlocked($domain)) {
            return null;
        }

        return ['domain' => $domain, 'name' => $name];
    }

    /**
     * تطبيع للمقارنة: ASCII + lowercase + شيل الترقيم + شيل الكلمات العامة.
     * أسماء مش لاتينية بتبقى فاضية → الجارد بيرجّع null، وده المطلوب (متحفّظ).
     */
    private function normalize(string $value): string
    {
        $value = Str::ascii($value);
        // الأبوستروفي بيتشال خالص عشان "Jerry's" تبقى "jerrys" مش "jerry s"
        $value = str_replace(["'", '`'], '', strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        $tokens = array_filter(
            explode(' ', trim($value)),
            static fn (string $token): bool => $token !== '' && ! in_array($token, self::NOISE_WORDS, true),
        );

        return implode(' ', $tokens);
    }

    /** ينضّف الدومين (يشيل البروتوكول و www والمسار) ويتأكد إنه شكله دومين فعلًا */
    private function sanitizeDomain(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return null;
        }

        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }

        $domain = trim(explode('/', $domain)[0]);
        $domain = trim($domain, '.');

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return null;
        }

        return $domain;
    }

    private function isBlocked(string $domain): bool
    {
        foreach (self::BLOCKED_HOSTS as $host) {
            if ($domain === $host || str_ends_with($domain, '.'.$host)) {
                return true;
            }
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($domain, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
