<?php

namespace App\Models;

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ContentDecayRiskLevel;
use App\Enums\ContentDiscoveryMethod;
use App\Enums\ContentIntelligenceStatus;
use App\Enums\ContentInventorySourceType;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentManagementType;
use App\Enums\ContentOriginType;
use App\Enums\ContentReviewStatus;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\SupportedLanguage;
use App\Enums\WordPressPostType;
use App\Services\Content\ContentSeriesArticleSyncService;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\DescriptionSanitizer;
use App\Support\KeywordSanitizer;
use App\Support\SeoMetadata;
use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Represents a content item (article, page, etc.) in Argusly.
 *
 * ## Architecture Notes (Phase 1 Refactor)
 *
 * ### SEO Fields - Content is the Single Source of Truth
 * The Content model is the canonical owner for all SEO metadata:
 * - seo_title, seo_meta_description, seo_h1, seo_canonical
 * - seo_og_title, seo_og_description, seo_og_image
 * - seo_twitter_title, seo_twitter_description
 * - robots_index, robots_follow, schema_type, primary_keyword
 *
 * Draft SEO fields are transitional (for editing) and sync to Content on approval.
 * ContentSeo is a legacy table maintained for backwards compatibility.
 *
 * @see SeoMetadata for resolution logic
 * @see ContentSeo (deprecated, legacy compatibility only)
 *
 * ### Remote ID - ContentPublication is the Single Source of Truth
 * For remote identifiers (WordPress post IDs, etc.), use ContentPublication.remote_id
 * instead of the legacy Content.wp_post_id field.
 * @see ContentPublication for canonical publication tracking
 * @see Content::getCanonicalRemoteId() for backwards-compatible resolution
 *
 * ### Delivery Status - ContentPublication is Authoritative
 * The Content.delivery_status field is a shadow/sync from ContentPublication for
 * backwards compatibility. Use ContentPublication.delivery_status for new code.
 * @see ContentPublication::deliveryStatusEnum()
 * @see Content::resolveDeliveryStatus() for backwards-compatible resolution
 *
 * ### Versioning - Two Systems
 * ContentRevision: Numbered snapshots (R1, R2...) tied to specific Draft records (legacy)
 * ContentVersion: Hierarchical tree with parent-child lineage (preferred for new code)
 * @see ContentRevision (legacy numbered snapshots)
 * @see ContentVersion (new hierarchical versioning)
 */
