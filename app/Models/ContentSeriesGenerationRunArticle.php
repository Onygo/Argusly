<?php

namespace App\Models;

use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ContentSeriesGenerationRunArticle extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_BRIEF = 'brief';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'run_id',
        'series_id',
        'article_number',
        'title',
        'status',
        'content_id',
        'brief_id',
        'draft_id',
        'slug',
        'planned_url',
        'internal_links_to',
        'error_message',
        'started_at',
        'finished_at',
        'attempts',
        'meta',
    ];

    protected $casts = [
        'article_number' => 'integer',
        'internal_links_to' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempts' => 'integer',
        'meta' => 'array',
    ];

    public function setTitleAttribute(mixed $value): void
    {
        $result = TitleSanitizer::normalizeWithMetadata($value, fallback: 'Series article');
        $this->attributes['title'] = $result['title'];

        if ($result['was_shortened']) {
            Log::notice('content_series.run_article_title_shortened', [
                'run_article_id' => $this->getKey(),
                'run_id' => (string) ($this->run_id ?? ''),
                'series_id' => (string) ($this->series_id ?? ''),
                'article_number' => (int) ($this->article_number ?? 0),
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'max_length' => $result['max_length'],
            ]);
        }
    }

    public function run()
    {
        return $this->belongsTo(ContentSeriesGenerationRun::class, 'run_id');
    }

    public function series()
    {
        return $this->belongsTo(ContentSeries::class, 'series_id');
    }

    public function content()
    {
        return $this->belongsTo(Content::class, 'content_id');
    }

    public function brief()
    {
        return $this->belongsTo(Brief::class, 'brief_id');
    }

    public function draft()
    {
        return $this->belongsTo(Draft::class, 'draft_id');
    }
}
