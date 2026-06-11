<?php

namespace App\Services\Growth;

use App\Enums\ProgrammaticPatternType;
use App\Models\Content;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticClusterItem;
use App\Models\ProgrammaticOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProgrammaticClusterBuilder
{
    public function __construct(
        private readonly ProgrammaticVariableLibrary $variables,
        private readonly GrowthAssetTypeResolver $assetTypes,
    ) {}

    public function build(ProgrammaticOpportunity $opportunity): ProgrammaticCluster
    {
        $pattern = $opportunity->pattern_type instanceof ProgrammaticPatternType
            ? $opportunity->pattern_type
            : ProgrammaticPatternType::tryFrom((string) $opportunity->pattern_type);

        if (! $pattern) {
            throw new \InvalidArgumentException('Unsupported programmatic pattern type.');
        }

        return DB::transaction(function () use ($opportunity, $pattern): ProgrammaticCluster {
            $variables = $this->variables($opportunity, $pattern);
            $cluster = ProgrammaticCluster::query()->updateOrCreate(
                ['programmatic_opportunity_id' => (string) $opportunity->id],
                [
                    'organization_id' => $opportunity->organization_id,
                    'workspace_id' => (string) $opportunity->workspace_id,
                    'growth_program_id' => $opportunity->growth_program_id,
                    'name' => $pattern->label().': '.$opportunity->base_topic,
                    'description' => $pattern->description(),
                    'pattern_type' => $pattern->value,
                    'base_topic' => $opportunity->base_topic,
                    'variable_axis' => $opportunity->variable_axis,
                    'status' => ProgrammaticCluster::STATUS_PREVIEW,
                    'estimated_assets_count' => count($variables),
                    'estimated_reach' => $this->aggregateReach($opportunity, count($variables)),
                    'estimated_ai_visibility' => $opportunity->ai_visibility_score,
                    'estimated_business_impact' => $opportunity->business_value_score,
                    'confidence_score' => $opportunity->confidence_score,
                    'metadata' => [
                        'source_programmatic_opportunity_id' => (string) $opportunity->id,
                        'variable_source' => 'code_library_and_detected_examples',
                    ],
                ]
            );

            foreach ($variables as $variable) {
                $title = $this->title($pattern, $opportunity->base_topic, $variable);
                $slug = Str::slug($title);
                $assetType = $this->assetTypes->fromPattern($pattern);
                $requirements = $this->assetTypes->enrichmentFor($assetType);

                ProgrammaticClusterItem::query()->updateOrCreate(
                    [
                        'programmatic_cluster_id' => (string) $cluster->id,
                        'variable_value' => $variable,
                        'asset_type' => $assetType->value,
                    ],
                    [
                        'workspace_id' => (string) $opportunity->workspace_id,
                        'title' => $title,
                        'slug' => $slug,
                        'growth_asset_type' => $requirements['growth_asset_type'],
                        'intent' => $requirements['intent'],
                        'priority_score' => $this->clamp((float) ($opportunity->scale_score ?? 50)),
                        'seo_score' => $this->clamp((float) ($opportunity->seo_opportunity_score ?? $opportunity->scale_score ?? 45)),
                        'ai_visibility_score' => $this->clamp((float) ($opportunity->ai_visibility_score ?? 35)),
                        'business_value_score' => $this->clamp((float) ($opportunity->business_value_score ?? 45)),
                        'recommended_word_count_min' => $requirements['recommended_word_count_min'],
                        'recommended_word_count_max' => $requirements['recommended_word_count_max'],
                        'recommended_schema_types' => $requirements['recommended_schema_types'],
                        'recommended_cta' => $requirements['recommended_cta'],
                        'internal_linking_role' => $requirements['internal_linking_role'],
                        'briefing_requirements' => $requirements['briefing_requirements'],
                        'ai_visibility_requirements' => $requirements['ai_visibility_requirements'],
                        'seo_requirements' => $requirements['seo_requirements'],
                        'duplicate_risk_score' => $this->duplicateRisk((string) $opportunity->workspace_id, $title, $slug),
                        'canonical_group_key' => $this->canonicalGroupKey($pattern, $opportunity->base_topic, $variable),
                        'status' => ProgrammaticClusterItem::STATUS_PREVIEW,
                        'metadata' => [
                            'pattern_type' => $pattern->value,
                            'variable_axis' => $opportunity->variable_axis,
                        ],
                    ]
                );
            }

            return $cluster->refresh()->load('items');
        });
    }

    /**
     * @return array<int,string>
     */
    private function variables(ProgrammaticOpportunity $opportunity, ProgrammaticPatternType $pattern): array
    {
        return collect((array) $opportunity->example_variables)
            ->merge($this->variables->variablesFor($pattern))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->take(10)
            ->values()
            ->all();
    }

    private function title(ProgrammaticPatternType $pattern, string $baseTopic, string $variable): string
    {
        return match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE => "{$baseTopic} voor {$variable}",
            ProgrammaticPatternType::LOCATION_PAGE => "{$baseTopic} in {$variable}",
            ProgrammaticPatternType::ALTERNATIVE_PAGE => "Alternatief voor {$variable}",
            ProgrammaticPatternType::COMPARISON_PAGE => "{$baseTopic} vs {$variable}",
            ProgrammaticPatternType::FAQ_LIBRARY => "Veelgestelde vragen over {$baseTopic}: {$variable}",
            ProgrammaticPatternType::AI_ANSWER_LIBRARY => "Antwoord op: {$variable} voor {$baseTopic}",
            ProgrammaticPatternType::USE_CASE_PAGE => "{$baseTopic} voor {$variable}",
            ProgrammaticPatternType::INTEGRATION_PAGE => "{$baseTopic} integratie met {$variable}",
            ProgrammaticPatternType::FEATURE_PAGE => "{$variable} voor {$baseTopic}",
        };
    }

    private function canonicalGroupKey(ProgrammaticPatternType $pattern, string $baseTopic, string $variable): ?string
    {
        return in_array($pattern, [ProgrammaticPatternType::COMPARISON_PAGE, ProgrammaticPatternType::ALTERNATIVE_PAGE], true)
            ? Str::slug($baseTopic.'-'.$variable)
            : Str::slug($baseTopic.'-'.$pattern->value);
    }

    private function duplicateRisk(string $workspaceId, string $title, string $slug): float
    {
        $normalizedTitle = Str::lower($title);
        $exists = Content::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($title, $normalizedTitle, $slug): void {
                $query->where('title', $title)
                    ->orWhereRaw('LOWER(title) = ?', [$normalizedTitle])
                    ->orWhere('external_key', $slug);
            })
            ->exists();

        return $exists ? 92.0 : 12.0;
    }

    private function aggregateReach(ProgrammaticOpportunity $opportunity, int $count): ?float
    {
        if ($opportunity->scale_score === null) {
            return null;
        }

        return round((float) $opportunity->scale_score * max(1, $count) * 25, 2);
    }

    private function clamp(float $score): float
    {
        return round(max(0, min(100, $score)), 2);
    }
}
