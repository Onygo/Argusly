<?php

namespace App\Models;

use App\Support\KeywordSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Legacy SEO metadata storage.
 *
 * @deprecated Phase 1 Refactor: This model is maintained for backwards compatibility only.
 *
 * ## Migration Notes
 *
 * SEO fields have been migrated to typed columns directly on the Content model:
 * - meta_title → Content.seo_title
 * - meta_description → Content.seo_meta_description
 * - primary_keyword → Content.primary_keyword
 * - robots_index → Content.robots_index
 * - robots_follow → Content.robots_follow
 * - schema_type → Content.schema_type
 *
 * ## Usage Guidelines
 *
 * - **New code**: Use Content model SEO fields directly
 * - **Read operations**: Can still use this for legacy data via SeoMetadata::resolveForContentContext()
 * - **Write operations**: Should write to Content model, not ContentSeo
 *
 * @see \App\Models\Content for canonical SEO storage
 * @see \App\Support\SeoMetadata for resolution with backwards compatibility
 *
 * TODO: Phase 2 - Consider soft-deprecating writes, Phase 3 - Archive and remove table
 */
class ContentSeo extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'content_seo';

    protected $fillable = [
        'content_id',
        'meta_title',
        'meta_description',
        'primary_keyword',
        'robots_index',
        'robots_follow',
        'schema_type',
        'secondary_keywords',
        'schema_enabled',
        'toc_enabled',
    ];

    protected $casts = [
        'secondary_keywords' => 'array',
        'schema_enabled' => 'boolean',
        'toc_enabled' => 'boolean',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
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
}
