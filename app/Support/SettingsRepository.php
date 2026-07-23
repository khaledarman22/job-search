<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class SettingsRepository
{
    /** المفاتيح التي تُخزَّن مشفَّرة */
    public const ENCRYPTED = ['apify_token', 'smtp_password'];

    /** القيم الافتراضية — تُستخدم عند غياب الصف من قاعدة البيانات */
    public const DEFAULTS = [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'email_subject_template' => 'Application for {job_title} — {my_name}',
        'send_min_interval_minutes' => '15',
        'send_max_interval_minutes' => '45',
        'send_window_start' => '09:00',
        'send_window_end' => '18:00',
        'send_days' => '[0,1,2,3,4]',
        'send_timezone' => 'Africa/Cairo',
        'daily_send_cap' => '40',
        'sending_enabled' => '0',
        'auto_scrape_enabled' => '0',
        'auto_scrape_time' => '10:00',
        'scrape_random_sources' => '1',
        'auto_queue_enabled' => '1',
        'import_mode' => 'all',
        'enrich_actor_id' => 'vdrmota/contact-info-scraper',
        'enrich_batch_size' => '10',
    ];

    /** كاش لكل الطلب الواحد */
    private ?array $loaded = null;

    public function get(string $key, ?string $default = null): ?string
    {
        $this->load();

        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'on'], true);
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $value = json_encode($value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value !== null) {
            $value = (string) $value;
        }

        $encrypted = in_array($key, self::ENCRYPTED, true);
        $stored = ($encrypted && $value !== null && $value !== '') ? Crypt::encryptString($value) : $value;

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_encrypted' => $encrypted],
        );

        if ($this->loaded !== null) {
            $this->loaded[$key] = $value;
        }
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        if ($this->loaded !== null) {
            unset($this->loaded[$key]);
        }
    }

    /** هل القيمة موجودة فعليًا (وليست الافتراضية)؟ */
    public function has(string $key): bool
    {
        $this->load();

        return isset($this->loaded[$key]) && $this->loaded[$key] !== '';
    }

    public function flushCache(): void
    {
        $this->loaded = null;
    }

    private function load(): void
    {
        if ($this->loaded !== null) {
            return;
        }

        $this->loaded = [];
        foreach (Setting::all() as $setting) {
            $value = $setting->value;
            if ($setting->is_encrypted && $value !== null && $value !== '') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    // APP_KEY اتغيّر — القيمة المشفرة بقت غير صالحة
                    $value = null;
                }
            }
            $this->loaded[$setting->key] = $value;
        }
    }
}
