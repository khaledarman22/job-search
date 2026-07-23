<?php

namespace App\Models;

use App\Enums\RunOrigin;
use App\Enums\RunPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApifyRun extends Model
{
    /** حالات Apify غير المنتهية التي يتابعها الـ poller */
    public const ACTIVE_STATUSES = ['READY', 'RUNNING', 'TIMING-OUT', 'ABORTING'];

    protected $fillable = [
        'apify_run_id', 'actor_id', 'actor_name', 'source_id', 'purpose', 'origin',
        'status', 'input', 'default_dataset_id', 'default_kv_store_id',
        'item_count', 'usage_usd', 'started_at', 'finished_at', 'imported_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => RunPurpose::class,
            'origin' => RunOrigin::class,
            'input' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function jobPosts(): HasMany
    {
        return $this->hasMany(JobPost::class);
    }

    public function isFinished(): bool
    {
        return ! in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'SUCCEEDED';
    }
}
