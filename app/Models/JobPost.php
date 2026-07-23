<?php

namespace App\Models;

use App\Enums\JobPostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class JobPost extends Model
{
    protected $fillable = [
        'source_id', 'company_id', 'apify_run_id', 'external_id', 'url', 'url_hash',
        'title', 'location', 'salary', 'description', 'posted_at', 'last_seen_at', 'raw', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobPostStatus::class,
            'raw' => 'array',
            'posted_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function apifyRun(): BelongsTo
    {
        return $this->belongsTo(ApifyRun::class);
    }

    /** تطبيع الرابط قبل الهاش: إزالة باراميترات التتبع والـ slash النهائي */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['host'])) {
            return trim($url);
        }

        $query = '';
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_filter(
                $params,
                fn ($key) => ! Str::startsWith((string) $key, ['utm_', 'ref', 'trk', 'fbclid', 'gclid']),
                ARRAY_FILTER_USE_KEY
            );
            ksort($params);
            $query = $params ? '?'.http_build_query($params) : '';
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$host}{$path}{$query}";
    }

    public static function hashUrl(string $url): string
    {
        return sha1(self::normalizeUrl($url));
    }
}
