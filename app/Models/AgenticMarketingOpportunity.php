<?php

namespace App\Models;

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Support\AgenticMarketing\AgenticMarketingDedupe;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class AgenticMarketingOpportunity extends Model
{
    use HasUuids;

    protected $fillable = [
        'objective_id',
        'content_id',
        'title',
        'type',
        'priority_score',
        'status',
        'payload',
        'payload_hash',
        'dedupe_hash',
        'open_dedupe_hash',
    ];

    protected $casts = [
        'priority_score' => 'integer',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (AgenticMarketingOpportunity $opportunity): void {
            $opportunity->type = self::normalizeType($opportunity->type);
            $opportunity->status = self::normalizeStatus($opportunity->status);
            $opportunity->content_id = $opportunity->content_id ?: self::payloadContentId($opportunity->payload ?? []);

            $payloadHash = AgenticMarketingDedupe::payloadHash($opportunity->payload ?? []);
            $opportunity->payload_hash = $payloadHash;
            $opportunity->dedupe_hash = AgenticMarketingDedupe::opportunityHash(
                $opportunity->content_id,
                $opportunity->type,
                $payloadHash
            );
            $opportunity->open_dedupe_hash = self::isOpenStatus($opportunity->status)
                ? $opportunity->dedupe_hash
                : null;
        });

        static::created(function (AgenticMarketingOpportunity $opportunity): void {
            app(\App\Services\AgenticMarketing\AgenticMarketingAuditLogger::class)
                ->record($opportunity->loadMissing('objective'), 'opportunity.created', null, $opportunity->attributesToArray());
        });

        static::updated(function (AgenticMarketingOpportunity $opportunity): void {
            app(\App\Services\AgenticMarketing\AgenticMarketingAuditLogger::class)
                ->record($opportunity->loadMissing('objective'), 'opportunity.updated', $opportunity->getOriginal(), $opportunity->getChanges());
        });
    }

    public static function createOrReuseOpen(array $attributes): self
    {
        if (empty($attributes['objective_id'])) {
            throw new InvalidArgumentException('Agentic Marketing opportunities require an objective_id.');
        }

        $attributes['type'] = self::normalizeType($attributes['type'] ?? null);
        $attributes['status'] = self::normalizeStatus($attributes['status'] ?? AgenticMarketingOpportunityStatus::Open->value);
        $attributes['payload'] = (array) ($attributes['payload'] ?? []);
        $attributes['content_id'] = $attributes['content_id'] ?? self::payloadContentId($attributes['payload']);

        $payloadHash = AgenticMarketingDedupe::payloadHash($attributes['payload']);
        $dedupeHash = AgenticMarketingDedupe::opportunityHash(
            $attributes['content_id'] ?? null,
            $attributes['type'] ?? null,
            $payloadHash
        );

        $existing = self::query()
            ->where('objective_id', $attributes['objective_id'])
            ->where('dedupe_hash', $dedupeHash)
            ->open()
            ->first();

        if ($existing) {
            return $existing;
        }

        return self::query()->create(array_merge($attributes, [
            'payload_hash' => $payloadHash,
            'dedupe_hash' => $dedupeHash,
            'open_dedupe_hash' => $dedupeHash,
        ]));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AgenticMarketingOpportunityStatus::Open->value);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgenticMarketingAction::class, 'opportunity_id');
    }

    public function executionPipelines(): HasMany
    {
        return $this->hasMany(AgenticMarketingExecutionPipeline::class, 'opportunity_id');
    }

    public static function normalizeType(?string $type): ?string
    {
        $type = trim((string) $type);

        return AgenticMarketingOpportunityType::tryFrom($type)?->value ?: ($type !== '' ? $type : null);
    }

    public static function normalizeStatus(?string $status): string
    {
        $status = trim((string) $status);

        return AgenticMarketingOpportunityStatus::tryFrom($status)?->value
            ?: AgenticMarketingOpportunityStatus::Open->value;
    }

    public static function isOpenStatus(?string $status): bool
    {
        return AgenticMarketingOpportunityStatus::tryFrom((string) $status)?->isOpen() ?? false;
    }

    private static function payloadContentId(array $payload): ?string
    {
        $contentId = data_get($payload, 'content_id');

        return is_scalar($contentId) && trim((string) $contentId) !== ''
            ? trim((string) $contentId)
            : null;
    }
}
