<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmGlobalSetting extends Model
{
    protected $table = 'llm_global_settings';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'default_text_provider',
        'default_image_provider',
        'default_text_model_map',
        'default_image_model_map',
        'timeout_seconds',
        'retry_max',
        'retry_backoff_ms',
    ];

    protected $casts = [
        'default_text_model_map' => 'array',
        'default_image_model_map' => 'array',
        'timeout_seconds' => 'integer',
        'retry_max' => 'integer',
        'retry_backoff_ms' => 'integer',
    ];
}
