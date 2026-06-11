<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticClusterItem;
use Illuminate\Support\Str;

class ProgrammaticBriefBlueprintBuilder
{
    public function __construct(private readonly GrowthAssetTypeResolver $assetTypes) {}

    public function build(ProgrammaticClusterItem $item): ProgrammaticBriefBlueprint
    {
        $item->loadMissing(['cluster.programmaticOpportunity', 'cluster.growthProgram']);
        $cluster = $item->cluster;
        $type = $this->assetTypes->fromClusterItem($item);
        $requirements = $this->assetTypes->enrichmentFor($type);
        $primaryKeyword = $this->primaryKeyword($item);

        return ProgrammaticBriefBlueprint::query()->updateOrCreate(
            ['programmatic_cluster_item_id' => (string) $item->id],
            [
                'workspace_id' => (string) $item->workspace_id,
                'growth_program_id' => $cluster?->growth_program_id,
                'programmatic_cluster_id' => (string) $item->programmatic_cluster_id,
                'growth_asset_type' => $type->value,
                'title' => $item->title,
                'slug' => $item->slug,
                'intent' => $item->intent ?: $requirements['intent'],
                'audience' => $this->audience($item, $type),
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $this->secondaryKeywords($item, $primaryKeyword),
                'outline' => $this->outline($type),
                'required_sections' => $requirements['briefing_requirements'],
                'faq_questions' => $this->faqQuestions($item, $type),
                'schema_recommendations' => $item->recommended_schema_types ?: $requirements['recommended_schema_types'],
                'internal_linking_plan' => $this->internalLinkingPlan($item, $type),
                'cta_recommendation' => $item->recommended_cta ?: $requirements['recommended_cta'],
                'seo_requirements' => $item->seo_requirements ?: $requirements['seo_requirements'],
                'ai_visibility_requirements' => $item->ai_visibility_requirements ?: $requirements['ai_visibility_requirements'],
                'quality_requirements' => $this->qualityRequirements($type),
                'status' => $item->briefBlueprint?->status ?? ProgrammaticBriefBlueprint::STATUS_DRAFT,
                'metadata' => [
                    'source' => 'programmatic_cluster_item',
                    'programmatic_cluster_item_id' => (string) $item->id,
                    'programmatic_cluster_id' => (string) $item->programmatic_cluster_id,
                    'pattern_type' => data_get($item->metadata, 'pattern_type'),
                    'variable_value' => $item->variable_value,
                    'word_count_min' => $item->recommended_word_count_min,
                    'word_count_max' => $item->recommended_word_count_max,
                ],
            ]
        )->refresh();
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function outline(GrowthAssetType $type): array
    {
        $sections = match ($type) {
            GrowthAssetType::INDUSTRY_PAGE => ['H1', 'Industry problem', 'Why this matters', 'Use cases', 'Requirements', 'Solution approach', 'Proof points', 'FAQ', 'CTA'],
            GrowthAssetType::COMPARISON_PAGE => ['H1', 'Short answer', 'Comparison table', 'Key differences', 'Best fit by situation', 'Risks and tradeoffs', 'FAQ', 'CTA'],
            GrowthAssetType::ALTERNATIVE_PAGE => ['H1', 'Why look for an alternative', 'Evaluation criteria', 'Alternative options', 'When to switch', 'FAQ', 'CTA'],
            GrowthAssetType::FAQ_PAGE => ['H1', 'Short intro', 'FAQ list', 'Related topics', 'CTA'],
            GrowthAssetType::AI_ANSWER_PAGE => ['H1', 'Direct answer', 'Context', 'Entity definitions', 'Step by step explanation', 'Related questions', 'Source style summary', 'CTA'],
            GrowthAssetType::LANDING_PAGE => ['H1', 'Hero', 'Problem', 'Solution', 'Proof', 'FAQ', 'CTA'],
            GrowthAssetType::LOCATION_PAGE => ['H1', 'Local context', 'Problem', 'Solution fit', 'Availability', 'Proof', 'FAQ', 'CTA'],
            GrowthAssetType::INTEGRATION_PAGE => ['H1', 'Integration value', 'Setup overview', 'Use cases', 'Requirements', 'FAQ', 'CTA'],
            GrowthAssetType::FEATURE_PAGE => ['H1', 'Feature summary', 'Workflow fit', 'Benefits', 'Proof', 'FAQ', 'CTA'],
            GrowthAssetType::PILLAR_PAGE => ['H1', 'Topic overview', 'Core concepts', 'Subtopic map', 'Internal links', 'FAQ', 'CTA'],
            GrowthAssetType::STRUCTURED_ANSWER => ['H1', 'Direct answer', 'Definitions', 'Disambiguation', 'Related questions'],
            GrowthAssetType::SCHEMA_MARKUP => ['Entity scope', 'Required properties', 'Relationships', 'Validation notes'],
            default => ['H1', 'Intro', 'Body', 'Proof', 'FAQ', 'CTA'],
        };

        return collect($sections)
            ->map(fn (string $section, int $index): array => [
                'heading' => $section,
                'level' => $section === 'H1' ? 'h1' : 'h2',
                'purpose' => $this->sectionPurpose($section, $index),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function secondaryKeywords(ProgrammaticClusterItem $item, string $primaryKeyword): array
    {
        return collect([
            $item->variable_value,
            data_get($item->metadata, 'variable_axis'),
            data_get($item->metadata, 'pattern_type'),
            Str::headline((string) data_get($item->metadata, 'pattern_type')),
        ])
            ->map(fn ($value): string => trim(Str::lower((string) $value)))
            ->filter(fn (string $value): bool => $value !== '' && $value !== Str::lower($primaryKeyword))
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function faqQuestions(ProgrammaticClusterItem $item, GrowthAssetType $type): array
    {
        $topic = $item->title;

        return match ($type) {
            GrowthAssetType::COMPARISON_PAGE => [
                "Wat is het verschil tussen {$topic}?",
                "Wanneer kies je voor {$item->variable_value}?",
                "Welke criteria zijn belangrijk bij deze vergelijking?",
            ],
            GrowthAssetType::ALTERNATIVE_PAGE => [
                "Waarom zoeken teams een alternatief voor {$item->variable_value}?",
                "Wanneer is overstappen verstandig?",
                "Welke evaluatiecriteria zijn belangrijk?",
            ],
            GrowthAssetType::AI_ANSWER_PAGE => [
                "Wat is het korte antwoord op {$item->variable_value}?",
                "Welke begrippen moeten duidelijk zijn?",
                "Welke vervolgvraag hoort hierbij?",
            ],
            GrowthAssetType::FAQ_PAGE => [
                "{$item->variable_value}?",
                "Hoe werkt dit in de praktijk?",
                "Wat is de volgende stap?",
            ],
            default => [
                "Wat moet je weten over {$topic}?",
                "Voor wie is dit relevant?",
                "Wat is een logische volgende stap?",
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function internalLinkingPlan(ProgrammaticClusterItem $item, GrowthAssetType $type): array
    {
        return [
            'role' => $type->defaultInternalLinkingRole(),
            'canonical_group_key' => $item->canonical_group_key,
            'link_to_pillar' => in_array($type, [GrowthAssetType::SUPPORTING_PAGE, GrowthAssetType::FAQ_PAGE, GrowthAssetType::AI_ANSWER_PAGE], true),
            'link_from_pillar' => in_array($type, [GrowthAssetType::PILLAR_PAGE, GrowthAssetType::INDUSTRY_PAGE, GrowthAssetType::COMPARISON_PAGE, GrowthAssetType::ALTERNATIVE_PAGE], true),
            'suggested_anchor' => Str::lower($item->title),
            'related_variable' => $item->variable_value,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function qualityRequirements(GrowthAssetType $type): array
    {
        return [
            'match growth asset type: '.$type->label(),
            'use deterministic outline before drafting',
            'include source-safe claims only',
            'keep CTA aligned with intent',
            'verify schema recommendations before publishing',
        ];
    }

    private function primaryKeyword(ProgrammaticClusterItem $item): string
    {
        return Str::of($item->title)->lower()->squish()->toString();
    }

    private function audience(ProgrammaticClusterItem $item, GrowthAssetType $type): string
    {
        return match ($type) {
            GrowthAssetType::COMPARISON_PAGE, GrowthAssetType::ALTERNATIVE_PAGE => 'Decision makers evaluating content and visibility platforms',
            GrowthAssetType::FAQ_PAGE, GrowthAssetType::AI_ANSWER_PAGE => 'Searchers and AI answer users looking for a direct explanation',
            GrowthAssetType::INDUSTRY_PAGE => 'Teams in the target industry evaluating a solution fit',
            default => 'Teams evaluating programmatic growth opportunities',
        };
    }

    private function sectionPurpose(string $section, int $index): string
    {
        if ($section === 'H1') {
            return 'Frame the asset around the primary keyword.';
        }

        return $index < 3
            ? 'Answer the core intent early.'
            : 'Support evaluation, trust, and next-step clarity.';
    }
}
