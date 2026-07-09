<?php

namespace App\Models;

use App\Support\Intelligence\Evidence;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceGraphEdgeType;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingOperatingLink extends Model
{
    use HasUuids;

    public const RELATION_SUPPORTS = 'supports';
    public const RELATION_DRIVES = 'drives';
    public const RELATION_EVIDENCES = 'evidences';
    public const RELATION_RECOMMENDS = 'recommends';
    public const RELATION_REPORTS = 'reports';
    public const RELATION_BRIEFS = 'briefs';
    public const RELATION_MEASURES = 'measures';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'marketing_objective_id',
        'marketing_initiative_id',
        'relationship_type',
        'resource_type',
        'resource_id',
        'resource_key',
        'resource_title',
        'resource_model',
        'confidence_score',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'confidence_score' => 'decimal:4',
        'metadata_json' => 'array',
    ];

    public function scopeForResource(Builder $query, string $resourceType, string $resourceKey): Builder
    {
        return $query->where('resource_type', $resourceType)->where('resource_key', $resourceKey);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(MarketingInitiative::class, 'marketing_initiative_id');
    }

    public function toIntelligenceGraphEdge(): IntelligenceGraphEdge
    {
        return new IntelligenceGraphEdge(
            $this->edgeType(),
            $this->subjectGraphReference(),
            $this->resourceGraphReference(),
            confidence: $this->confidence_score !== null ? (float) $this->confidence_score : null,
            evidence: $this->edgeEvidence(),
            metadata: [
                'marketing_operating_link_id' => (string) $this->getKey(),
                'relationship_type' => $this->relationship_type,
                'resource_type' => $this->resource_type,
                'resource_key' => $this->resource_key,
                'resource_model' => $this->resource_model,
            ] + (array) $this->metadata_json,
            provenance: [
                'projector' => 'marketing_operating_link_read_model',
                'storage_mutated' => false,
            ],
            stage: $this->stageForRelationship(),
        );
    }

    private function subjectGraphReference(): IntelligenceGraphReference
    {
        if ($this->marketing_initiative_id) {
            return IntelligenceGraphReference::initiative(
                $this->marketing_initiative_id,
                $this->relationLoaded('initiative') ? $this->initiative?->name : null,
            );
        }

        return IntelligenceGraphReference::objective(
            (string) $this->marketing_objective_id,
            $this->relationLoaded('objective') ? $this->objective?->name : null,
        );
    }

    private function resourceGraphReference(): IntelligenceGraphReference
    {
        $key = (string) ($this->resource_id ?: $this->resource_key);
        $title = $this->resource_title ?: $this->resource_key;

        return match ($this->resource_type) {
            'marketing_objective' => IntelligenceGraphReference::objective($key, $title),
            'marketing_initiative' => IntelligenceGraphReference::initiative($key, $title),
            'monitored_page' => IntelligenceGraphReference::page($key, $title),
            'agentic_marketing_recommendation' => IntelligenceGraphReference::recommendation($key, $title),
            'recommended_action' => IntelligenceGraphReference::action($key, $title),
            'page_intelligence_report' => IntelligenceGraphReference::report($key, $title),
            'scheduled_briefing' => IntelligenceGraphReference::briefing($key, $title),
            'marketing_observation' => IntelligenceGraphReference::observation($key, $title),
            'campaign' => IntelligenceGraphReference::make('campaign', $key, $title),
            'content' => IntelligenceGraphReference::make('content', $key, $title),
            'performance_snapshot' => IntelligenceGraphReference::make('performance_snapshot', $key, $title),
            'connector_account' => IntelligenceGraphReference::reference('connector_account:'.$key, $title),
            'connector_dataset' => IntelligenceGraphReference::reference('connector_dataset:'.$key, $title),
            default => IntelligenceGraphReference::make((string) $this->resource_type, $key, $title),
        };
    }

    private function edgeType(): IntelligenceGraphEdgeType
    {
        return match ($this->relationship_type) {
            self::RELATION_SUPPORTS => IntelligenceGraphEdgeType::SUPPORTS,
            self::RELATION_DRIVES => IntelligenceGraphEdgeType::DRIVES,
            self::RELATION_EVIDENCES => IntelligenceGraphEdgeType::EVIDENCES,
            self::RELATION_RECOMMENDS => IntelligenceGraphEdgeType::RECOMMENDS,
            self::RELATION_REPORTS => IntelligenceGraphEdgeType::REPORTS,
            self::RELATION_BRIEFS => IntelligenceGraphEdgeType::BRIEFS,
            self::RELATION_MEASURES => IntelligenceGraphEdgeType::MEASURES,
            default => IntelligenceGraphEdgeType::RELATES_TO,
        };
    }

    private function edgeEvidence(): Evidence
    {
        $evidence = data_get($this->metadata_json, 'evidence');

        return is_array($evidence) ? Evidence::fromArray($evidence) : new Evidence();
    }

    private function stageForRelationship(): IntelligenceStage
    {
        return match ($this->relationship_type) {
            self::RELATION_EVIDENCES => IntelligenceStage::RAW_OBSERVATION,
            self::RELATION_MEASURES => IntelligenceStage::SIGNAL,
            self::RELATION_REPORTS => IntelligenceStage::INSIGHT,
            self::RELATION_RECOMMENDS, self::RELATION_BRIEFS => IntelligenceStage::RECOMMENDATION,
            default => IntelligenceStage::ACTION,
        };
    }
}
