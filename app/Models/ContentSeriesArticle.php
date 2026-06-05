<?php

namespace App\Models;

use App\Support\KeywordSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ContentSeriesArticle extends Model
{
    use HasFactory;
    use HasUuids;

    public const ROLE_PILLAR = 'pillar';
    public const ROLE_SUPPORTING = 'supporting';

    protected $fillable = [
        'series_id',
        'content_id',
        'article_number',
        'title',
        'primary_keyword',
        'secondary_keywords',
        'internal_links_to',
        'planned_url',
        'is_pillar',
        'pillar_series_id',
        'meta',
    ];

    protected $casts = [
        'article_number' => 'integer',
        'secondary_keywords' => 'array',
        'internal_links_to' => 'array',
        'is_pillar' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            $article->pillar_series_id = $article->is_pillar
                ? (string) $article->series_id
                : null;
        });
    }

    public function setPrimaryKeywordAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['primary_keyword'] = null;

            return;
        }

        $result = KeywordSanitizer::normalizeWithMetadata($value);

        if ($result['was_sanitized']) {
            Log::notice('content.keyword_sanitized', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'attribute' => 'primary_keyword',
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'was_truncated' => $result['was_truncated'],
                'was_rejected' => $result['was_rejected'],
                'rejection_reason' => $result['rejection_reason'],
            ]);
        }

        $this->attributes['primary_keyword'] = $result['keyword'] !== '' ? $result['keyword'] : null;
    }

    public function series()
    {
        return $this->belongsTo(ContentSeries::class, 'series_id');
    }

    public function content()
    {
        return $this->belongsTo(Content::class, 'content_id');
    }

    public function role(): string
    {
        return $this->is_pillar ? self::ROLE_PILLAR : self::ROLE_SUPPORTING;
    }

    public function roleLabel(): string
    {
        return $this->is_pillar ? 'Pillar' : 'Supporting';
    }
}
