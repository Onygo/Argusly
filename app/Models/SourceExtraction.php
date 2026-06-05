<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceExtraction extends Model
{
    protected $fillable = [
        'tenant_id',
        'url_hash',
        'url',
        'final_url',
        'title',
        'author',
        'published_at',
        'language',
        'summary',
        'extracted_text',
        'word_count',
        'chars',
        'estimated_tokens',
        'method',
        'status',
        'error_code',
        'error_message',
        'duration_ms',
        'metadata',
        'fetched_at',
        'expires_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
