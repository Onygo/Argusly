<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use Illuminate\Support\Str;

class ContentOpportunityBriefPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        ContentOpportunity $opportunity,
        ClientSite $site,
        string $mode,
        ?int $createdByUserId = null,
        ?Opportunity $canonical = null,
        bool $includeCanonicalRefs = false,
    ): array {
        $clientRefs = [
            'client_type' => 'content_opportunity',
            'site_url' => (string) ($site->site_url ?? ''),
            'content_opportunity' => $this->opportunityReference($opportunity),
            'source_briefing' => [
                'chain_proposal' => $mode === 'chained' ? $this->chainProposal($opportunity) : null,
                'generated_at' => now()->toIso8601String(),
            ],
        ];

        if ($includeCanonicalRefs) {
            $clientRefs['content_opportunity_id'] = (string) $opportunity->id;
            $clientRefs['canonical_opportunity_id'] = $canonical?->id ? (string) $canonical->id : null;
            $clientRefs['mode'] = $mode;
            $clientRefs['source_signature'] = $this->sourceSignature($opportunity, $canonical, $site, $mode);
            $clientRefs['canonical_brief_writer'] = [
                'phase' => '2J',
                'status_policy' => 'no_status_changes_by_default',
                'source_model' => ContentOpportunity::class,
                'canonical_model' => Opportunity::class,
            ];
        }

        return [
            'client_site_id' => (string) $site->id,
            'created_by_user_id' => $createdByUserId,
            'status' => 'draft',
            'source' => 'content_opportunity',
            'title' => $canonical?->title ?: $opportunity->title,
            'language' => (string) data_get($opportunity->localization_recommendation, 'priority_locales.0', 'en'),
            'content_type' => $this->briefContentType($opportunity),
            'output_type' => 'article',
            'primary_keyword' => $this->primaryKeyword($opportunity, $canonical),
            'secondary_keywords' => $this->secondaryKeywords($opportunity),
            'audience' => $opportunity->target_audience,
            'target_audience' => $opportunity->target_audience,
            'funnel_stage' => $opportunity->funnel_stage,
            'search_intent' => $opportunity->primary_search_intent,
            'unique_angle' => $opportunity->angle,
            'key_points' => $this->keyPoints($opportunity),
            'call_to_action' => $opportunity->suggested_cta,
            'desired_length_min' => $mode === 'chained' ? 900 : 1000,
            'desired_length_max' => $mode === 'chained' ? 1300 : 1500,
            'notes' => $this->briefNotes($opportunity, $includeCanonicalRefs),
            'progress' => 0,
            'client_refs' => $clientRefs,
            'wp_site_id' => (string) $site->id,
        ];
    }

    public function sourceSignature(
        ContentOpportunity $opportunity,
        ?Opportunity $canonical,
        ClientSite $site,
        string $mode,
    ): string {
        return hash('sha256', implode('|', [
            'canonical_content_opportunity_brief',
            (string) $site->id,
            (string) $mode,
            (string) $opportunity->id,
            (string) ($canonical?->id ?? ''),
        ]));
    }

    public function briefContentType(ContentOpportunity $opportunity): string
    {
        return match ((string) $opportunity->type) {
            'faq_opportunity', 'answer_block_opportunity' => 'other',
            default => 'blog',
        };
    }

    public function primaryKeyword(ContentOpportunity $opportunity, ?Opportunity $canonical = null): ?string
    {
        return trim((string) ($canonical?->topic ?? ''))
            ?: trim((string) data_get($opportunity->normalized_payload, 'candidate.topic'))
            ?: trim((string) ($canonical?->title ?? ''))
            ?: trim((string) $opportunity->title)
            ?: null;
    }

    /**
     * @return array<int, string>
     */
    public function secondaryKeywords(ContentOpportunity $opportunity): array
    {
        return collect((array) $opportunity->related_entities)
            ->merge(collect((array) $opportunity->recommended_internal_links)->pluck('anchor_text'))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function keyPoints(ContentOpportunity $opportunity): array
    {
        return collect([
            $opportunity->reasoning,
            $opportunity->why_this_matters,
            $opportunity->why_now,
            $opportunity->competitor_pressure,
            $opportunity->ai_visibility_opportunity,
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    public function briefNotes(ContentOpportunity $opportunity, bool $includeCanonicalContext = false): string
    {
        $links = collect((array) $opportunity->recommended_internal_links)
            ->map(fn (mixed $link): string => is_array($link) ? trim((string) ($link['title'] ?? $link['url'] ?? $link['anchor_text'] ?? '')) : trim((string) $link))
            ->filter()
            ->take(6)
            ->map(fn (string $value): string => '- '.$value)
            ->implode(PHP_EOL);

        return trim(implode(PHP_EOL.PHP_EOL, array_filter([
            'Generated from Content Opportunity Engine.',
            $includeCanonicalContext ? 'Future handoff source: canonical MOS Opportunity.' : null,
            'Opportunity type: '.str_replace('_', ' ', (string) $opportunity->type),
            'Expected impact: '.(string) $opportunity->expected_impact,
            'Suggested schema: '.(string) $opportunity->suggested_schema,
            $links !== '' ? 'Recommended internal link context:'.PHP_EOL.$links : null,
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    public function opportunityReference(ContentOpportunity $opportunity): array
    {
        return [
            'id' => (string) $opportunity->id,
            'type' => (string) $opportunity->type,
            'priority_score' => (float) $opportunity->priority_score,
            'expected_impact' => (string) $opportunity->expected_impact,
            'source_signals' => $opportunity->source_signals,
            'query_intent' => $opportunity->query_intent_payload,
            'recommended_internal_links' => $opportunity->recommended_internal_links,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function chainProposal(ContentOpportunity $opportunity): array
    {
        $topic = $this->primaryKeyword($opportunity) ?: $opportunity->title;
        $entities = collect((array) $opportunity->related_entities)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->take(4)
            ->values();

        $fallbacks = collect([
            'What is '.$topic.'?',
            'How to implement '.$topic,
            $topic.' examples and use cases',
            $topic.' comparison and alternatives',
        ]);

        return [
            'pillar_topic' => Str::headline((string) $topic),
            'strategy' => 'Create a pillar article plus supporting chained articles based on the opportunity findings.',
            'supporting_subtopics' => $entities
                ->map(fn (string $entity): array => ['title' => Str::headline($entity), 'reason' => 'Related entity from the opportunity signals.'])
                ->merge($fallbacks->map(fn (string $title): array => ['title' => Str::headline($title), 'reason' => 'Derived from the opportunity topic.']))
                ->unique('title')
                ->take(6)
                ->values()
                ->all(),
        ];
    }
}
