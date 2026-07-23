<?php

namespace App\Services;

class EmailValidator
{
    /** كاش لكل بروسيس — نتيجة فحص الـ DNS لكل دومين */
    private static array $domainCache = [];

    public function isValid(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));
        if ($domain === '') {
            return false;
        }

        return self::$domainCache[$domain] ??= $this->domainAcceptsMail($domain);
    }

    private function domainAcceptsMail(string $domain): bool
    {
        // النقطة النهائية بتمنع إلحاق الـ search domain المحلي بالاستعلام
        $mxErrored = false;
        try {
            if (checkdnsrr($domain.'.', 'MX')) {
                return true;
            }
        } catch (\Throwable) {
            $mxErrored = true;
        }

        // دومين بدون MX بس له A record — السيرفرات بتقبله عبر الـ implicit MX
        $aErrored = false;
        try {
            if (checkdnsrr($domain.'.', 'A')) {
                return true;
            }
        } catch (\Throwable) {
            $aErrored = true;
        }

        // قرار مقصود: لو الاستعلامين فشلوا بخطأ (resolver واقع / أوفلاين) نعتبر
        // الإيميل صالحًا — عطل مؤقت في الـ DNS ماينفعش يجمّد الطابور كله.
        return $mxErrored && $aErrored;
    }
}
