<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaClientSite;
use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Services\Content\ContentRenderer;
use App\Services\Content\ContentRenderNormalizer;
use App\Services\ContentVisuals\VisualRenderer;
use App\Support\DescriptionSanitizer;
use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Represents a draft version of content being edited or generated.
 *
 * ## Architecture Notes (Phase 1 Refactor)
 *
 * ### SEO Fields - Transitional Storage
 * Draft SEO fields (seo_title, seo_meta_description, etc.) are used during editing
 * and content generation. On approval, these fields sync to Content, which is the
 * canonical owner.
 *
 * - Draft SEO fields are for work-in-progress editing
 * - Content SEO fields are the single source of truth
 * - Use Content::syncSeoFromDraft() to propagate changes
 *
 * @see \App\Models\Content for canonical SEO storage
 * @see \App\Support\SeoMetadata::resolveForDraftContext() for read resolution
 *
 * ### Delivery Status - Ephemeral/Per-Attempt
 * The delivery_status field on Draft tracks the delivery state of this specific
 * draft attempt. It is NOT the authoritative source for content publication status.
 *
 * - Draft.delivery_status: Per-draft attempt (ephemeral, this draft's delivery)
 * - Content.delivery_status: Shadow/sync field (legacy compatibility)
 * - ContentPublication.delivery_status: Authoritative source for publication state
 *
 * @see \App\Models\ContentPublication for authoritative delivery status
 * @see \App\Enums\PublicationDeliveryStatus for status enum
 *
 * ### Remote References - Transitional Storage
 * The meta.client_refs nested field contains WordPress and other remote references
 * during the delivery process. After delivery, these are persisted to ContentPublication.
 *
 * - meta.client_refs.wp_post_id: Transitional, copied to ContentPublication.remote_id
 * - ContentPublication.remote_id: Canonical storage for remote identifiers
 *
 * @see \App\Models\ContentPublication for canonical remote ID storage
 */
class Draft extends Model
{
    use BelongsToOrganizationViaClientSite;
    use HasUuids;

    protected $fillable = [
        'brief_id',
        'content_id',
        'draft_comparison_id',
        'draft_comparison_variant_id',
        'client_site_id',
        'content_destination_id',
        'status',
        'attempts',
        'title',
        'seo_title',
        'seo_meta_description',
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
        'output_type',
        'language',
        'draft_type',
        'source_draft_id',
        'translation_source_language',
        'model_used',
        'content_html',
        'meta',
        'links',
        'last_error',
        'delivered_at',
        'credit_wallet_id',
        'workspace_credit_wallet_id',
        'credit_action_id',
        'credit_cost',
        'credit_status',
        'credit_ledger_entry_id',
        'workspace_credit_transaction_id',
        'delivery_status',
        'delivery_attempts',
        'delivery_started_at',
        'delivery_last_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'meta' => 'array',
        'links' => 'array',
        'delivered_at' => 'datetime',
        'acked_at' => 'datetime',
        'credit_cost' => 'integer',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'delivery_attempts' => 'integer',
        'delivery_started_at' => 'datetime',
        'language' => SupportedLanguage::class,
        'draft_type' => DraftType::class,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY_FOR_REVIEW = 'ready_for_review';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED_FOR_PUBLISHING = 'approved_for_publishing';
    public const STATUS_ARCHIVED = 'archived';

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
        $result = TitleSanitizer::normalizeWithMetadata($value, allowEmpty: $attribute === 'title');

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

    public function markReadyForReview(User $user): self
    {
        $this->assertOpportunityExecutionDraft();
        $this->assertGovernanceTransition([self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED], self::STATUS_READY_FOR_REVIEW);

        return $this->transitionGovernance(self::STATUS_READY_FOR_REVIEW, [
            'ready_for_review_by' => (string) $user->id,
            'ready_for_review_at' => now()->toIso8601String(),
        ]);
    }

    public function requestChanges(User $user, ?string $note = null): self
    {
        $this->assertOpportunityExecutionDraft();
        $this->assertGovernanceTransition([self::STATUS_READY_FOR_REVIEW], self::STATUS_CHANGES_REQUESTED);

        return $this->transitionGovernance(self::STATUS_CHANGES_REQUESTED, [
            'changes_requested_by' => (string) $user->id,
            'changes_requested_at' => now()->toIso8601String(),
            'changes_requested_note' => trim((string) $note) ?: null,
        ]);
    }

    public function approveForPublishing(User $user): self
    {
        $this->assertOpportunityExecutionDraft();
        $this->assertGovernanceTransition([self::STATUS_READY_FOR_REVIEW], self::STATUS_APPROVED_FOR_PUBLISHING);

        return $this->transitionGovernance(self::STATUS_APPROVED_FOR_PUBLISHING, [
            'approved_for_publishing_by' => (string) $user->id,
            'approved_for_publishing_at' => now()->toIso8601String(),
        ]);
    }

    public function archiveGovernance(User $user): self
    {
        $this->assertOpportunityExecutionDraft();
        $this->assertGovernanceTransition([
            self::STATUS_DRAFT,
            self::STATUS_READY_FOR_REVIEW,
            self::STATUS_CHANGES_REQUESTED,
            self::STATUS_APPROVED_FOR_PUBLISHING,
        ], self::STATUS_ARCHIVED);

        return $this->transitionGovernance(self::STATUS_ARCHIVED, [
            'archived_by' => (string) $user->id,
            'archived_at' => now()->toIso8601String(),
        ]);
    }

    public function isOpportunityExecutionDraft(): bool
    {
        return trim((string) data_get($this->meta, 'source_context.execution_plan_id')) !== ''
            || trim((string) data_get($this->meta, 'source_context.opportunity_execution_plan_id')) !== '';
    }

    private function assertOpportunityExecutionDraft(): void
    {
        if (! $this->isOpportunityExecutionDraft()) {
            throw new RuntimeException('Draft governance is only available for opportunity execution drafts.');
        }
    }

    /**
     * @param array<int,string> $allowedFrom
     */
    private function assertGovernanceTransition(array $allowedFrom, string $to): void
    {
        if (! in_array((string) $this->status, $allowedFrom, true)) {
            throw new RuntimeException(sprintf('Cannot move draft from %s to %s.', (string) $this->status, $to));
        }
    }

    /**
     * @param array<string,mixed> $audit
     */
    private function transitionGovernance(string $status, array $audit): self
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $governance = is_array($meta['governance'] ?? null) ? $meta['governance'] : [];
        $meta['governance'] = array_merge($governance, $audit);

        $this->forceFill([
            'status' => $status,
            'meta' => $meta,
        ])->save();

        return $this->refresh();
    }

    public function setSeoCanonicalAttribute(mixed $value): void
    {
        $this->attributes['seo_canonical'] = DescriptionSanitizer::normalizeCanonicalUrl($value);
    }

    public function setContentHtmlAttribute(mixed $value): void
    {
        $html = trim((string) $value);
        if ($html === '') {
            $this->attributes['content_html'] = $html;

            return;
        }

        $this->attributes['content_html'] = app(ContentRenderNormalizer::class)
            ->removeLegacyPlaceholderResources($html)['html'];
    }

    public function brief()
    {
        return $this->belongsTo(Brief::class);
    }

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function aiTransparencyRecords(): HasMany
    {
        return $this->hasMany(AiTransparencyRecord::class);
    }

    public function draftComparison(): BelongsTo
    {
        return $this->belongsTo(DraftComparison::class, 'draft_comparison_id');
    }

    public function draftComparisonVariantLink(): BelongsTo
    {
        return $this->belongsTo(DraftComparisonVariant::class, 'draft_comparison_variant_id');
    }

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function creditAction()
    {
        return $this->belongsTo(CreditAction::class, 'credit_action_id');
    }

    public function workspaceCreditWallet(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditWallet::class, 'workspace_credit_wallet_id');
    }

    public function workspaceCreditTransaction(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'workspace_credit_transaction_id');
    }

    public function articleEmbedding()
    {
        return $this->hasOne(ArticleEmbedding::class, 'article_id');
    }

    public function articleEntities(): HasMany
    {
        return $this->hasMany(ArticleEntity::class, 'article_id');
    }

    public function outboundLinkSuggestions(): HasMany
    {
        return $this->hasMany(LinkSuggestion::class, 'source_article_id');
    }

    public function inboundLinkSuggestions(): HasMany
    {
        return $this->hasMany(LinkSuggestion::class, 'target_article_id');
    }

    public function batchItems(): HasMany
    {
        return $this->hasMany(ContentBatchItem::class);
    }

    public function comparisonItem(): HasOne
    {
        return $this->hasOne(DraftComparisonItem::class);
    }

    public function comparisonVariant(): HasOne
    {
        return $this->hasOne(DraftComparisonVariant::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(DraftAnalysis::class)->latestOfMany('created_at');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(DraftAnalysis::class)->orderByDesc('created_at');
    }

    public function improvementResults(): HasMany
    {
        return $this->hasMany(DraftImprovementResult::class)->orderByDesc('created_at');
    }

    public function latestImprovementResult(): HasOne
    {
        return $this->hasOne(DraftImprovementResult::class)->latestOfMany('created_at');
    }

    public function recommendationSnapshots(): HasMany
    {
        return $this->hasMany(DraftRecommendation::class)->orderBy('sort_order');
    }

    public function intelligenceDeltas(): HasMany
    {
        return $this->hasMany(DraftIntelligenceDelta::class)->latest('created_at');
    }

    public function sourceDraft(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_draft_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'source_draft_id');
    }

    public function translationsForLanguage(SupportedLanguage $language): ?self
    {
        return $this->translations()->where('language', $language->value)->first();
    }

    public function isTranslation(): bool
    {
        return ($this->draft_type ?? DraftType::ORIGINAL) === DraftType::TRANSLATION;
    }

    public function isOriginal(): bool
    {
        return ($this->draft_type ?? DraftType::ORIGINAL) === DraftType::ORIGINAL;
    }

    public function isHybrid(): bool
    {
        return ($this->draft_type ?? DraftType::ORIGINAL) === DraftType::HYBRID;
    }

    public function canBeTranslated(): bool
    {
        $type = $this->draft_type ?? DraftType::ORIGINAL;

        return $type->canBeTranslated();
    }

    public function getOriginalSourceDraft(): ?self
    {
        if ($this->isOriginal() || $this->isHybrid()) {
            return $this;
        }

        $source = $this->sourceDraft;
        if (! $source) {
            return null;
        }

        if ($source->isTranslation()) {
            return $source->getOriginalSourceDraft();
        }

        return $source;
    }

    public function hasTranslationForLanguage(SupportedLanguage $language): bool
    {
        return $this->translations()->where('language', $language->value)->exists();
    }

    public function getAvailableTranslationLanguages(): array
    {
        $currentLanguage = $this->language;
        $existingLanguages = $this->translations()
            ->pluck('language')
            ->map(fn ($lang) => $lang instanceof SupportedLanguage ? $lang->value : $lang)
            ->toArray();

        return array_filter(
            SupportedLanguage::cases(),
            fn (SupportedLanguage $lang) => $lang !== $currentLanguage &&
                ! in_array($lang->value, $existingLanguages, true)
        );
    }

    public function getRenderedContentHtmlAttribute(): HtmlString
    {
        $html = app(VisualRenderer::class)->renderDraftHtml($this);

        return app(ContentRenderer::class)->renderToHtml($html);
    }

    // 👇 altijd onderaan
    protected static function booted(): void
    {
        static::creating(function (Draft $draft) {
            if ($draft->credit_action_id && ! $draft->credit_cost) {
                $action = CreditAction::query()
                    ->select(['id', 'credits_cost', 'is_active'])
                    ->whereKey($draft->credit_action_id)
                    ->first();

                if ($action && $action->is_active) {
                    $draft->credit_cost = $action->credits_cost;
                }
            }
        });
    }
}
