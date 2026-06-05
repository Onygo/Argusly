<?php

namespace App\Models;

use App\Enums\ResearchFindingType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchFinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'research_project_id',
        'research_source_id',
        'finding_type',
        'finding_text',
        'citations',
        'confidence_score',
        'is_selected',
        'meta',
    ];

    protected $casts = [
        'finding_type' => ResearchFindingType::class,
        'citations' => 'array',
        'confidence_score' => 'float',
        'is_selected' => 'boolean',
        'meta' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class, 'research_project_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class, 'research_source_id');
    }
}
