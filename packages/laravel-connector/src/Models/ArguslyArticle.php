<?php

declare(strict_types=1);

namespace Argusly\LaravelConnector\Models;

use Illuminate\Database\Eloquent\Model;

class ArguslyArticle extends Model
{
    protected $table = 'argusly_articles';

    protected $guarded = [];

    protected $casts = [
        'hreflang_alternates' => 'array',
        'answer_blocks' => 'array',
        'structured_output' => 'array',
        'schema_data' => 'array',
        'ai_visibility' => 'array',
        'metadata' => 'array',
        'published_at' => 'datetime',
        'source_updated_at' => 'datetime',
    ];
}
