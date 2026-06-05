<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftAnalysis extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'draft_id',
        'status',
        'seo_score',
        'readability_score',
        'cta_score',
        'headings_score',
        'llm_visibility_score',
        'brand_voice_fit_score',
        'conversion_fit_score',
        'trust_evidence_score',
        'publish_readiness_score',
        'publish_readiness_status',
        'publish_readiness_blocking_issues',
        'publish_readiness_next_actions',
        'keyword_coverage',
        'entity_coverage',
        'internal_link_opportunities',
        'suggestions',
        'normalized_payload',
        'signals_payload',
        'analysis_model',
        'analysis_provider',
        'prompt_version',
        'snapshot_signature',
        'tokens_used',
        'raw_response',
        'parser_errors',
        'validation_errors',
        'created_at',
    ];

    protected $casts = [
        'seo_score' => 'integer',
        'readability_score' => 'integer',
        'cta_score' => 'integer',
        'headings_score' => 'integer',
        'llm_visibility_score' => 'integer',
        'brand_voice_fit_score' => 'integer',
        'conversion_fit_score' => 'integer',
        'trust_evidence_score' => 'integer',
        'publish_readiness_score' => 'integer',
        'publish_readiness_blocking_issues' => 'array',
        'publish_readiness_next_actions' => 'array',
        'keyword_coverage' => 'integer',
        'entity_coverage' => 'integer',
        'internal_link_opportunities' => 'array',
        'suggestions' => 'array',
        'normalized_payload' => 'array',
        'signals_payload' => 'array',
        'tokens_used' => 'integer',
        'parser_errors' => 'array',
        'validation_errors' => 'array',
        'created_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(DraftRecommendation::class)->orderBy('sort_order');
    }

    public function improvementDeltasAfter(): HasMany
    {
        return $this->hasMany(DraftIntelligenceDelta::class, 'after_analysis_id');
    }

    public function improvementDeltasBefore(): HasMany
    {
        return $this->hasMany(DraftIntelligenceDelta::class, 'before_analysis_id');
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function hasUsableData(): bool
    {
        return in_array($this->effective_status, [self::STATUS_COMPLETED, self::STATUS_PARTIAL], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function canonicalPayload(): array
    {
        $payload = $this->normalized_payload;
        if (is_array($payload) && $payload !== []) {
            return $payload;
        }

        return is_array($this->suggestions) ? $this->suggestions : [];
    }

    public function getEffectiveStatusAttribute(): string
    {
        $status = (string) ($this->status ?: self::STATUS_COMPLETED);
        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return $status;
        }

        $payload = $this->canonicalPayload();
        $sections = (array) data_get($payload, 'sections', []);
        $present = 0;
        $explained = 0;
        $improvements = 0;

        foreach (['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'] as $key) {
            $section = data_get($sections, $key, []);
            $score = data_get($section, 'score');
            $explanation = trim((string) data_get($section, 'explanation', ''));
            $sectionImprovements = collect((array) data_get($section, 'improvements', []))
                ->filter(fn (mixed $item): bool => trim((string) $item) !== '')
                ->count();

            if (is_numeric($score) || $explanation !== '' || $sectionImprovements > 0) {
                $present++;
            }

            if ($explanation !== '') {
                $explained++;
            }

            $improvements += $sectionImprovements;
        }

        if ($present === 0 && $explained === 0 && $improvements === 0) {
            return self::STATUS_FAILED;
        }

        if (
            $status === self::STATUS_COMPLETED
            && ($present < 4 || $explained < 3 || $improvements < 3)
        ) {
            return self::STATUS_PARTIAL;
        }

        return $status;
    }

    /**
     * @return array<int,string>
     */
    public function getMissingSectionsAttribute(): array
    {
        $missing = [];
        $sections = (array) data_get($this->canonicalPayload(), 'sections', []);

        foreach (['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'] as $key) {
            $section = data_get($sections, $key, []);
            $score = data_get($section, 'score');
            $explanation = trim((string) data_get($section, 'explanation', ''));
            $improvements = collect((array) data_get($section, 'improvements', []))
                ->filter(fn (mixed $item): bool => trim((string) $item) !== '')
                ->count();

            if (! is_array($section) || (! is_numeric($score) && $explanation === '' && $improvements === 0)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @return array<int,string>
     */
    public function getAvailableSectionsAttribute(): array
    {
        return array_diff(
            ['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'],
            $this->missing_sections
        );
    }
}
