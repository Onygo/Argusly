<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProgrammaticBriefConverter
{
    public function convertBlueprint(ProgrammaticBriefBlueprint $blueprint): Brief
    {
        $blueprint->loadMissing(['workspace', 'growthProgram', 'cluster', 'item']);

        if ($blueprint->status === ProgrammaticBriefBlueprint::STATUS_CONVERTED) {
            return $this->existingBriefFor($blueprint) ?? $this->createBrief($blueprint);
        }

        if ($blueprint->status !== ProgrammaticBriefBlueprint::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved programmatic brief blueprints can be converted.');
        }

        return $this->existingBriefFor($blueprint) ?? $this->createBrief($blueprint);
    }

    public function convertApprovedBlueprintsForCluster(ProgrammaticCluster $cluster): int
    {
        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->whereIn('status', [ProgrammaticBriefBlueprint::STATUS_APPROVED, ProgrammaticBriefBlueprint::STATUS_CONVERTED])
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use (&$count): void {
                $this->convertBlueprint($blueprint);
                $count++;
            });

        return $count;
    }

    public function convertApprovedBlueprintsForProgram(GrowthProgram $program): int
    {
        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('growth_program_id', $program->id)
            ->whereIn('status', [ProgrammaticBriefBlueprint::STATUS_APPROVED, ProgrammaticBriefBlueprint::STATUS_CONVERTED])
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use (&$count): void {
                $this->convertBlueprint($blueprint);
                $count++;
            });

        return $count;
    }

    public function existingBriefFor(ProgrammaticBriefBlueprint $blueprint): ?Brief
    {
        return Brief::query()
            ->where('client_refs->programmatic_brief_blueprint_id', (string) $blueprint->id)
            ->first();
    }

    private function createBrief(ProgrammaticBriefBlueprint $blueprint): Brief
    {
        $site = $this->siteForBlueprint($blueprint);
        $assetType = $blueprint->growth_asset_type instanceof GrowthAssetType
            ? $blueprint->growth_asset_type
            : GrowthAssetType::tryFrom((string) $blueprint->growth_asset_type);
        $metadata = (array) $blueprint->metadata;

        $brief = Brief::query()->create([
            'client_site_id' => (string) $site->id,
            'status' => 'draft',
            'source' => 'programmatic_brief_blueprint',
            'title' => $blueprint->title,
            'language' => 'nl',
            'content_type' => $this->contentTypeFor($assetType),
            'output_type' => $this->outputTypeFor($assetType),
            'intent' => $blueprint->intent,
            'primary_keyword' => $blueprint->primary_keyword,
            'secondary_keywords' => $blueprint->secondary_keywords ?? [],
            'audience' => $blueprint->audience,
            'target_audience' => $blueprint->audience,
            'search_intent' => $blueprint->intent,
            'funnel_stage' => $this->funnelStageFor($blueprint->intent),
            'unique_angle' => $this->uniqueAngle($blueprint),
            'key_points' => $this->keyPoints($blueprint),
            'call_to_action' => $blueprint->cta_recommendation,
            'desired_length_min' => data_get($metadata, 'word_count_min'),
            'desired_length_max' => data_get($metadata, 'word_count_max'),
            'notes' => $this->notes($blueprint),
            'progress' => 0,
            'client_refs' => [
                'client_type' => 'programmatic_growth',
                'source_type' => 'programmatic_brief_blueprint',
                'programmatic_brief_blueprint_id' => (string) $blueprint->id,
                'programmatic_cluster_id' => (string) $blueprint->programmatic_cluster_id,
                'programmatic_cluster_item_id' => (string) $blueprint->programmatic_cluster_item_id,
                'growth_program_id' => $blueprint->growth_program_id ? (string) $blueprint->growth_program_id : null,
                'growth_asset_type' => $assetType?->value ?? (string) $blueprint->growth_asset_type,
                'slug' => $blueprint->slug,
                'outline' => $blueprint->outline ?? [],
                'required_sections' => $blueprint->required_sections ?? [],
                'faq_questions' => $blueprint->faq_questions ?? [],
                'schema_recommendations' => $blueprint->schema_recommendations ?? [],
                'internal_linking_plan' => $blueprint->internal_linking_plan ?? [],
                'seo_requirements' => $blueprint->seo_requirements ?? [],
                'ai_visibility_requirements' => $blueprint->ai_visibility_requirements ?? [],
                'quality_requirements' => $blueprint->quality_requirements ?? [],
            ],
            'wp_site_id' => (string) $site->id,
        ]);

        $blueprint->forceFill(['status' => ProgrammaticBriefBlueprint::STATUS_CONVERTED])->save();

        return $brief->refresh();
    }

    private function siteForBlueprint(ProgrammaticBriefBlueprint $blueprint): ClientSite
    {
        return ClientSite::query()
            ->where('workspace_id', $blueprint->workspace_id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first()
            ?? ClientSite::query()
                ->where('workspace_id', $blueprint->workspace_id)
                ->orderBy('created_at')
                ->firstOrFail();
    }

    private function contentTypeFor(?GrowthAssetType $type): string
    {
        return match ($type) {
            GrowthAssetType::LANDING_PAGE,
            GrowthAssetType::INDUSTRY_PAGE,
            GrowthAssetType::LOCATION_PAGE,
            GrowthAssetType::COMPARISON_PAGE,
            GrowthAssetType::ALTERNATIVE_PAGE,
            GrowthAssetType::FAQ_PAGE,
            GrowthAssetType::AI_ANSWER_PAGE,
            GrowthAssetType::PILLAR_PAGE,
            GrowthAssetType::INTEGRATION_PAGE,
            GrowthAssetType::FEATURE_PAGE => 'landing_page',
            default => 'article',
        };
    }

    private function outputTypeFor(?GrowthAssetType $type): string
    {
        return $this->contentTypeFor($type);
    }

    private function funnelStageFor(?string $intent): ?string
    {
        return match ($intent) {
            'commercial_investigation', 'solution_evaluation' => 'consideration',
            'informational', 'educational', 'authority_building' => 'awareness',
            default => null,
        };
    }

    /**
     * @return array<int,string>
     */
    private function keyPoints(ProgrammaticBriefBlueprint $blueprint): array
    {
        return collect($blueprint->outline ?? [])
            ->pluck('heading')
            ->merge($blueprint->required_sections ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function uniqueAngle(ProgrammaticBriefBlueprint $blueprint): string
    {
        $type = $blueprint->growth_asset_type instanceof GrowthAssetType
            ? $blueprint->growth_asset_type->label()
            : Str::headline((string) $blueprint->growth_asset_type);

        return trim($type.' blueprint for '.$blueprint->primary_keyword);
    }

    private function notes(ProgrammaticBriefBlueprint $blueprint): string
    {
        return collect([
            'Programmatic brief converted from approved blueprint.',
            'Required sections: '.collect($blueprint->required_sections ?? [])->join(', '),
            'FAQ questions: '.collect($blueprint->faq_questions ?? [])->join(' | '),
            'Schema recommendations: '.collect($blueprint->schema_recommendations ?? [])->join(', '),
            'AI visibility requirements: '.collect($blueprint->ai_visibility_requirements ?? [])->join(', '),
        ])->filter(fn (string $line): bool => trim($line) !== '')->join("\n");
    }
}
