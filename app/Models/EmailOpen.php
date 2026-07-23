<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailOpen extends Model
{
    public $timestamps = false;

    protected $fillable = ['outreach_email_id', 'opened_at', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime'];
    }

    public function outreachEmail(): BelongsTo
    {
        return $this->belongsTo(OutreachEmail::class);
    }
}
