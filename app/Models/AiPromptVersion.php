<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPromptVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'ai_model_run_id',
        'version',
        'prompt_type',
        'prompt_text',
        'redacted_prompt_summary',
        'prompt_hash',
        'contains_redactions',
        'captured_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'contains_redactions' => 'boolean',
        'captured_at' => 'datetime',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(AiModelRun::class, 'ai_model_run_id');
    }
}
