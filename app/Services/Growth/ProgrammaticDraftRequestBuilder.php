<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Models\Brief;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticDraftRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProgrammaticDraftRequestBuilder
{
    public function buildForBlueprint(ProgrammaticBriefBlueprint $blueprint): ProgrammaticDraftRequest
    {
        $blueprint->loadMissing(['cluster', 'item']);

        if ($blueprint->status !== ProgrammaticBriefBlueprint::STATUS_CONVERTED) {
            throw new InvalidArgumentException('Only converted programmatic brief blueprints can be prepared for draft generation.');
        }

        $brief = $blueprint->linkedBrief();
        if (! $brief) {
            throw new InvalidArgumentException('Converted blueprint has no linked Brief.');
        }

        $type = $blueprint->growth_asset_type instanceof GrowthAssetType
            ? $blueprint->growth_asset_type
            : GrowthAssetType::tryFrom((string) $blueprint->growth_asset_type) ?? GrowthAssetType::SUPPORTING_PAGE;
        $tokens = $this->estimatedTokens($blueprint, $brief);
        $cost = $this->estimatedCost($tokens);
        $status = (bool) config('argusly_programmatic.require_manual_approval', true)
            ? ProgrammaticDraftRequest::STATUS_PENDING
            : ProgrammaticDraftRequest::STATUS_APPROVED;

        return ProgrammaticDraftRequest::query()->updateOrCreate(
            ['brief_id' => (string) $brief->id],
            [
                'workspace_id' => (string) $blueprint->workspace_id,
                'growth_program_id' => $blueprint->growth_program_id,
                'programmatic_brief_blueprint_id' => (string) $blueprint->id,
                'programmatic_cluster_id' => $blueprint->programmatic_cluster_id,
                'programmatic_cluster_item_id' => $blueprint->programmatic_cluster_item_id,
                'growth_asset_type' => $type->value,
                'title' => $brief->title,
                'slug' => (string) data_get($brief->client_refs, 'slug', $blueprint->slug),
                'priority_score' => $this->priorityScore($blueprint, $brief),
                'estimated_cost' => $cost,
                'estimated_tokens' => $tokens,
                'status' => $blueprint->draftRequest?->status ?? $status,
                'generation_mode' => ProgrammaticDraftRequest::MODE_MANUAL,
                'metadata' => [
                    'source' => 'programmatic_brief_blueprint',
                    'requires_manual_approval' => (bool) config('argusly_programmatic.require_manual_approval', true),
                    'allow_batch_generation' => (bool) config('argusly_programmatic.allow_batch_generation', false),
                    'estimated_cost_warning_threshold' => (float) config('argusly_programmatic.estimated_cost_warning_threshold', 25.00),
                    'cost_warning' => $cost >= (float) config('argusly_programmatic.estimated_cost_warning_threshold', 25.00),
                    'programmatic_brief_blueprint_id' => (string) $blueprint->id,
                    'programmatic_cluster_id' => $blueprint->programmatic_cluster_id ? (string) $blueprint->programmatic_cluster_id : null,
                    'programmatic_cluster_item_id' => $blueprint->programmatic_cluster_item_id ? (string) $blueprint->programmatic_cluster_item_id : null,
                    'brief_id' => (string) $brief->id,
                    'outline_sections' => count((array) data_get($brief->client_refs, 'outline', [])),
                ],
            ]
        )->refresh();
    }

    public function buildForCluster(ProgrammaticCluster $cluster): int
    {
        $limit = (int) config('argusly_programmatic.max_requests_per_cluster', 25);

        return $this->convertedBlueprints()
            ->where('programmatic_cluster_id', $cluster->id)
            ->limit($limit)
            ->get()
            ->reduce(function (int $count, ProgrammaticBriefBlueprint $blueprint): int {
                $this->buildForBlueprint($blueprint);

                return $count + 1;
            }, 0);
    }

    public function buildForProgram(GrowthProgram $program): int
    {
        $limit = (int) config('argusly_programmatic.max_requests_per_growth_program', 100);

        return $this->convertedBlueprints()
            ->where('growth_program_id', $program->id)
            ->limit($limit)
            ->get()
            ->reduce(function (int $count, ProgrammaticBriefBlueprint $blueprint): int {
                $this->buildForBlueprint($blueprint);

                return $count + 1;
            }, 0);
    }

    public function syncForProgram(GrowthProgram $program): int
    {
        return $this->buildForProgram($program);
    }

    private function convertedBlueprints()
    {
        return ProgrammaticBriefBlueprint::query()
            ->where('status', ProgrammaticBriefBlueprint::STATUS_CONVERTED)
            ->orderByDesc('updated_at');
    }

    private function estimatedTokens(ProgrammaticBriefBlueprint $blueprint, Brief $brief): int
    {
        $maxWords = (int) ($brief->desired_length_max ?: data_get($blueprint->metadata, 'word_count_max', 1200));
        $outlineCount = count((array) data_get($brief->client_refs, 'outline', []));
        $requirementCount = count((array) data_get($brief->client_refs, 'quality_requirements', []));

        return max(1200, (int) ceil(($maxWords * 1.7) + ($outlineCount * 120) + ($requirementCount * 80)));
    }

    private function estimatedCost(int $tokens): float
    {
        return round($tokens / 1000 * (float) config('argusly_programmatic.estimated_cost_per_1k_tokens', 0.02), 4);
    }

    private function priorityScore(ProgrammaticBriefBlueprint $blueprint, Brief $brief): float
    {
        $itemScore = (float) ($blueprint->item?->priority_score ?? 0);
        $businessValue = (float) ($blueprint->item?->business_value_score ?? 0);
        $readiness = (float) $blueprint->readinessPercentage();

        return round(max($itemScore, $businessValue, $readiness * 0.8), 2);
    }
}
