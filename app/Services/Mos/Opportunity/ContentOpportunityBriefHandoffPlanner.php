<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\RecommendedAction;
use Illuminate\Support\Str;

class ContentOpportunityBriefHandoffPlanner
{
    public const ACTION_TYPE = 'prepare_content_opportunity';

    public function __construct(
        private readonly ContentOpportunityRecommendedActionSignature $signature,
    ) {}

    public function plan(ContentOpportunity $contentOpportunity): ContentOpportunityBriefHandoffPlan
    {
        $contentOpportunity->loadMissing('workspace', 'site');
        $canonical = $this->linkedCanonicalOpportunity($contentOpportunity);
        $site = $contentOpportunity->site;

        $title = $this->string($canonical?->title) ?: $this->string($contentOpportunity->title);
        $primaryKeyword = $this->primaryKeyword($contentOpportunity, $canonical);
        $evidence = $this->sourceEvidence($contentOpportunity, $canonical);
        $actions = $this->recommendedActions($contentOpportunity, $canonical);

        $legacyFields = [
            'client_site_id' => $site?->id ? (string) $site->id : null,
            'status' => 'draft',
            'source' => 'content_opportunity',
            'title' => $title,
            'language' => (string) data_get($contentOpportunity->localization_recommendation, 'priority_locales.0', 'en'),
            'content_type' => $this->briefContentType($contentOpportunity),
            'output_type' => 'article',
            'primary_keyword' => $primaryKeyword,
            'secondary_keywords' => $this->secondaryKeywords($contentOpportunity),
            'audience' => $contentOpportunity->target_audience,
            'target_audience' => $contentOpportunity->target_audience,
            'funnel_stage' => $contentOpportunity->funnel_stage,
            'search_intent' => $contentOpportunity->primary_search_intent,
            'unique_angle' => $contentOpportunity->angle,
            'key_points' => $this->keyPoints($contentOpportunity),
            'call_to_action' => $contentOpportunity->suggested_cta,
            'desired_length_min' => 1000,
            'desired_length_max' => 1500,
            'notes' => $this->briefNotes($contentOpportunity),
            'client_refs' => [
                'client_type' => 'content_opportunity',
                'content_opportunity_id' => (string) $contentOpportunity->id,
                'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            ],
            'wp_site_id' => $site?->id ? (string) $site->id : null,
        ];

        $missing = $this->missingFields($canonical, $site, $legacyFields, $evidence);
        $safe = $missing === [];

        return new ContentOpportunityBriefHandoffPlan(
            canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
            legacyContentOpportunityId: (string) $contentOpportunity->id,
            workspaceId: $contentOpportunity->workspace_id ? (string) $contentOpportunity->workspace_id : null,
            clientSiteId: $site?->id ? (string) $site->id : null,
            proposedActionType: self::ACTION_TYPE,
            proposedSourceSignature: $this->signature->signature(
                $canonical ?? $contentOpportunity,
                $canonical?->workspace ?? $contentOpportunity->workspace,
                $canonical ? RecommendedAction::SOURCE_OPPORTUNITY : RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
                $canonical ? 'review_opportunity' : self::ACTION_TYPE,
            ),
            proposedCtaLabel: 'Review canonical opportunity',
            proposedCtaRoute: $canonical
                ? route('app.opportunities.show', $canonical)
                : route('app.agentic-marketing.content-opportunities.index', array_filter([
                    'workspace_id' => $contentOpportunity->workspace_id,
                    'client_site_id' => $contentOpportunity->client_site_id,
                    'status' => $contentOpportunity->status,
                ])),
            proposedSourceLink: route('app.agentic-marketing.content-opportunities.index', array_filter([
                'workspace_id' => $contentOpportunity->workspace_id,
                'client_site_id' => $contentOpportunity->client_site_id,
                'status' => $contentOpportunity->status,
            ])),
            recommendedBriefTitle: $title,
            primaryKeyword: $primaryKeyword,
            audience: $contentOpportunity->target_audience,
            funnelStage: $contentOpportunity->funnel_stage,
            intent: $contentOpportunity->primary_search_intent,
            sourceEvidence: $evidence,
            recommendedActions: $actions,
            targetContext: [
                'organization_id' => $contentOpportunity->organization_id ? (string) $contentOpportunity->organization_id : null,
                'workspace_id' => $contentOpportunity->workspace_id ? (string) $contentOpportunity->workspace_id : null,
                'client_site_id' => $site?->id ? (string) $site->id : null,
                'site_url' => $site?->site_url,
            ],
            legacyRequiredFields: $legacyFields,
            missingFields: $missing,
            safe: $safe,
            safetyStatus: $safe ? 'safe' : 'blocked',
            reason: $safe
                ? 'Linked canonical Opportunity has the site, title, keyword, and evidence required to plan future brief creation while preserving legacy traceability.'
                : 'Blocked until required fields are present: '.implode(', ', $missing).'.',
        );
    }

    private function linkedCanonicalOpportunity(ContentOpportunity $contentOpportunity): ?Opportunity
    {
        return Opportunity::query()
            ->where('content_opportunity_id', $contentOpportunity->id)
            ->first();
    }

    private function briefContentType(ContentOpportunity $opportunity): string
    {
        return match ((string) $opportunity->type) {
            'faq_opportunity', 'answer_block_opportunity' => 'other',
            default => 'blog',
        };
    }

    private function primaryKeyword(ContentOpportunity $opportunity, ?Opportunity $canonical): ?string
    {
        return $this->string($canonical?->topic)
            ?: $this->string(data_get($opportunity->normalized_payload, 'candidate.topic'))
            ?: $this->string($canonical?->title)
            ?: $this->string($opportunity->title)
            ?: null;
    }

    /**
     * @return array<int, string>
     */
    private function secondaryKeywords(ContentOpportunity $opportunity): array
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
    private function keyPoints(ContentOpportunity $opportunity): array
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

    private function briefNotes(ContentOpportunity $opportunity): string
    {
        $links = collect((array) $opportunity->recommended_internal_links)
            ->map(fn (mixed $link): string => is_array($link) ? trim((string) ($link['title'] ?? $link['url'] ?? $link['anchor_text'] ?? '')) : trim((string) $link))
            ->filter()
            ->take(6)
            ->map(fn (string $value): string => '- '.$value)
            ->implode(PHP_EOL);

        return trim(implode(PHP_EOL.PHP_EOL, array_filter([
            'Generated from Content Opportunity Engine.',
            'Future handoff source: canonical MOS Opportunity.',
            'Opportunity type: '.str_replace('_', ' ', (string) $opportunity->type),
            'Expected impact: '.(string) $opportunity->expected_impact,
            'Suggested schema: '.(string) $opportunity->suggested_schema,
            $links !== '' ? 'Recommended internal link context:'.PHP_EOL.$links : null,
        ])));
    }

    /**
     * @return array<int, mixed>
     */
    private function sourceEvidence(ContentOpportunity $opportunity, ?Opportunity $canonical): array
    {
        $evidence = is_array($canonical?->evidence) ? $canonical->evidence : [];
        $legacyEvidence = [
            'type' => 'legacy_content_opportunity',
            'source_model' => ContentOpportunity::class,
            'source_id' => (string) $opportunity->id,
            'reasoning' => $opportunity->reasoning,
            'why_this_matters' => $opportunity->why_this_matters,
            'why_now' => $opportunity->why_now,
            'source_signals' => $opportunity->source_signals,
        ];

        if ($this->hasMeaningfulEvidence($legacyEvidence)) {
            $evidence[] = $legacyEvidence;
        }

        return array_values(array_filter($evidence));
    }

    /**
     * @return array<int, mixed>
     */
    private function recommendedActions(ContentOpportunity $opportunity, ?Opportunity $canonical): array
    {
        $canonicalActions = is_array($canonical?->recommended_actions) ? $canonical->recommended_actions : [];

        return array_values(array_filter(array_merge($canonicalActions, [
            $opportunity->suggested_cta,
            $opportunity->suggested_schema,
            $opportunity->localization_recommendation,
        ])));
    }

    /**
     * @param  array<string, mixed>  $legacyFields
     * @param  array<int, mixed>  $evidence
     * @return array<int, string>
     */
    private function missingFields(?Opportunity $canonical, ?ClientSite $site, array $legacyFields, array $evidence): array
    {
        $missing = [];

        if (! $canonical) {
            $missing[] = 'canonical_opportunity_id';
        }

        if (! $site) {
            $missing[] = 'client_site_id';
        }

        foreach (['title', 'language', 'content_type', 'output_type', 'primary_keyword'] as $field) {
            if (blank($legacyFields[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        if ($evidence === []) {
            $missing[] = 'source_evidence';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function hasMeaningfulEvidence(array $evidence): bool
    {
        return collect($evidence)
            ->except(['type', 'source_model', 'source_id'])
            ->contains(fn (mixed $value): bool => filled($value));
    }

    private function string(mixed $value): string
    {
        return trim(Str::squish((string) $value));
    }
}