class Content extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const ANSWER_BLOCK_STATUS_QUEUED = 'queued';

    public const ANSWER_BLOCK_STATUS_RUNNING = 'running';

    public const ANSWER_BLOCK_STATUS_COMPLETED = 'completed';

    public const ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING = 'completed_with_warning';

    public const ANSWER_BLOCK_STATUS_FAILED = 'failed';

    public const ANSWER_BLOCK_RENDER_MODE_DISABLED = 'disabled';

    public const ANSWER_BLOCK_RENDER_MODE_INLINE = 'inline';

    public const ANSWER_BLOCK_RENDER_MODE_BOTTOM = 'bottom';

    public const ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED = 'ai_optimized';

    public const ANSWER_BLOCK_VISIBILITY_HIDDEN = 'hidden';

    public const ANSWER_BLOCK_VISIBILITY_VISIBLE = 'visible';

    public const ANSWER_BLOCK_POSITION_INLINE = 'inline';

    public const ANSWER_BLOCK_POSITION_BOTTOM = 'bottom';

    public const ANSWER_BLOCK_POSITION_AI_OPTIMIZED = 'ai_optimized';

    /**
     * @var array<string,bool>
     */
    private static array $familyIdColumnSupportCache = [];

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'content_destination_id',
        'series_id',
        'title',
        'language',
        'family_id',
        'translation_source_content_id',
        'translation_source_version_id',
        'translation_source_locale',
        'is_source_locale',
        'sync_with_source',
        'auto_publish',
        'translation_generated_at',
        'translation_source_updated_at',
        'source_content_updated_at_snapshot',
        'locale_repair_meta',
        'seo_title',
        'seo_meta_description',
        'public_blog_excerpt',
        'public_blog_reading_time_minutes',
        'public_blog_author',
        'public_blog_category',
        'public_blog_tags',
        'public_blog_featured_image_url',
        'public_blog_featured_image_width',
        'public_blog_featured_image_height',
        'seo_h1',
        'seo_canonical',
        'seo_og_title',
        'seo_og_description',
        'seo_og_image',
        'seo_twitter_title',
        'seo_twitter_description',
        'robots_index',
        'robots_follow',
        'schema_type',
        'primary_keyword',
        'intent_keys',
        'type',
        'status',
        'source',
        'origin_type',
        'automation_id',
        'automation_run_id',
        'source_chain_suggestion_id',
        'external_id',
        'external_key',
        'dedupe_fingerprint',
        'duplicate_checked_at',
        'dedupe_was_reused',
        'dedupe_reused_at',
        'dedupe_reuse_reason',
        'duplicate_of_content_id',
        'wp_post_id',
        'delivery_status',
        'scheduled_publish_at',
        'first_published_at',
        'publish_status',
        'publish_error',
        'published_url',
        'publish_url_key',
        'canonical_url_key',
        'inventory_source_type',
        'management_type',
        'discovery_method',
        'original_url',
        'normalized_url',
        'canonical_url',
        'url_hash',
        'content_fingerprint',
        'http_status',
        'first_seen_at',
        'last_seen_at',
        'last_fetched_at',
        'external_modified_at',
        'external_changed_at',
        'review_status',
        'campaign_eligible',
        'inventory_metadata',
        'generation_mode',
        'brand_voice_id',
        'buyer_persona_id',
        'team_member_id',
        'writer_profile_id',
        'preferred_length',
        'actual_word_count',
        'aeo_score',
        'content_health_score',
        'ai_visibility_score',
        'semantic_coverage_score',
        'freshness_score',
        'internal_link_score',
        'answer_block_score',
        'translation_parity_score',
        'competitor_freshness_risk',
        'optimization_opportunity_score',
        'decay_risk_level',
        'intelligence_status',
        'content_intelligence_computed_at',
        'ai_optimized_at',
        'aeo_breakdown',
        'image_prompt_instructions',
        'current_revision_id',
        'current_version_id',
        'internal_links_meta',
        'answer_block_generation_status',
        'answer_block_generation_persisted_count',
        'answer_block_generation_draft_revision_id',
        'answer_block_generation_started_at',
        'answer_block_generation_completed_at',
        'answer_block_generation_failed_at',
        'answer_block_generation_last_error',
        'answer_block_generation_last_warning',
        'answer_block_generation_meta',
        'answer_block_render_mode',
        'answer_block_visibility',
        'answer_block_position',
        'answer_block_max_visible',
        'last_feedback_at',
        'reviewed_at',
        'created_by',
        'updated_by',
        // Lifecycle management fields
        'lifecycle_stage',
        'assigned_user_id',
        'reviewer_user_id',
        'due_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected $casts = [
        'last_feedback_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'scheduled_publish_at' => 'datetime',
        'first_published_at' => 'datetime',
        'duplicate_checked_at' => 'datetime',
        'dedupe_was_reused' => 'boolean',
        'dedupe_reused_at' => 'datetime',
        'source' => ContentSource::class,
        'origin_type' => ContentOriginType::class,
        'translation_generated_at' => 'datetime',
        'translation_source_updated_at' => 'datetime',
        'source_content_updated_at_snapshot' => 'datetime',
        'public_blog_reading_time_minutes' => 'integer',
        'public_blog_tags' => 'array',
        'public_blog_featured_image_width' => 'integer',
        'public_blog_featured_image_height' => 'integer',
        'actual_word_count' => 'integer',
        'aeo_score' => 'integer',
        'content_health_score' => 'integer',
        'ai_visibility_score' => 'integer',
        'semantic_coverage_score' => 'integer',
        'freshness_score' => 'integer',
        'internal_link_score' => 'integer',
        'answer_block_score' => 'integer',
        'translation_parity_score' => 'integer',
        'competitor_freshness_risk' => 'integer',
        'optimization_opportunity_score' => 'integer',
        'decay_risk_level' => ContentDecayRiskLevel::class,
        'intelligence_status' => ContentIntelligenceStatus::class,
        'content_intelligence_computed_at' => 'datetime',
        'ai_optimized_at' => 'datetime',
        'aeo_breakdown' => 'array',
        'answer_block_generation_persisted_count' => 'integer',
        'answer_block_generation_meta' => 'array',
        'answer_block_max_visible' => 'integer',
        'intent_keys' => 'array',
        'internal_links_meta' => 'array',
        'locale_repair_meta' => 'array',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'is_source_locale' => 'boolean',
        'sync_with_source' => 'boolean',
        'auto_publish' => 'boolean',
        'language' => SupportedLanguage::class,
        'deleted_at' => 'datetime',
        'answer_block_generation_started_at' => 'datetime',
        'answer_block_generation_completed_at' => 'datetime',
        'answer_block_generation_failed_at' => 'datetime',
        // Lifecycle management casts
        'lifecycle_stage' => ContentLifecycleStatus::class,
        'inventory_source_type' => ContentInventorySourceType::class,
        'management_type' => ContentManagementType::class,
        'discovery_method' => ContentDiscoveryMethod::class,
        'review_status' => ContentReviewStatus::class,
        'campaign_eligible' => 'boolean',
        'http_status' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        'external_modified_at' => 'datetime',
        'external_changed_at' => 'datetime',
        'inventory_metadata' => 'array',
        'due_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $content): void {
            if ($content->series_id) {
                app(ContentSeriesArticleSyncService::class)->syncContent($content);

                return;
            }

            app(ContentSeriesArticleSyncService::class)->detachContent($content);
        });

        static::deleting(function (self $content): void {
            app(ContentSeriesArticleSyncService::class)->detachContent($content);
        });
    }

    /**
     * @return array<int, string>
     */
    public function getIntentsAttribute(): array
    {
        return (array) ($this->intent_keys ?? []);
    }

    public function setTitleAttribute(mixed $value): void
    {
        $this->attributes['title'] = $this->sanitizeTitleAttribute($value, 'title');
    }

    public function setSeoTitleAttribute(mixed $value): void
    {
        $this->attributes['seo_title'] = $value === null ? null : $this->sanitizeTitleAttribute($value, 'seo_title');
    }

    public function setSeoH1Attribute(mixed $value): void
    {
        $this->attributes['seo_h1'] = $value === null ? null : $this->sanitizeTitleAttribute($value, 'seo_h1');
    }

    public function setSeoOgTitleAttribute(mixed $value): void
    {
        $this->attributes['seo_og_title'] = $value === null ? null : $this->sanitizeTitleAttribute($value, 'seo_og_title');
    }

    public function setSeoTwitterTitleAttribute(mixed $value): void
    {
        $this->attributes['seo_twitter_title'] = $value === null ? null : $this->sanitizeTitleAttribute($value, 'seo_twitter_title');
    }

    private function sanitizeTitleAttribute(mixed $value, string $attribute): string
    {
        $result = TitleSanitizer::normalizeWithMetadata($value);

        if ($result['was_shortened']) {
            Log::notice('content.title_shortened', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'attribute' => $attribute,
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'max_length' => $result['max_length'],
            ]);
        }

        return $result['title'];
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
        $stringValue = $value instanceof ContentSource ? $value->value : trim((string) $value);
        $normalized = ContentPersistencePayloadNormalizer::normalizeSource($value);

        if ($stringValue === '' || $stringValue !== $normalized->value) {
            Log::warning('content.source_normalized', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'original_value' => $stringValue,
                'normalized_value' => $normalized->value,
            ]);
        }

        $this->attributes['source'] = $normalized->value;
    }

    public function setTypeAttribute(mixed $value): void
    {
        $stringValue = $value instanceof ContentType ? $value->value : trim((string) $value);
        $normalized = ContentType::normalize($stringValue);

        if ($stringValue !== '' && $stringValue !== $normalized->value) {
            Log::info('content.type_normalized', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'original_value' => $stringValue,
                'normalized_value' => $normalized->value,
            ]);
        }

        $this->attributes['type'] = $normalized->value;
    }

    public function setSeoMetaDescriptionAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['seo_meta_description'] = null;

            return;
        }

        $result = DescriptionSanitizer::normalizeWithMetadata($value, maxLength: DescriptionSanitizer::META_DESCRIPTION_MAX);
        $this->attributes['seo_meta_description'] = $result['description'] !== '' ? $result['description'] : null;

        if ($result['was_sanitized']) {
            Log::notice('content.description_sanitized', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'attribute' => 'seo_meta_description',
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'was_truncated' => $result['was_truncated'],
                'was_rejected' => $result['was_rejected'],
                'rejection_reason' => $result['rejection_reason'],
            ]);
        }
    }

    public function setSeoOgDescriptionAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['seo_og_description'] = null;

            return;
        }

        $result = DescriptionSanitizer::normalizeWithMetadata($value, maxLength: DescriptionSanitizer::OG_DESCRIPTION_MAX);
        $this->attributes['seo_og_description'] = $result['description'] !== '' ? $result['description'] : null;

        if ($result['was_sanitized']) {
            Log::notice('content.description_sanitized', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'attribute' => 'seo_og_description',
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'was_truncated' => $result['was_truncated'],
                'was_rejected' => $result['was_rejected'],
                'rejection_reason' => $result['rejection_reason'],
            ]);
        }
    }

    public function setSeoTwitterDescriptionAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['seo_twitter_description'] = null;

            return;
        }

        $result = DescriptionSanitizer::normalizeWithMetadata($value, maxLength: DescriptionSanitizer::TWITTER_DESCRIPTION_MAX);
        $this->attributes['seo_twitter_description'] = $result['description'] !== '' ? $result['description'] : null;

        if ($result['was_sanitized']) {
            Log::notice('content.description_sanitized', [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'attribute' => 'seo_twitter_description',
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'was_truncated' => $result['was_truncated'],
                'was_rejected' => $result['was_rejected'],
                'rejection_reason' => $result['rejection_reason'],
            ]);
        }
    }

    public function setSeoCanonicalAttribute(mixed $value): void
    {
        $this->attributes['seo_canonical'] = DescriptionSanitizer::normalizeCanonicalUrl($value);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function publicationReadiness(): HasOne
    {
        return $this->hasOne(ProgrammaticPublicationReadiness::class);
    }

    public function publicationPlanItems(): HasMany
    {
        return $this->hasMany(ProgrammaticPublicationPlanItem::class);
    }

    public function series()
    {
        return $this->belongsTo(ContentSeries::class, 'series_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(ContentAutomation::class, 'automation_id');
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(ContentAutomationRun::class, 'automation_run_id');
    }

    public function sourceChainSuggestion(): BelongsTo
    {
        return $this->belongsTo(ContentChainSuggestion::class, 'source_chain_suggestion_id');
    }

    public function seriesArticle(): HasOne
    {
        return $this->hasOne(ContentSeriesArticle::class, 'content_id');
    }

    public function brandVoice()
    {
        return $this->belongsTo(BrandVoice::class);
    }

    public function buyerPersona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'buyer_persona_id');
    }

    public function teamMember()
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function writerProfile(): BelongsTo
    {
        return $this->belongsTo(WriterProfile::class);
    }

    public function brief()
    {
        return $this->hasOne(Brief::class);
    }

    public function drafts()
    {
        return $this->hasMany(Draft::class);
    }

    public function aiTransparencyRecord(): HasOne
    {
        return $this->hasOne(AiTransparencyRecord::class);
    }

    public function revisions()
    {
        return $this->hasMany(ContentRevision::class);
    }

    public function currentRevision()
    {
        return $this->belongsTo(ContentRevision::class, 'current_revision_id');
    }

    public function feedback()
    {
        return $this->hasMany(ContentFeedback::class);
    }

    public function seo()
    {
        return $this->hasOne(ContentSeo::class);
    }

    public function publishTargets()
    {
        return $this->hasMany(ContentPublishTarget::class);
    }

    public function publications()
    {
        return $this->hasMany(ContentPublication::class);
    }

    public function campaignContents(): HasMany
    {
        return $this->hasMany(CampaignContent::class);
    }

    public function pageLinks(): HasMany
    {
        return $this->hasMany(ContentPageLink::class);
    }

    public function primaryPageLinks(): HasMany
    {
        return $this->pageLinks()->where('is_primary', true);
    }

    public function monitoredPages(): BelongsToMany
    {
        return $this->belongsToMany(MonitoredPage::class, 'content_page_links')
            ->withPivot(['id', 'workspace_id', 'client_site_id', 'link_type', 'is_primary', 'confidence_score', 'metadata'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function socialPostVariants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function translationRequests(): HasMany
    {
        return $this->hasMany(ContentTranslation::class);
    }

    public function translationRequestForLocale(string $locale): ?ContentTranslation
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;

        $translation = null;

        if ($this->relationLoaded('translationRequests')) {
            $translation = $this->translationRequests
                ->first(fn (ContentTranslation $translation): bool => $translation->target_locale === $resolvedLocale);
        } else {
            $translation = $this->translationRequests()
                ->where('target_locale', $resolvedLocale)
                ->latest('updated_at')
                ->first();
        }

        if ($translation instanceof ContentTranslation) {
            $translation->reconcileRecoverableLockState();
        }

        return $translation;
    }

    public function activeTranslationRequestForLocale(string $locale): ?ContentTranslation
    {
        $translation = $this->translationRequestForLocale($locale);

        if (! $translation) {
            return null;
        }

        if (! $translation->isActiveLock()) {
            return null;
        }

        return $translation;
    }

    public function localizationRecommendations(): HasMany
    {
        // Content-detail insight widgets are scoped to this exact content row.
        // Translation families do not currently share AgentRun history.
        return $this->hasMany(AgentRun::class)
            ->where('agent_key', LocalizationAgent::KEY)
            ->whereIn('trigger_type', ['manual', 'event']);
    }

    public function refreshRecommendations(): HasMany
    {
        // This remains per-content, not family-wide, until a canonical family aggregation exists.
        return $this->hasMany(AgentRun::class)
            ->where('agent_key', ContentRefreshAgent::KEY)
            ->whereIn('trigger_type', ['manual', 'event']);
    }

    public function internalLinkSuggestions(): HasMany
    {
        // Keep internal linking history tied to the current content_id for now.
        return $this->hasMany(AgentRun::class)
            ->where('agent_key', InternalLinkingAgent::KEY)
            ->whereIn('trigger_type', ['manual', 'event']);
    }

    public function improvementRuns(): HasMany
    {
        return $this->hasMany(ContentImprovementRun::class)->latest('created_at');
    }

    public function aiVisibilitySnapshots(): HasMany
    {
        return $this->hasMany(ContentAiVisibilitySnapshot::class)->latest('captured_at');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(ContentRecommendation::class)->latest('created_at');
    }

    public function lifecycleAnalyses(): HasMany
    {
        return $this->hasMany(ContentLifecycleAnalysis::class)->latest('analyzed_at');
    }

    public function latestLifecycleAnalysis(): HasOne
    {
        return $this->hasOne(ContentLifecycleAnalysis::class)->latestOfMany('analyzed_at');
    }

    public function refreshTasks(): HasMany
    {
        return $this->hasMany(ContentRefreshTask::class)->latest('created_at');
    }

    public function learningProfile(): HasOne
    {
        return $this->hasOne(ContentLearningProfile::class);
    }

    public function learningRecommendations(): HasMany
    {
        return $this->hasMany(LearningRecommendation::class)->latest('recommended_at');
    }

    public function indexationHealth(): HasOne
    {
        return $this->hasOne(ContentIndexationHealth::class);
    }

    public function translationSourceContent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'translation_source_content_id');
    }

    public function familyRoot(): BelongsTo
    {
        if (static::supportsFamilyId()) {
            return $this->belongsTo(self::class, 'family_id');
        }

        return $this->belongsTo(self::class, 'translation_source_content_id');
    }

    public function localizedVariants(): HasMany
    {
        if (static::supportsFamilyId()) {
            return $this->hasMany(self::class, 'family_id', 'family_id')
                ->whereKeyNot($this->id);
        }

        if ($this->translation_source_content_id) {
            return $this->hasMany(self::class, 'translation_source_content_id', 'translation_source_content_id')
                ->whereKeyNot($this->id);
        }

        return $this->hasMany(self::class, 'translation_source_content_id', 'id')
            ->whereKeyNot($this->id);
    }

    public function legacyMarketingBlogRedirects(): HasMany
    {
        return $this->hasMany(MarketingBlogRedirect::class, 'target_content_id');
    }

    public function renderArtifacts()
    {
        return $this->hasMany(ContentRenderArtifact::class);
    }

    public function markdownArtifacts()
    {
        return $this->renderArtifacts();
    }

    public function answerBlocks(): HasMany
    {
        return $this->hasMany(StructuredAnswerBlock::class)->orderBy('order');
    }

    /**
     * Get the publication record for a specific destination.
     */
    public function publicationForDestination(?string $destinationId = null, ?string $clientSiteId = null): ?ContentPublication
    {
        if ($destinationId) {
            return $this->publications()
                ->where('destination_id', $destinationId)
                ->first();
        }

        if ($clientSiteId) {
            return $this->publications()
                ->where('client_site_id', $clientSiteId)
                ->whereNull('destination_id')
                ->first();
        }

        return null;
    }

    /**
     * Resolve or create a publication for this content + destination.
     */
    public function resolvePublication(
        ?string $destinationId = null,
        ?string $clientSiteId = null,
        string $provider = ContentPublication::PROVIDER_WORDPRESS
    ): ContentPublication {
        return ContentPublication::resolveForDelivery(
            $this->id,
            $destinationId,
            $clientSiteId,
            $provider,
            $this->language,
        );
    }

    public function syncAttempts()
    {
        return $this->hasMany(ContentDestinationSyncAttempt::class);
    }

    public function creditLogs()
    {
        return $this->hasMany(ContentCreditLog::class);
    }

    public function performanceMetrics()
    {
        return $this->hasMany(ContentPerformanceMetric::class);
    }

    public function outgoingLinkOpportunities()
    {
        return $this->hasMany(LinkOpportunity::class, 'source_content_id');
    }

    public function incomingLinkOpportunities()
    {
        return $this->hasMany(LinkOpportunity::class, 'target_content_id');
    }

    public function pillarClusters()
    {
        return $this->hasMany(ContentCluster::class, 'pillar_content_id');
    }

    public function chainGuidance(): HasOne
    {
        return $this->hasOne(ContentChainGuidance::class);
    }

    public function outboundChainSuggestions(): HasMany
    {
        return $this->hasMany(ContentChainSuggestion::class, 'source_content_id');
    }

    public function inboundChainSuggestions(): HasMany
    {
        return $this->hasMany(ContentChainSuggestion::class, 'target_content_id');
    }

    public function versions()
    {
        return $this->hasMany(ContentVersion::class)->orderByDesc('created_at');
    }

    public function images()
    {
        return $this->hasMany(ContentImage::class)->latest('content_images.created_at');
    }

    public function imageVersions()
    {
        return $this->hasMany(ContentImage::class)->latest('content_images.created_at');
    }

    public function featuredImage()
    {
        return $this->hasOne(ContentImage::class, 'content_id')
            ->where(function ($query): void {
                $query->where('content_images.type', 'featured')
                    ->orWhere('content_images.display_as_featured_image', true)
                    ->orWhere('content_images.display_on_website', true);
            })
            ->where('content_images.is_active', true)
            ->select([
                'content_images.id',
                'content_images.workspace_id',
                'content_images.content_id',
                'content_images.campaign_id',
                'content_images.social_publication_id',
                'content_images.social_post_variant_id',
                'content_images.type',
                'content_images.source',
                'content_images.image_path',
                'content_images.image_url',
                'content_images.original_filename',
                'content_images.mime_type',
                'content_images.alt_text',
                'content_images.original_path',
                'content_images.medium_path',
                'content_images.thumbnail_path',
                'content_images.original_webp_path',
                'content_images.medium_webp_path',
                'content_images.thumbnail_webp_path',
                'content_images.width',
                'content_images.height',
                'content_images.status',
                'content_images.is_active',
                'content_images.display_on_website',
                'content_images.display_as_featured_image',
                'content_images.use_as_meta_image',
                'content_images.use_as_social_image',
                'content_images.use_for_linkedin',
                'content_images.metadata',
                'content_images.created_at',
                'content_images.updated_at',
                'content_images.deleted_at',
            ])
            ->ofMany(
                ['created_at' => 'max'],
                function ($query): void {
                    $query->where(function ($nested): void {
                        $nested->where('content_images.type', 'featured')
                            ->orWhere('content_images.display_as_featured_image', true)
                            ->orWhere('content_images.display_on_website', true);
                    })
                        ->where('content_images.is_active', true);
                }
            );
    }

    public function ogImage()
    {
        return $this->hasOne(ContentImage::class)
            ->ofMany(
                ['created_at' => 'max'],
                function ($query): void {
                    $query->where(function ($nested): void {
                        $nested->where('content_images.type', 'og')
                            ->orWhere('content_images.use_as_meta_image', true);
                    })
                        ->where('content_images.is_active', true);
                }
            );
    }

    public function currentVersion()
    {
        return $this->belongsTo(ContentVersion::class, 'current_version_id');
    }

    public function translationSourceVersion(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'translation_source_version_id');
    }

    public function briefVersion()
    {
        return $this->hasOne(ContentVersion::class)
            ->where('type', 'brief')
            ->latestOfMany('created_at');
    }

    public function draftVersion()
    {
        return $this->hasOne(ContentVersion::class)
            ->whereIn('type', ['draft', 'revision'])
            ->latestOfMany('created_at');
    }

    public function publishTargetForLanguage(SupportedLanguage $language): ?ContentPublishTarget
    {
        return $this->publishTargets()->forLanguage($language)->first();
    }

    public function scopePublishedInLocale($query, string $locale)
    {
        return $query
            ->where('language', SupportedLanguage::fromStringOrDefault($locale)->value)
            ->where('status', 'published')
            ->where('publish_status', 'published');
    }

    public function scopeWithOriginType(Builder $query, string|ContentOriginType $type): Builder
    {
        $value = $type instanceof ContentOriginType ? $type->value : $type;

        return $query->where('origin_type', $value);
    }

    public function draftsForLanguage(SupportedLanguage $language)
    {
        return $this->drafts()->where('language', $language->value);
    }

    public function markdownArtifact(?string $locale = null): ?ContentRenderArtifact
    {
        $resolvedLocale = $locale !== null && trim($locale) !== ''
            ? SupportedLanguage::fromStringOrDefault($locale)->value
            : $this->language->value;

        if ($this->relationLoaded('renderArtifacts')) {
            return $this->renderArtifacts
                ->first(fn (ContentRenderArtifact $artifact) => $artifact->markdown_locale?->value === $resolvedLocale);
        }

        return $this->renderArtifacts()
            ->forLocale($resolvedLocale)
            ->first();
    }

    public function hasMarkdown(?string $locale = null): bool
    {
        return $this->markdownArtifact($locale)?->hasMarkdown() ?? false;
    }

    public function markdownChecksum(?string $locale = null): ?string
    {
        return $this->markdownArtifact($locale)?->markdown_checksum;
    }

    public function markdownLocale(?string $locale = null): string
    {
        return $this->markdownArtifact($locale)?->markdown_locale?->value
            ?? ($locale ? SupportedLanguage::fromStringOrDefault($locale)->value : $this->language->value);
    }

    public function localizationSource(): self
    {
        $this->loadMissing('familyRoot', 'translationSourceContent');

        return $this->familyRoot ?: $this->translationSourceContent ?: $this;
    }

    public function localizationRootId(): string
    {
        if (static::supportsFamilyId()) {
            return (string) ($this->family_id ?: $this->translation_source_content_id ?: $this->id);
        }

        return (string) ($this->translation_source_content_id ?: $this->id);
    }

    public function scopeWhereInLocalizationRoots(Builder $query, array $rootIds): Builder
    {
        $rootIds = collect($rootIds)
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($rootIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            DB::raw(static::localizationRootExpression($query->getModel()->getTable())),
            $rootIds
        );
    }

    public static function supportsFamilyId(): bool
    {
        $model = new static;
        $connection = $model->getConnectionName() ?: config('database.default', 'default');
        $table = $model->getTable();
        $cacheKey = $connection.':'.$table.':family_id';

        return self::$familyIdColumnSupportCache[$cacheKey]
            ??= Schema::connection($model->getConnectionName())
                ->hasColumn($table, 'family_id');
    }

    public static function localizationRootExpression(?string $table = null): string
    {
        $table ??= (new static)->getTable();

        $qualify = static fn (string $column) => sprintf('%s.%s', $table, $column);

        if (static::supportsFamilyId()) {
            return sprintf(
                'COALESCE(%s, %s, %s)',
                $qualify('family_id'),
                $qualify('translation_source_content_id'),
                $qualify('id')
            );
        }

        return sprintf(
            'COALESCE(%s, %s)',
            $qualify('translation_source_content_id'),
            $qualify('id')
        );
    }

    private static function familyAlias(): string
    {
        return 'family_contents';
    }

    private static function workspaceAlias(): string
    {
        return 'family_workspaces';
    }

    private static function contentTableName(): string
    {
        return (new static)->getTable();
    }

    private static function familyRootMatchRaw(string $outerTable): string
    {
        return static::localizationRootExpression(static::familyAlias()).' = '.static::localizationRootExpression($outerTable);
    }

    private static function expectedLocalesSubquery(string $outerTable): string
    {
        $connection = app('db')->connection((new static)->getConnectionName());
        $jsonLength = $connection->getDriverName() === 'sqlite' ? 'json_array_length' : 'JSON_LENGTH';
        $workspaceCount = sprintf(
            '(SELECT %s(%s.enabled_content_languages) FROM workspaces %s WHERE %s.id = %s.workspace_id LIMIT 1)',
            $jsonLength,
            static::workspaceAlias(),
            static::workspaceAlias(),
            static::workspaceAlias(),
            $outerTable
        );

        return sprintf(
            'CASE WHEN COALESCE(%s, 1) > 1 THEN COALESCE(%s, 1) ELSE 1 END',
            $workspaceCount,
            $workspaceCount,
        );
    }

    private static function availableLocalesSubquery(string $outerTable): string
    {
        return sprintf(
            '(SELECT COUNT(DISTINCT %1$s.language) FROM %2$s %1$s WHERE %3$s AND %1$s.deleted_at IS NULL)',
            static::familyAlias(),
            static::contentTableName(),
            static::familyRootMatchRaw($outerTable),
        );
    }

    private static function publishedVariantsSubquery(string $outerTable): string
    {
        return sprintf(
            '(SELECT COUNT(DISTINCT %1$s.id) FROM %2$s %1$s WHERE %3$s AND %1$s.deleted_at IS NULL AND (%1$s.publish_status = \'published\' OR %1$s.status = \'published\' OR EXISTS (SELECT 1 FROM content_publications family_publications WHERE family_publications.content_id = %1$s.id AND family_publications.delivery_status IN (\'delivered\', \'partial_success\'))))',
            static::familyAlias(),
            static::contentTableName(),
            static::familyRootMatchRaw($outerTable),
        );
    }

    private function scopeWhereFamilyExists(Builder $query, callable $callback): Builder
    {
        $outerTable = $query->getModel()->getTable();

        return $query->whereExists(function ($subquery) use ($callback, $outerTable): void {
            $subquery
                ->selectRaw('1')
                ->from(static::contentTableName().' as '.static::familyAlias())
                ->whereRaw(static::familyRootMatchRaw($outerTable));

            $callback($subquery, static::familyAlias());
        });
    }

    private function scopeWhereFamilyNotExists(Builder $query, callable $callback): Builder
    {
        $outerTable = $query->getModel()->getTable();

        return $query->whereNotExists(function ($subquery) use ($callback, $outerTable): void {
            $subquery
                ->selectRaw('1')
                ->from(static::contentTableName().' as '.static::familyAlias())
                ->whereRaw(static::familyRootMatchRaw($outerTable));

            $callback($subquery, static::familyAlias());
        });
    }

    public function isTranslationVariant(): bool
    {
        return $this->translation_source_content_id !== null;
    }

    public function localeCode(): string
    {
        $rawLanguage = $this->getRawOriginal('language');

        if (is_string($rawLanguage) && trim($rawLanguage) !== '') {
            return SupportedLanguage::fromStringOrDefault($rawLanguage)->value;
        }

        return $this->language instanceof SupportedLanguage
            ? $this->language->value
            : SupportedLanguage::fromStringOrDefault((string) $this->language)->value;
    }

    public function answerBlockGenerationIsActive(): bool
    {
        return in_array((string) $this->answer_block_generation_status, [
            self::ANSWER_BLOCK_STATUS_QUEUED,
            self::ANSWER_BLOCK_STATUS_RUNNING,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function answerBlockRenderModes(): array
    {
        return [
            self::ANSWER_BLOCK_RENDER_MODE_DISABLED,
            self::ANSWER_BLOCK_RENDER_MODE_INLINE,
            self::ANSWER_BLOCK_RENDER_MODE_BOTTOM,
            self::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function answerBlockVisibilities(): array
    {
        return [
            self::ANSWER_BLOCK_VISIBILITY_HIDDEN,
            self::ANSWER_BLOCK_VISIBILITY_VISIBLE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function answerBlockPositions(): array
    {
        return [
            self::ANSWER_BLOCK_POSITION_INLINE,
            self::ANSWER_BLOCK_POSITION_BOTTOM,
            self::ANSWER_BLOCK_POSITION_AI_OPTIMIZED,
        ];
    }

    public static function resolveAnswerBlockRenderModeSetting(?string $renderMode, ?string $visibility, ?string $position): ?string
    {
        if ($visibility === self::ANSWER_BLOCK_VISIBILITY_HIDDEN) {
            return self::ANSWER_BLOCK_RENDER_MODE_DISABLED;
        }

        if (is_string($renderMode) && in_array($renderMode, self::answerBlockRenderModes(), true)) {
            return $renderMode;
        }

        if ($visibility === self::ANSWER_BLOCK_VISIBILITY_VISIBLE && is_string($position) && in_array($position, self::answerBlockPositions(), true)) {
            return $position;
        }

        return null;
    }

    /**
     * @return Collection<int,self>
     */
    public function localizationFamily(): Collection
    {
        $source = $this->localizationSource();
        $source->loadMissing('localizedVariants');

        return collect([$source])
            ->merge($source->localizedVariants)
            ->unique(fn (self $content): string => (string) $content->id)
            ->sortBy(function (self $content) use ($source): array {
                return [
                    (string) $content->id === (string) $source->id ? 0 : 1,
                    $content->localeCode(),
                    -1 * (int) ($content->updated_at?->timestamp ?? 0),
                    (string) $content->id,
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int,self>
     */
    public function normalizedLocalizationFamily(): Collection
    {
        $source = $this->localizationSource();

        return $this->localizationFamily()
            ->groupBy(fn (self $variant): string => $variant->localeCode())
            ->map(fn (Collection $variants): self => $this->selectCanonicalLocaleVariant($variants, $source))
            ->sortBy(function (self $variant) use ($source): array {
                return [
                    (string) $variant->id === (string) $source->id ? 0 : 1,
                    $variant->localeCode(),
                    -1 * (int) ($variant->updated_at?->timestamp ?? 0),
                    (string) $variant->id,
                ];
            })
            ->values();
    }

    public function localizedVariantFor(string $locale): ?self
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;

        return $this->normalizedLocalizationFamily()
            ->first(fn (self $variant): bool => $variant->localeCode() === $resolvedLocale);
    }

    public function isPublishedForTranslation(): bool
    {
        return (string) $this->status === 'published'
            || (string) ($this->publish_status ?? '') === 'published';
    }

    public function isDeliveredForTranslation(): bool
    {
        if ($this->isPublishedForTranslation()) {
            return true;
        }

        return in_array($this->resolveDeliveryStatus(), [
            ContentPublication::STATUS_DELIVERED,
            'delivered',
            'partial_success',
        ], true);
    }

    public function translationSourceLifecycle(): ?string
    {
        if ($this->isPublishedForTranslation()) {
            return 'published';
        }

        if ($this->isDeliveredForTranslation()) {
            return 'delivered';
        }

        return null;
    }

    public function isTranslationOutdated(): bool
    {
        if (! $this->isTranslationVariant()) {
            return false;
        }

        $source = $this->translationSourceContent;
        if (! $source) {
            return false;
        }

        $sourceUpdatedAt = collect([
            optional($source->currentVersion)->updated_at,
            optional($source->currentVersion)->created_at,
            $source->updated_at,
        ])->filter()->sortDesc()->first();

        $baseline = $this->translation_generated_at
            ?: $this->translation_source_updated_at;

        if (! $sourceUpdatedAt || ! $baseline) {
            return false;
        }

        return $sourceUpdatedAt->gt($baseline);
    }

    private function selectCanonicalLocaleVariant(Collection $variants, self $source): self
    {
        return $variants
            ->sortBy(fn (self $variant): array => $this->canonicalLocaleVariantPriority($variant, $source))
            ->first() ?? $variants->firstOrFail();
    }

    /**
     * @return array<int,int|string>
     */
    private function canonicalLocaleVariantPriority(self $variant, self $source): array
    {
        return [
            (string) $variant->id === (string) $source->id ? 0 : 1,
            (bool) $variant->is_source_locale ? 0 : 1,
            (string) $variant->status === 'archived' ? 1 : 0,
            $variant->isPublishedForTranslation() ? 0 : 1,
            $variant->isDeliveredForTranslation() ? 0 : 1,
            -1 * (int) ($variant->updated_at?->timestamp ?? 0),
            (int) ($variant->created_at?->timestamp ?? 0),
            (string) $variant->id,
        ];
    }

    /**
     * Get the WordPress post type for this content.
     *
     * Resolution order:
     * 1. Series content_type (if content belongs to a series)
     * 2. Content.type field mapping
     * 3. Default to POST
     */
    public function wordPressPostType(): WordPressPostType
    {
        // If content belongs to a series, use the series content type
        if ($this->series_id && $this->relationLoaded('series') && $this->series) {
            return $this->series->wordPressPostType();
        }

        if ($this->series_id) {
            $series = $this->series()->first();
            if ($series) {
                return $series->wordPressPostType();
            }
        }

        // Fall back to mapping from content type
        return WordPressPostType::fromContentType($this->type);
    }

    // =========================================================================
    // SEO Resolution (Phase 1 Refactor - Content is Single Source of Truth)
    // =========================================================================

    /**
     * Resolve all SEO metadata for this content.
     *
     * Returns the canonical SEO fields from Content, with fallback to legacy
     * ContentSeo and other sources for backwards compatibility.
     *
     * @param  array<string, mixed>  $extraSources  Additional fallback sources
     * @return array<string, mixed> Normalized SEO metadata
     */
    public function resolveSeoMetadata(array ...$extraSources): array
    {
        return SeoMetadata::resolveForContentContext($this, ...$extraSources);
    }

    /**
     * Check if this content has all primary SEO fields populated.
     *
     * Primary fields: seo_title, seo_meta_description, primary_keyword
     */
    public function hasCompleteSeo(): bool
    {
        return trim((string) $this->seo_title) !== ''
            && trim((string) $this->seo_meta_description) !== ''
            && trim((string) $this->primary_keyword) !== '';
    }

    /**
     * Sync SEO fields from a Draft to this Content.
     *
     * This is the canonical write path for SEO - Draft edits flow to Content.
     *
     * @param  Draft  $draft  The draft to sync SEO fields from
     * @param  bool  $overwriteExisting  Whether to overwrite existing Content values
     * @return bool True if any fields were updated
     */
    public function syncSeoFromDraft(Draft $draft, bool $overwriteExisting = true): bool
    {
        $seoFields = [
            'seo_title', 'seo_meta_description', 'seo_h1', 'seo_canonical',
            'seo_og_title', 'seo_og_description', 'seo_og_image',
            'seo_twitter_title', 'seo_twitter_description',
            'robots_index', 'robots_follow', 'schema_type',
        ];

        $changes = [];
        foreach ($seoFields as $field) {
            $draftValue = $draft->{$field};
            $contentValue = $this->{$field};

            // Skip if draft value is empty
            if ($draftValue === null || (is_string($draftValue) && trim($draftValue) === '')) {
                continue;
            }

            // Skip if content already has value and we're not overwriting
            if (! $overwriteExisting && $contentValue !== null && (! is_string($contentValue) || trim($contentValue) !== '')) {
                continue;
            }

            $changes[$field] = $draftValue;
        }

        // Also sync primary_keyword from brief if available
        if ($draft->brief && trim((string) $draft->brief->primary_keyword) !== '') {
            if ($overwriteExisting || trim((string) $this->primary_keyword) === '') {
                $changes['primary_keyword'] = $draft->brief->primary_keyword;
            }
        }

        if ($changes === []) {
            return false;
        }

        $this->forceFill($changes)->save();

        return true;
    }

    // =========================================================================
    // Remote ID Resolution (Phase 1 Refactor - ContentPublication is Canonical)
    // =========================================================================

    /**
     * Get the canonical remote ID for a specific destination.
     *
     * This is the preferred method for resolving remote identifiers.
     * Uses ContentPublication.remote_id as the single source of truth.
     *
     * @param  string|null  $destinationId  Specific destination, or null for primary
     * @param  string|null  $clientSiteId  Fallback to client site if no destination
     * @return string|null The remote ID (e.g., WordPress post ID)
     */
    public function getCanonicalRemoteId(?string $destinationId = null, ?string $clientSiteId = null): ?string
    {
        $publication = $this->publicationForDestination($destinationId, $clientSiteId);

        if ($publication && $publication->hasRemoteId()) {
            return $publication->remote_id;
        }

        // If no destination specified, try to get from first available publication
        if (! $destinationId && ! $clientSiteId) {
            $publication = $this->publications()->whereNotNull('remote_id')->first();
            if ($publication) {
                return $publication->remote_id;
            }
        }

        // Legacy fallback: Content.wp_post_id (deprecated)
        // TODO: Phase 2 - Remove this fallback once all code uses ContentPublication
        if (trim((string) $this->wp_post_id) !== '') {
            return $this->wp_post_id;
        }

        return null;
    }

    /**
     * Check if content has been published to any remote destination.
     *
     * Prefers ContentPublication records, falls back to legacy wp_post_id.
     */
    public function hasRemotePublication(): bool
    {
        // Check ContentPublication (canonical source)
        if ($this->publications()->whereNotNull('remote_id')->exists()) {
            return true;
        }

        // Legacy fallback (deprecated)
        // TODO: Phase 2 - Remove this fallback
        return trim((string) $this->wp_post_id) !== '';
    }

    /**
     * Get all remote publications for this content.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ContentPublication>
     */
    public function getRemotePublications(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->publications()->whereNotNull('remote_id')->get();
    }

    /**
     * @deprecated Use getCanonicalRemoteId() instead. This field will be removed in Phase 2.
     *
     * Legacy accessor for wp_post_id. New code should use ContentPublication.remote_id.
     */
    public function getLegacyWpPostId(): ?string
    {
        return trim((string) $this->wp_post_id) !== '' ? $this->wp_post_id : null;
    }

    // =========================================================================
    // Delivery Status Resolution (Phase 1 Refactor - ContentPublication is Authoritative)
    // =========================================================================

    /**
     * Resolve the canonical delivery status for this content.
     *
     * Uses ContentPublication.delivery_status as the authoritative source.
     * Falls back to Content.delivery_status for backwards compatibility.
     *
     * @param  string|null  $destinationId  Specific destination, or null for any
     * @param  string|null  $clientSiteId  Fallback to client site if no destination
     * @return string The delivery status (pending, delivered, failed, etc.)
     */
    public function resolveDeliveryStatus(?string $destinationId = null, ?string $clientSiteId = null): string
    {
        $publication = $this->publicationForDestination($destinationId, $clientSiteId);

        if ($publication) {
            return $publication->delivery_status ?? ContentPublication::STATUS_PENDING;
        }

        // If no destination specified, find the most relevant publication
        if (! $destinationId && ! $clientSiteId) {
            if ($this->relationLoaded('publications')) {
                $publication = $this->publications
                    ->sortBy(fn (ContentPublication $publication): array => [
                        match ((string) $publication->delivery_status) {
                            'delivered' => 1,
                            'partial_success' => 2,
                            default => 3,
                        },
                        -1 * max(
                            (int) ($publication->last_delivered_at?->timestamp ?? 0),
                            (int) ($publication->updated_at?->timestamp ?? 0),
                            (int) ($publication->created_at?->timestamp ?? 0),
                        ),
                    ])
                    ->first();

                if ($publication) {
                    return $publication->delivery_status ?? ContentPublication::STATUS_PENDING;
                }
            }

            // Prefer delivered publications, then any publication
            $publication = $this->publications()
                ->orderByRaw("CASE delivery_status WHEN 'delivered' THEN 1 WHEN 'partial_success' THEN 2 ELSE 3 END")
                ->first();

            if ($publication) {
                return $publication->delivery_status ?? ContentPublication::STATUS_PENDING;
            }
        }

        // Legacy fallback: Content.delivery_status (shadow field)
        // TODO: Phase 2 - Consider removing this fallback
        return $this->delivery_status ?? 'pending';
    }

    /**
     * Check if content has been successfully delivered to any destination.
     */
    public function isDelivered(?string $destinationId = null, ?string $clientSiteId = null): bool
    {
        $status = $this->resolveDeliveryStatus($destinationId, $clientSiteId);

        return in_array($status, [
            ContentPublication::STATUS_DELIVERED,
            'delivered',
            'partial_success',
        ], true);
    }

    /**
     * Check if any delivery to this content has failed.
     */
    public function hasDeliveryFailure(): bool
    {
        return $this->publications()
            ->where('delivery_status', ContentPublication::STATUS_FAILED)
            ->exists();
    }

    /**
     * Get the canonical ContentPublication for delivery operations.
     *
     * @param  string|null  $destinationId  Specific destination
     * @param  string|null  $clientSiteId  Fallback client site
     */
    public function getPrimaryPublication(?string $destinationId = null, ?string $clientSiteId = null): ?ContentPublication
    {
        return $this->publicationForDestination($destinationId, $clientSiteId)
            ?? $this->publications()->first();
    }

    public function seriesRole(): ?string
    {
        if (! $this->series_id) {
            return null;
        }

        $seriesArticle = $this->relationLoaded('seriesArticle')
            ? $this->seriesArticle
            : $this->seriesArticle()->first();

        if (! $seriesArticle) {
            return null;
        }

        return $seriesArticle->role();
    }

    public function isSeriesPillar(): bool
    {
        return $this->seriesRole() === ContentSeriesArticle::ROLE_PILLAR;
    }

    // =========================================================================
    // Lifecycle Management Relationships
    // =========================================================================

    /**
     * User assigned to work on this content.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * User assigned to review this content.
     */
    public function reviewerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * User who approved this content.
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * User who rejected this content.
     */
    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Lifecycle event audit trail.
     */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ContentLifecycleEvent::class)->orderByDesc('created_at');
    }

    // =========================================================================
    // Lifecycle Management Scopes
    // =========================================================================

    /**
     * Filter content by lifecycle stage.
     *
     * @param  ContentLifecycleStatus|string|array<ContentLifecycleStatus|string>  $stage
     */
    public function scopeInLifecycleStage(Builder $query, ContentLifecycleStatus|string|array $stage): Builder
    {
        if (is_array($stage)) {
            $values = array_map(
                fn ($s) => $s instanceof ContentLifecycleStatus ? $s->value : $s,
                $stage
            );

            return $query->whereIn('lifecycle_stage', $values);
        }

        $value = $stage instanceof ContentLifecycleStatus ? $stage->value : $stage;

        return $query->where('lifecycle_stage', $value);
    }

    /**
     * Filter content assigned to a specific user.
     */
    public function scopeAssignedTo(Builder $query, int|string $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }

    /**
     * Filter content where user is the reviewer.
     */
    public function scopeReviewerIs(Builder $query, int|string $userId): Builder
    {
        return $query->where('reviewer_user_id', $userId);
    }

    /**
     * Filter content due before a specific date.
     */
    public function scopeDueBefore(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query->where('due_at', '<', $date);
    }

    /**
     * Filter overdue content (due date passed, not yet published/archived).
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('lifecycle_stage', [
                ContentLifecycleStatus::PUBLISHED->value,
                ContentLifecycleStatus::ARCHIVED->value,
            ]);
    }

    /**
     * Filter content that needs review.
     */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('lifecycle_stage', ContentLifecycleStatus::REVIEW->value);
    }

    /**
     * Filter content needing refresh.
     */
    public function scopeNeedsRefresh(Builder $query): Builder
    {
        return $query->where('lifecycle_stage', ContentLifecycleStatus::REFRESH_NEEDED->value);
    }

    public function scopeDraftState(Builder $query): Builder
    {
        $this->scopeWhereFamilyExists($query, fn ($subquery, string $alias) => $subquery->where("$alias.publish_status", 'draft'));

        return $this->scopeWhereFamilyNotExists($query, fn ($subquery, string $alias) => $subquery->whereIn("$alias.publish_status", ['scheduled', 'publishing', 'published', 'failed']));
    }

    public function scopeScheduledState(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists(
            $query,
            fn ($subquery, string $alias) => $subquery->where("$alias.publish_status", 'scheduled')
        );
    }

    public function scopePublishingState(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists(
            $query,
            fn ($subquery, string $alias) => $subquery->where("$alias.publish_status", 'publishing')
        );
    }

    public function scopePartiallyPublished(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->whereRaw(static::publishedVariantsSubquery($table).' > 0')
            ->whereRaw(static::publishedVariantsSubquery($table).' < '.static::expectedLocalesSubquery($table));
    }

    public function scopeFullyPublished(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->whereRaw(static::availableLocalesSubquery($table).' > 0')
            ->whereRaw(static::availableLocalesSubquery($table).' >= '.static::expectedLocalesSubquery($table))
            ->whereRaw(static::publishedVariantsSubquery($table).' >= '.static::expectedLocalesSubquery($table));
    }

    public function scopeFailedPublication(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists($query, function ($subquery, string $alias): void {
            $subquery
                ->where("$alias.publish_status", 'failed')
                ->orWhereExists(function ($publicationQuery) use ($alias): void {
                    $publicationQuery
                        ->selectRaw('1')
                        ->from('content_publications')
                        ->whereColumn('content_publications.content_id', "$alias.id")
                        ->where('content_publications.delivery_status', ContentPublication::STATUS_FAILED);
                });
        });
    }

    public function scopeArchivedState(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists(
            $query,
            fn ($subquery, string $alias) => $subquery->where("$alias.status", 'archived')
                ->orWhere("$alias.lifecycle_stage", ContentLifecycleStatus::ARCHIVED->value)
        );
    }

    public function scopeNeedsTranslation(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw(static::availableLocalesSubquery($table).' = 1')
            ->whereRaw(static::availableLocalesSubquery($table).' < '.static::expectedLocalesSubquery($table));
    }

    public function scopePartiallyTranslated(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw(static::availableLocalesSubquery($table).' > 1')
            ->whereRaw(static::availableLocalesSubquery($table).' < '.static::expectedLocalesSubquery($table));
    }

    public function scopeFullyTranslated(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw(static::availableLocalesSubquery($table).' >= '.static::expectedLocalesSubquery($table));
    }

    public function scopeTranslationFailed(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists($query, function ($subquery, string $alias): void {
            $subquery->whereExists(function ($translationQuery) use ($alias): void {
                $translationQuery
                    ->selectRaw('1')
                    ->from('content_translations')
                    ->whereColumn('content_translations.content_id', "$alias.id")
                    ->whereIn('content_translations.status', [
                        ContentTranslation::STATUS_FAILED,
                        ContentTranslation::STATUS_INSUFFICIENT_CREDITS,
                    ]);
            });
        });
    }

    public function scopeTranslationProcessing(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists($query, function ($subquery, string $alias): void {
            $subquery->whereExists(function ($translationQuery) use ($alias): void {
                $translationQuery
                    ->selectRaw('1')
                    ->from('content_translations')
                    ->whereColumn('content_translations.content_id', "$alias.id")
                    ->whereIn('content_translations.status', [
                        ContentTranslation::STATUS_QUEUED,
                        ContentTranslation::STATUS_PROCESSING,
                    ]);
            });
        });
    }

    public function scopeAiImprovementsPending(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists($query, function ($subquery, string $alias): void {
            $subquery->whereExists(function ($runQuery) use ($alias): void {
                $runQuery
                    ->selectRaw('1')
                    ->from('content_improvement_runs')
                    ->whereColumn('content_improvement_runs.content_id', "$alias.id")
                    ->whereIn('content_improvement_runs.status', [
                        ContentImprovementRun::STATUS_QUEUED,
                        ContentImprovementRun::STATUS_RUNNING,
                    ]);
            });
        });
    }

    public function scopeAiImprovementsGenerated(Builder $query): Builder
    {
        return $this->scopeWhereFamilyExists($query, function ($subquery, string $alias): void {
            $subquery->whereExists(function ($runQuery) use ($alias): void {
                $runQuery
                    ->selectRaw('1')
                    ->from('content_improvement_runs')
                    ->whereColumn('content_improvement_runs.content_id', "$alias.id")
                    ->whereIn('content_improvement_runs.status', [
                        ContentImprovementRun::STATUS_COMPLETED,
                        ContentImprovementRun::STATUS_NO_CHANGES,
                    ]);
            });
        });
    }

    public function scopeRefreshRecommended(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->needsRefresh()
                ->orWhereExists(function ($runQuery): void {
                    $runQuery
                        ->selectRaw('1')
                        ->from('agent_runs')
                        ->whereColumn('agent_runs.content_id', $this->getTable().'.id')
                        ->where('agent_runs.agent_key', ContentRefreshAgent::KEY)
                        ->whereIn('agent_runs.status', ['success', 'warning'])
                        ->whereRaw('COALESCE(JSON_EXTRACT(agent_runs.output_payload, "$.raw_payload.refresh_score"), JSON_EXTRACT(agent_runs.output_payload, "$.metrics.refresh_score"), 0) >= 35');
                });
        });
    }

    public function scopeStaleContent(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->needsRefresh()
                ->orWhereDate($this->getTable().'.updated_at', '<=', now()->subDays((int) config('content_refresh.thresholds.aging_days', 90)));
        });
    }

    public function scopeRecentlyUpdated(Builder $query): Builder
    {
        return $query->where($this->getTable().'.updated_at', '>=', now()->subDays(7));
    }

    public function scopeLocaleOnly(Builder $query, string $locale): Builder
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;
        $table = $query->getModel()->getTable();

        $query->whereRaw(static::availableLocalesSubquery($table).' = 1');

        return $this->scopeWhereFamilyExists($query, fn ($subquery, string $alias) => $subquery->where("$alias.language", $resolvedLocale));
    }

    public function scopeMissingLocale(Builder $query, string $locale): Builder
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;

        return $query
            ->whereHas('workspace', function (Builder $workspaceQuery) use ($resolvedLocale): void {
                $workspaceQuery
                    ->where('default_content_language', $resolvedLocale)
                    ->orWhereJsonContains('enabled_content_languages', $resolvedLocale);
            });

        return $this->scopeWhereFamilyNotExists($query, fn ($subquery, string $alias) => $subquery->where("$alias.language", $resolvedLocale));
    }

    public function scopeMultiLocaleOnly(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw(static::availableLocalesSubquery($table).' > 1');
    }

    // =========================================================================
    // Lifecycle Management Helpers
    // =========================================================================

    /**
     * Get the lifecycle stage as enum.
     */
    public function lifecycleStageEnum(): ContentLifecycleStatus
    {
        if ($this->lifecycle_stage instanceof ContentLifecycleStatus) {
            return $this->lifecycle_stage;
        }

        return ContentLifecycleStatus::tryFrom((string) $this->lifecycle_stage)
            ?? ContentLifecycleStatus::fromLegacyStatus($this->status);
    }

    /**
     * Check if content is overdue.
     */
    public function isOverdue(): bool
    {
        if (! $this->due_at) {
            return false;
        }

        $stage = $this->lifecycleStageEnum();

        // Published or archived content is never overdue
        if (in_array($stage, [ContentLifecycleStatus::PUBLISHED, ContentLifecycleStatus::ARCHIVED], true)) {
            return false;
        }

        return $this->due_at->isPast();
    }

    /**
     * Check if content is assigned to a specific user.
     */
    public function isAssignedTo(User $user): bool
    {
        return (int) $this->assigned_user_id === (int) $user->id;
    }

    /**
     * Check if user is the designated reviewer for this content.
     */
    public function isReviewerFor(User $user): bool
    {
        return (int) $this->reviewer_user_id === (int) $user->id;
    }

    /**
     * Check if content can transition to a specific stage.
     */
    public function canTransitionTo(ContentLifecycleStatus $target): bool
    {
        return $this->lifecycleStageEnum()->canTransitionTo($target);
    }

    /**
     * Get allowed transitions from current stage.
     *
     * @return array<ContentLifecycleStatus>
     */
    public function allowedTransitions(): array
    {
        return $this->lifecycleStageEnum()->allowedTransitions();
    }

    /**
     * Check if content is in an editable stage.
     */
    public function isInEditableStage(): bool
    {
        return $this->lifecycleStageEnum()->isEditable();
    }

    /**
     * Check if content is ready for delivery.
     */
    public function isReadyForDelivery(): bool
    {
        return $this->lifecycleStageEnum()->isDeliverable();
    }
}
