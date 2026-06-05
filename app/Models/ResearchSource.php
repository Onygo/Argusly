<?php

namespace App\Models;

use App\Enums\ResearchSourceFetchStatus;
use App\Enums\ResearchSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'research_project_id',
        'source_type',
        'source_classification',
        'url',
        'title',
        'content_text',
        'fetch_status',
        'fetched_at',
        'meta',
    ];

    protected $casts = [
        'source_type' => ResearchSourceType::class,
        'fetch_status' => ResearchSourceFetchStatus::class,
        'meta' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class, 'research_project_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ResearchFinding::class, 'research_source_id');
    }
}
