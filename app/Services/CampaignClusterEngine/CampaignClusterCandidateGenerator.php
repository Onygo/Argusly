<?php

namespace App\Services\CampaignClusterEngine;

use App\Models\CompanyIntelligenceProfile;
use App\Models\CompetitorContentOpportunity;
use App\Models\Content;
use App\Models\ContentOpportunity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CampaignClusterCandidateGenerator
{
    /**
     * @return array<int,CampaignClusterPlan>
     */
    public function generate(array $input): array
    {
        /** @var CompanyIntelligenceProfile|null $company */
        $company = $input['company'] ?? null;
        /** @var Collection<int,ContentOpportunity> $opportunities */
        $opportunities = $input['opportunities'];
        /** @var Collection<int,CompetitorContentOpportunity> $competitorGaps */
        $competitorGaps = $input['competitor_gaps'];
        /** @var Collection<int,Content> $existingContent */
        $existingContent = $input['existing_content'] ?? collect();

        $topics = $this->priorityTopics($company, $opportunities, $competitorGaps, $existingContent, (array) ($input['fallback_topics'] ?? []));

        return collect($topics)
            ->take(6)
            ->map(fn (string $topic): CampaignClusterPlan => $this->planForTopic($topic, $opportunities, $competitorGaps, $company))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function priorityTopics(?CompanyIntelligenceProfile $company, Collection $opportunities, Collection $competitorGaps, Collection $existingContent, array $fallbackTopics): array
    {
        $topics = collect();

        $topics = $topics->merge((array) ($company?->primary_topics ?? []))
            ->merge((array) ($company?->authority_areas ?? []))
            ->merge($opportunities->pluck('normalized_payload.candidate.topic'))
            ->merge($opportunities->pluck('title'))
            ->merge($competitorGaps->pluck('topic'))
            ->merge($existingContent->pluck('primary_keyword'))
            ->merge($existingContent->pluck('seo_h1'))
            ->merge($existingContent->pluck('title'))
            ->merge($fallbackTopics);

        return $topics
            ->filter(fn ($topic): bool => is_scalar($topic) && trim((string) $topic) !== '')
            ->map(fn ($topic): string => $this->normalizeTopic((string) $topic))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTopic(string $topic): string
    {
        $normalized = Str::of($topic)
            ->replaceMatches('/[|:;]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->value();

        if ($normalized === '') {
            return '';
        }

        return Str::words($normalized, 8, '');
    }

    private function planForTopic(string $topic, Collection $opportunities, Collection $competitorGaps, ?CompanyIntelligenceProfile $company): CampaignClusterPlan
    {
        $related = $this->relatedOpportunities($topic, $opportunities);
        $gaps = $this->relatedCompetitorGaps($topic, $competitorGaps);
        $entity = Str::headline($topic);
        $items = $this->items($topic, $entity, $related, $gaps);

        return new CampaignClusterPlan(
            primaryEntity: $entity,
            primaryTopic: $topic,
            name: $entity.' Authority Cluster',
            authorityStrategy: 'Build a hub-and-spoke content ecosystem that explains, compares, implements, and answers high-intent questions around '.$topic.'.',
            ctaStrategy: $this->ctaStrategy($company, $topic),
            refreshCadence: $this->refreshCadence($related, $gaps),
            items: $items,
            dependencies: $this->dependencies($items, $topic),
            sourceSignals: [
                'company_topics' => (array) ($company?->primary_topics ?? []),
                'related_opportunity_ids' => $related->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                'competitor_gap_ids' => $gaps->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                'agent_ready' => true,
            ],
        );
    }

    private function relatedOpportunities(string $topic, Collection $opportunities): Collection
    {
        $needle = Str::lower($topic);

        return $opportunities
            ->filter(function (ContentOpportunity $opportunity) use ($needle): bool {
                $haystack = Str::lower($opportunity->title.' '.$opportunity->reasoning.' '.$opportunity->angle.' '.implode(' ', (array) $opportunity->related_entities));

                return str_contains($haystack, $needle) || str_contains($needle, Str::lower((string) data_get($opportunity->normalized_payload, 'candidate.topic')));
            })
            ->take(12)
            ->values();
    }

    private function relatedCompetitorGaps(string $topic, Collection $gaps): Collection
    {
        $needle = Str::lower($topic);

        return $gaps
            ->filter(fn (CompetitorContentOpportunity $gap): bool => str_contains(Str::lower($gap->topic.' '.$gap->title.' '.$gap->attackable_angle), $needle))
            ->take(8)
            ->values();
    }

    private function items(string $topic, string $entity, Collection $related, Collection $gaps): array
    {
        $items = [
            $this->item('pillar_page', $entity.' Guide', $entity, 'awareness', 'informational', 1, null, [
                'role' => 'authority_hub',
                'brief' => 'Create the primary entity hub and define the market vocabulary.',
            ]),
            $this->item('comparison_page', $entity.' Alternatives and Comparisons', $entity, 'decision', 'comparison', 2, $related->firstWhere('type', 'comparison_page'), [
                'role' => 'bofu_capture',
                'competitor_pressure' => $gaps->where('type', 'comparison_page')->count(),
            ]),
            $this->item('implementation_guide', 'How to Implement '.$entity, $entity, 'consideration', 'implementation', 3, $related->firstWhere('type', 'implementation_guide'), [
                'role' => 'practical_enablement',
            ]),
            $this->item('faq_cluster', $entity.' Questions and Answers', $entity, 'awareness', 'informational', 4, $related->firstWhere('type', 'faq_opportunity'), [
                'role' => 'answer_extraction',
            ]),
            $this->item('answer_blocks', 'Answer blocks for '.$entity.' cluster pages', $entity, 'consideration', 'implementation', 5, $related->firstWhere('type', 'answer_block_opportunity'), [
                'role' => 'ai_visibility',
                'asset_kind' => 'content_enhancement',
                'materialized_action_type' => 'add_answer_block',
                'suggested_schema' => 'FAQPage',
            ]),
            $this->item('supporting_article', $entity.' Use Cases', $entity, 'consideration', 'commercial', 6, $related->firstWhere('type', 'use_case_page'), [
                'role' => 'use_case_depth',
            ]),
        ];

        foreach ($related->whereNotIn('type', ['comparison_page', 'implementation_guide', 'faq_opportunity', 'answer_block_opportunity', 'use_case_page'])->take(4) as $opportunity) {
            $items[] = $this->item($opportunity->type, $opportunity->title, $entity, (string) $opportunity->funnel_stage, (string) $opportunity->primary_search_intent, count($items) + 1, $opportunity, [
                'role' => 'opportunity_extension',
            ]);
        }

        return $items;
    }

    private function item(string $type, string $title, string $entity, string $stage, string $intent, int $order, ?ContentOpportunity $opportunity, array $payload): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'target_entity' => $entity,
            'funnel_stage' => $stage,
            'search_intent' => $intent,
            'sequence_order' => $order,
            'content_opportunity_id' => $opportunity?->id ? (string) $opportunity->id : null,
            'authority_contribution' => $type === 'pillar_page' ? 30 : 12,
            'coverage_contribution' => in_array($type, ['faq_cluster', 'answer_blocks'], true) ? 18 : 14,
            'payload' => array_merge($payload, [
                'source_opportunity' => $opportunity ? [
                    'id' => (string) $opportunity->id,
                    'priority_score' => $opportunity->priority_score,
                ] : null,
            ]),
        ];
    }

    private function dependencies(array $items, string $topic): array
    {
        $pillar = $items[0] ?? null;
        if (! $pillar) {
            return [];
        }

        return collect($items)
            ->skip(1)
            ->flatMap(fn (array $item): array => [
                [
                    'source_order' => $item['sequence_order'],
                    'target_order' => $pillar['sequence_order'],
                    'type' => 'internal_link',
                    'anchor_text' => $topic,
                    'reason' => 'Spoke content reinforces the pillar page.',
                ],
                [
                    'source_order' => $pillar['sequence_order'],
                    'target_order' => $item['sequence_order'],
                    'type' => 'contextual_link',
                    'anchor_text' => $item['title'],
                    'reason' => 'Pillar page routes readers to deeper execution pages.',
                ],
            ])
            ->values()
            ->all();
    }

    private function ctaStrategy(?CompanyIntelligenceProfile $company, string $topic): string
    {
        $uvp = trim((string) ($company?->uvp ?? ''));

        return $uvp !== ''
            ? 'Use mid-funnel education CTAs early, then transition to demo CTAs anchored in: '.$uvp
            : 'Use education CTAs on awareness assets and demo CTAs on comparison, implementation, and use-case pages.';
    }

    private function refreshCadence(Collection $related, Collection $gaps): string
    {
        return $related->where('freshness_status', 'stale')->isNotEmpty() || $gaps->count() >= 3
            ? 'monthly'
            : 'quarterly';
    }
}
