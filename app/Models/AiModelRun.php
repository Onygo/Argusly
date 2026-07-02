<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModelRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'draft_id',
        'provider',
        'model',
        'model_version',
        'run_id',
        'settings',
        'usage',
        'input_hash',
        'output_hash',
        'ran_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'usage' => 'array',
        'ran_at' => 'datetime',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function promptVersions(): HasMany
    {
        return $this->hasMany(AiPromptVersion::class);
    }
}
