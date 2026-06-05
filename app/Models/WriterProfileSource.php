<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WriterProfileSource extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'writer_profile_id',
        'content_id',
        'uploaded_file_id',
        'title',
        'source_text',
        'source_url',
        'language',
        'word_count',
        'analyzed_at',
        'metadata',
    ];

    protected $casts = [
        'word_count' => 'integer',
        'analyzed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function writerProfile(): BelongsTo
    {
        return $this->belongsTo(WriterProfile::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
