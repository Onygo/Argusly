<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaClientSite;
use App\Events\Onboarding\BriefCreated as BriefCreatedEvent;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\KeywordSanitizer;
use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Brief extends Model
{
    use BelongsToOrganizationViaClientSite;
    use HasUuids;

    protected $fillable = [
        'client_site_id',
        'content_destination_id',
        'created_by_user_id',
        'wp_brief_id',
        'wp_post_id',
        'wp_site_id',
        'wp_remote_ref',
        'content_id',
        'content_source_id',
        'status',
        'source',
        'progress',
        'title',
        'language',
        'content_type',
        'intent',
        'primary_keyword',
        'secondary_keywords',
        'audience',
        'audience_details',
        'target_audience',
        'funnel_stage',
        'search_intent',
        'output_type',
        'notes',
        'tone_of_voice',
        'unique_angle',
        'key_points',
        'call_to_action',
        'desired_length_min',
        'desired_length_max',
        'client_refs',
    ];

    protected $casts = [
        'progress' => 'float',
        'secondary_keywords' => 'array',
        'key_points' => 'array',
        'desired_length_min' => 'integer',
        'desired_length_max' => 'integer',
        'client_refs' => 'array',
    ];

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function contentSource()
    {
        return $this->belongsTo(ContentSource::class);
    }

    public function drafts()
    {
        return $this->hasMany(Draft::class);
    }

    public function draftComparisons()
    {
        return $this->hasMany(DraftComparison::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function batchItems()
    {
        return $this->hasMany(ContentBatchItem::class);
    }

    public function researchProjects()
    {
        return $this->hasMany(ResearchProject::class);
    }

    public function suggestions()
    {
        return $this->hasMany(BriefSuggestion::class);
    }

    public function setTitleAttribute(mixed $value): void
    {
        $result = TitleSanitizer::normalizeWithMetadata($value);
        $this->attributes['title'] = $result['title'];

        if ($result['was_shortened']) {
            Log::notice('content.title_shortened', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'attribute' => 'title',
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'max_length' => $result['max_length'],
            ]);
        }
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

    public function setSourceAttribute(mixed $value): void
    {
        $this->attributes['source'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'source' => $value,
        ])['source'];
    }

    public function setLanguageAttribute(mixed $value): void
    {
        $this->attributes['language'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'language' => $value,
        ])['language'];
    }

    public function setIntentAttribute(mixed $value): void
    {
        $this->attributes['intent'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'intent' => $value,
        ])['intent'];
    }

    public function setContentTypeAttribute(mixed $value): void
    {
        $this->attributes['content_type'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'content_type' => $value,
        ])['content_type'];
    }

    public function setOutputTypeAttribute(mixed $value): void
    {
        $this->attributes['output_type'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'output_type' => $value,
        ])['output_type'];
    }

    public function setFunnelStageAttribute(mixed $value): void
    {
        $this->attributes['funnel_stage'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'funnel_stage' => $value,
        ])['funnel_stage'];
    }

    public function setSearchIntentAttribute(mixed $value): void
    {
        $this->attributes['search_intent'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'search_intent' => $value,
        ])['search_intent'];
    }

    public function setAudienceAttribute(mixed $value): void
    {
        $normalized = ContentPersistencePayloadNormalizer::normalizeBriefAudience(
            $value,
            $this->attributes['audience_details'] ?? null
        );

        $this->attributes['audience'] = $normalized['audience'];

        if ($normalized['audience_details'] !== null) {
            $this->attributes['audience_details'] = $normalized['audience_details'];
        }
    }

    public function setAudienceDetailsAttribute(mixed $value): void
    {
        $this->attributes['audience_details'] = ContentPersistencePayloadNormalizer::normalizeBrief([
            'audience_details' => $value,
        ])['audience_details'];
    }

    protected static function booted(): void
    {
        static::created(function (Brief $brief): void {
            BriefCreatedEvent::dispatch((string) $brief->id);
        });
    }
}
