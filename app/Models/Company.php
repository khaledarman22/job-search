<?php

namespace App\Models;

use App\Enums\EnrichmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = [
        'name', 'normalized_name', 'website', 'domain', 'domain_checked_at', 'location',
        'enrichment_status', 'enrich_run_id', 'enriched_at',
    ];

    protected function casts(): array
    {
        return [
            'enrichment_status' => EnrichmentStatus::class,
            'enriched_at' => 'datetime',
            'domain_checked_at' => 'datetime',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function jobPosts(): HasMany
    {
        return $this->hasMany(JobPost::class);
    }

    public function enrichRun(): BelongsTo
    {
        return $this->belongsTo(ApifyRun::class, 'enrich_run_id');
    }

    public static function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    /** يستخرج الدومين من رابط الموقع (بدون www) */
    public static function extractDomain(?string $website): ?string
    {
        if (! $website) {
            return null;
        }
        $url = str_contains($website, '://') ? $website : "https://{$website}";
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? Str::of($host)->lower()->after('www.')->toString() : null;
    }
}
