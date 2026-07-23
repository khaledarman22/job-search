<?php

namespace App\Models;

use App\Enums\OutreachStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachEmail extends Model
{
    protected $fillable = [
        'contact_id', 'company_id', 'job_post_id', 'to_email', 'subject', 'body_html',
        'status', 'is_manual', 'attempts', 'error', 'tracking_token', 'position',
        'queued_at', 'sent_at', 'failed_at', 'opened_at', 'last_opened_at',
        'open_count', 'replied_at', 'smtp_message_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OutreachStatus::class,
            'is_manual' => 'boolean',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobPost(): BelongsTo
    {
        return $this->belongsTo(JobPost::class);
    }

    public function opens(): HasMany
    {
        return $this->hasMany(EmailOpen::class);
    }
}
