<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = [
        'name', 'actor_id', 'input_template', 'field_map',
        'default_keywords', 'default_location', 'max_items', 'enabled', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'input_template' => 'array',
            'field_map' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ApifyRun::class);
    }

    public function jobPosts(): HasMany
    {
        return $this->hasMany(JobPost::class);
    }
}
