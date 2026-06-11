<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Enums\ProgrammaticPatternType;
use App\Models\ProgrammaticClusterItem;

class GrowthAssetTypeResolver
{
    public function fromPattern(ProgrammaticPatternType $pattern): GrowthAssetType
    {
        return match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE => GrowthAssetType::INDUSTRY_PAGE,
            ProgrammaticPatternType::LOCATION_PAGE => GrowthAssetType::LOCATION_PAGE,
            ProgrammaticPatternType::COMPARISON_PAGE => GrowthAssetType::COMPARISON_PAGE,
            ProgrammaticPatternType::ALTERNATIVE_PAGE => GrowthAssetType::ALTERNATIVE_PAGE,
            ProgrammaticPatternType::USE_CASE_PAGE => GrowthAssetType::LANDING_PAGE,
            ProgrammaticPatternType::FAQ_LIBRARY => GrowthAssetType::FAQ_PAGE,
            ProgrammaticPatternType::AI_ANSWER_LIBRARY => GrowthAssetType::AI_ANSWER_PAGE,
            ProgrammaticPatternType::FEATURE_PAGE => GrowthAssetType::FEATURE_PAGE,
            ProgrammaticPatternType::INTEGRATION_PAGE => GrowthAssetType::INTEGRATION_PAGE,
        };
    }

    public function fromClusterItem(ProgrammaticClusterItem $item): GrowthAssetType
    {
        if ($item->growth_asset_type instanceof GrowthAssetType) {
            return $item->growth_asset_type;
        }

        if ($item->growth_asset_type && $type = GrowthAssetType::tryFrom((string) $item->growth_asset_type)) {
            return $type;
        }

        $pattern = ProgrammaticPatternType::tryFrom((string) data_get($item->metadata, 'pattern_type'));

        return $pattern ? $this->fromPattern($pattern) : GrowthAssetType::SUPPORTING_PAGE;
    }

    /**
     * @return array<string,mixed>
     */
    public function enrichmentFor(ProgrammaticPatternType|GrowthAssetType $source): array
    {
        $type = $source instanceof ProgrammaticPatternType ? $this->fromPattern($source) : $source;

        return [
            'growth_asset_type' => $type->value,
            'intent' => $type->defaultIntent(),
            'recommended_word_count_min' => $this->wordCountRange($type)[0],
            'recommended_word_count_max' => $this->wordCountRange($type)[1],
            'recommended_schema_types' => $this->schemaRequirements($type),
            'recommended_cta' => $type->defaultCtaStyle(),
            'internal_linking_role' => $type->defaultInternalLinkingRole(),
            'briefing_requirements' => $this->briefingRequirements($type),
            'seo_requirements' => $this->seoRequirements($type),
            'ai_visibility_requirements' => $this->aiVisibilityRequirements($type),
        ];
    }

    /**
     * @return array<int,int>
     */
    public function wordCountRange(GrowthAssetType $type): array
    {
        return match ($type) {
            GrowthAssetType::PILLAR_PAGE => [2200, 4200],
            GrowthAssetType::COMPARISON_PAGE, GrowthAssetType::ALTERNATIVE_PAGE, GrowthAssetType::INDUSTRY_PAGE => [1200, 2200],
            GrowthAssetType::LANDING_PAGE, GrowthAssetType::LOCATION_PAGE, GrowthAssetType::INTEGRATION_PAGE, GrowthAssetType::FEATURE_PAGE => [900, 1600],
            GrowthAssetType::FAQ_PAGE, GrowthAssetType::AI_ANSWER_PAGE => [600, 1200],
            GrowthAssetType::STRUCTURED_ANSWER => [250, 600],
            GrowthAssetType::SCHEMA_MARKUP => [0, 0],
            default => [800, 1400],
        };
    }

    /**
     * @return array<int,string>
     */
    public function briefingRequirements(GrowthAssetType $type): array
    {
        return match ($type) {
            GrowthAssetType::LANDING_PAGE => ['hero', 'problem', 'solution', 'proof', 'CTA', 'FAQ'],
            GrowthAssetType::COMPARISON_PAGE => ['intro', 'comparison table', 'criteria', 'strengths', 'weaknesses', 'best fit', 'CTA'],
            GrowthAssetType::ALTERNATIVE_PAGE => ['why switch', 'alternative options', 'use case fit', 'decision criteria', 'CTA'],
            GrowthAssetType::AI_ANSWER_PAGE => ['direct answer', 'short summary', 'entity definitions', 'source style explanation', 'FAQ'],
            GrowthAssetType::INDUSTRY_PAGE => ['industry problem', 'use cases', 'requirements', 'solution mapping', 'proof', 'CTA'],
            GrowthAssetType::LOCATION_PAGE => ['local context', 'service fit', 'proof', 'availability', 'CTA'],
            GrowthAssetType::FAQ_PAGE => ['question', 'direct answer', 'expanded answer', 'related questions'],
            GrowthAssetType::INTEGRATION_PAGE => ['integration value', 'setup overview', 'use cases', 'requirements', 'CTA'],
            GrowthAssetType::FEATURE_PAGE => ['feature summary', 'benefits', 'workflow fit', 'proof', 'CTA'],
            GrowthAssetType::PILLAR_PAGE => ['topic overview', 'subtopics', 'internal links', 'proof', 'CTA'],
            GrowthAssetType::STRUCTURED_ANSWER => ['direct answer', 'entity definitions', 'disambiguation'],
            GrowthAssetType::SCHEMA_MARKUP => ['entities', 'page type', 'relationships', 'required properties'],
            default => ['intro', 'body', 'proof', 'CTA'],
        };
    }

    /**
     * @return array<int,string>
     */
    public function seoRequirements(GrowthAssetType $type): array
    {
        return ['search intent', 'primary keyword', 'metadata', 'H1', 'internal links', 'canonical URL'];
    }

    /**
     * @return array<int,string>
     */
    public function aiVisibilityRequirements(GrowthAssetType $type): array
    {
        return match ($type) {
            GrowthAssetType::AI_ANSWER_PAGE, GrowthAssetType::FAQ_PAGE, GrowthAssetType::STRUCTURED_ANSWER => ['direct answer first', 'entity-rich wording', 'question variants', 'FAQ structure'],
            GrowthAssetType::COMPARISON_PAGE, GrowthAssetType::ALTERNATIVE_PAGE => ['clear criteria', 'balanced comparison', 'best-fit summary', 'answerable headings'],
            default => ['concise summary', 'entity definitions', 'answerable headings'],
        };
    }

    /**
     * @return array<int,string>
     */
    public function schemaRequirements(GrowthAssetType $type): array
    {
        return $type->defaultSchemaRecommendation();
    }
}
