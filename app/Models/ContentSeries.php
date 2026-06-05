<?php

namespace App\Models;

use App\Enums\WordPressPostType;
use App\Support\KeywordSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ContentSeries extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_STRATEGY_GENERATED = 'strategy_generated';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY = 'ready';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'content_series';

    protected $fillable = [
        'organization_id',
        'site_id',
        'name',
        'main_topic',
        'primary_keyword',
        'supporting_keywords',
        'intent_keys',
        'audience',
        'tone',
        'funnel_stage',
        'articles_count',
        'content_type',
        'status',
        'is_locked',
        'strategy_json',
        'publish_plan_json',
        'created_by',
    ];

    protected $casts = [
        'supporting_keywords' => 'array',
        'intent_keys' => 'array',
        'articles_count' => 'integer',
        'is_locked' => 'boolean',
        'strategy_json' => 'array',
        'publish_plan_json' => 'array',
        'content_type' => WordPressPostType::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (ContentSeries $series): void {
            if ((string) $series->status === self::STATUS_PUBLISHED) {
                $series->is_locked = true;
            }

            if ($series->is_locked === null) {
                $series->is_locked = false;
            }
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contents()
    {
        return $this->hasMany(Content::class, 'series_id');
    }

    public function seriesArticles(): HasMany
    {
        return $this->hasMany(ContentSeriesArticle::class, 'series_id')
            ->orderBy('article_number');
    }

    public function generationRuns()
    {
        return $this->hasMany(ContentSeriesGenerationRun::class, 'series_id');
    }

    public function getPillarArticle(): ?ContentSeriesArticle
    {
        if ($this->relationLoaded('seriesArticles')) {
            return $this->seriesArticles
                ->first(fn (ContentSeriesArticle $article): bool => (bool) $article->is_pillar);
        }

        return $this->seriesArticles()
            ->where('is_pillar', true)
            ->first();
    }

    /**
     * @return Collection<int,ContentSeriesArticle>
     */
    public function getSupportingArticles(): Collection
    {
        if ($this->relationLoaded('seriesArticles')) {
            return $this->seriesArticles
                ->filter(fn (ContentSeriesArticle $article): bool => ! $article->is_pillar)
                ->values();
        }

        return $this->seriesArticles()
            ->where('is_pillar', false)
            ->get();
    }

    public function hasPillarArticle(): bool
    {
        return $this->getPillarArticle() !== null;
    }

    public function isPublished(): bool
    {
        return (string) $this->status === self::STATUS_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return (string) $this->status === self::STATUS_ARCHIVED;
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_locked || $this->isPublished();
    }

    public function normalizedStatus(): string
    {
        $status = (string) $this->status;

        return match ($status) {
            'strategy_ready' => self::STATUS_STRATEGY_GENERATED,
            'generated' => self::STATUS_READY,
            'publishing' => self::STATUS_SCHEDULED,
            default => $status,
        };
    }

    /**
     * @return array<int,string>
     */
    public static function lifecycleStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_STRATEGY_GENERATED,
            self::STATUS_GENERATING,
            self::STATUS_READY,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
        ];
    }

    /**
     * Get the WordPress post type for this series.
     *
     * Provides a safe accessor that falls back to POST for existing series
     * created before the content_type field was added.
     */
    public function wordPressPostType(): WordPressPostType
    {
        if ($this->content_type instanceof WordPressPostType) {
            return $this->content_type;
        }

        $raw = trim((string) $this->getRawOriginal('content_type'));

        return WordPressPostType::tryFrom($raw) ?? WordPressPostType::POST;
    }

    /**
     * @return array<int, string>
     */
    public function getIntentsAttribute(): array
    {
        return (array) ($this->intent_keys ?? []);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Series are organization-scoped and anchored to a site. This scope keeps
     * the dashboard aligned with the workspace set currently visible to the user
     * without assuming a non-existent content_series.workspace_id column.
     *
     * @param  array<int|string>|Collection<int|string>  $workspaceIds
     */
    public function scopeForWorkspaces(Builder $query, array|Collection $workspaceIds): Builder
    {
        $workspaceIds = collect($workspaceIds)
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($workspaceIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('site', function (Builder $siteQuery) use ($workspaceIds): void {
            $siteQuery->whereIn('workspace_id', $workspaceIds->all());
        });
    }

    public function setIntentsAttribute(mixed $value): void
    {
        $this->intent_keys = \App\Support\ContentIntentCatalog::normalizeKeys(
            is_array($value) ? $value : [$value]
        );
    }
}
