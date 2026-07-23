<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\DiscoveredVia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $fillable = [
        'company_id', 'job_post_id', 'email', 'phone', 'name', 'position',
        'discovered_via', 'is_alternate', 'status',
    ];

    protected function casts(): array
    {
        return [
            'discovered_via' => DiscoveredVia::class,
            'is_alternate' => 'boolean',
            'status' => ContactStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobPost(): BelongsTo
    {
        return $this->belongsTo(JobPost::class);
    }

    public function sightings(): HasMany
    {
        return $this->hasMany(ContactJobSighting::class);
    }

    public function outreachEmails(): HasMany
    {
        return $this->hasMany(OutreachEmail::class);
    }

    /** رابط واتساب مباشر لو فيه رقم */
    public function whatsappUrl(): ?string
    {
        if (! $this->phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $this->phone);

        return $digits ? "https://wa.me/{$digits}" : null;
    }
}
