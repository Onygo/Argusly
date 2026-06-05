<?php

namespace App\Services\ContentOpportunityEngine;

use App\DTO\QueryIntent\QueryIntentInput;
use App\Models\ContentOpportunity;
use App\Models\ContentOpportunityRun;
use App\Models\Workspace;
use App\Services\QueryIntent\QueryIntentIntelligenceService;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContentOpportunityEngine
{
    public function __construct(
        private readonly ContentOpportunityInputBuilder $inputBuilder,
        private readonly ContentOpportunityCandidateGenerator $candidateGenerator,
        private readonly ContentOpportunityInternalLinkService $internalLinkService,
        private readonly QueryIntentIntelligenceService $queryIntentService,
        private readonly ContentOpportunityScoringEngine $scoringEngine,
        private readonly ContentOpportunityLifecycleService $lifecycleService,
        private readonly ContentOpportunityDedupe $dedupe,
    ) {}

    public function run(Workspace $workspace, ?string $clientSiteId = null, array $options = []): ContentOpportunityRun
    {
        $run = ContentOpportunityRun::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => $clientSiteId,
            'status' => 'running',
            'source_type' => (string) ($options['source_type'] ?? 'agentic_marketing'),
            'input' => $options,
            'started_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($workspace, $clientSiteId, $run): void {
                $input = $this->inputBuilder->build($workspace, $clientSiteId);
                $candidates = $this->candidateGenerator->generate($input);
                $created = 0;
                $refreshed = 0;
                $ids = [];

                foreach ($candidates as $candidate) {
                    $opportunity = $this->persistCandidate($workspace, $clientSiteId, $run, $candidate);
                    $opportunity->wasRecentlyCreated ? $created++ : $refreshed++;
                    $ids[] = (string) $opportunity->id;
                }

                $run->forceFill([
                    'status' => 'completed',
                    'candidates_count' => count($candidates),
                    'created_count' => $created,
                    'refreshed_count' => $refreshed,
                    'result' => [
                        'opportunity_ids' => $ids,
                        'types' => collect($candidates)->countBy('type')->all(),
                    ],
                    'finished_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $run->refresh();
    }

    private function persistCandidate(Workspace $workspace, ?string $clientSiteId, ContentOpportunityRun $run, ContentOpportunityCandidate $candidate): ContentOpportunity
    {
        $intent = $this->queryIntentService->classify(new QueryIntentInput(
            title: $candidate->title,
            query: $candidate->topic,
            text: $candidate->reasoning . ' ' . $candidate->angle,
            sourceType: 'content_opportunity_engine',
            sourceKey: $candidate->type . ':' . $candidate->topic,
            workspaceId: (string) $workspace->id,
            clientSiteId: $clientSiteId,
            organizationId: $workspace->organization_id,
            context: ['type' => $candidate->type],
        ));
        $score = $this->scoringEngine->score($candidate, $intent);
        $links = $this->internalLinkService->supportingContent($workspace, $candidate->topic, $candidate->relatedEntities, $clientSiteId);
        $hash = $this->dedupe->hash((string) $workspace->id, $candidate->type, $candidate->title, $candidate->topic);
        $existing = ContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->where('dedupe_hash', $hash)
            ->first();
        $lifecycle = $this->lifecycleService->freshnessPayload($existing);
        $payload = $this->normalizedPayload($candidate, $intent->toArray(), $score, $links);

        $opportunity = ContentOpportunity::query()->updateOrCreate(
            [
                'workspace_id' => (string) $workspace->id,
                'dedupe_hash' => $hash,
            ],
            array_merge($lifecycle, [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $clientSiteId,
                'content_opportunity_run_id' => (string) $run->id,
                'type' => $candidate->type,
                'status' => ContentOpportunity::STATUS_OPEN,
                'title' => $candidate->title,
                'reasoning' => $candidate->reasoning,
                'why_this_matters' => $this->whyThisMatters($candidate, $score),
                'why_now' => $this->whyNow($candidate),
                'competitor_pressure' => $this->competitorPressure($candidate),
                'ai_visibility_opportunity' => $this->aiVisibilityOpportunity($candidate),
                'target_audience' => $candidate->targetAudience ?: $intent->buyerRole,
                'funnel_stage' => $candidate->funnelStage ?: $intent->funnelStage,
                'primary_search_intent' => $candidate->searchIntent ?: $intent->primaryIntent,
                'angle' => $candidate->angle,
                'expected_impact' => $score['expected_impact'],
                'confidence_score' => $score['confidence_score'],
                'urgency_score' => $score['urgency_score'],
                'business_value_score' => $score['business_value_score'],
                'priority_score' => $score['priority_score'],
                'related_entities' => $candidate->relatedEntities,
                'supporting_existing_content' => $links,
                'recommended_internal_links' => $links,
                'localization_recommendation' => $this->localizationRecommendation($candidate),
                'suggested_cta' => $candidate->suggestedCta ?: $this->cta($candidate->type),
                'suggested_schema' => $candidate->suggestedSchema ?: $this->schema($candidate->type),
                'source_signals' => $candidate->sourceSignals,
                'query_intent_payload' => $intent->toArray(),
                'normalized_payload' => $payload,
            ])
        );

        $this->queryIntentService->classifyAndPersist(new QueryIntentInput(
            title: $opportunity->title,
            query: $candidate->topic,
            text: trim($opportunity->reasoning . ' ' . $opportunity->angle . ' ' . $opportunity->why_this_matters),
            sourceType: 'content_opportunity',
            sourceKey: (string) $opportunity->id,
            workspaceId: (string) $workspace->id,
            clientSiteId: $clientSiteId,
            organizationId: $workspace->organization_id,
            classifiable: $opportunity,
            context: ['type' => $opportunity->type],
        ));

        return $opportunity;
    }

    private function normalizedPayload(ContentOpportunityCandidate $candidate, array $intent, array $score, array $links): array
    {
        return [
            'schema' => 'content_opportunity_engine.v1',
            'candidate' => [
                'type' => $candidate->type,
                'title' => $candidate->title,
                'topic' => $candidate->topic,
                'angle' => $candidate->angle,
            ],
            'query_intent' => $intent,
            'score' => $score,
            'internal_links' => $links,
            'agent_ready' => true,
        ];
    }

    private function whyThisMatters(ContentOpportunityCandidate $candidate, array $score): string
    {
        return sprintf('This %s can expand net-new demand coverage with %s expected business impact and a %.0f priority score.', str_replace('_', ' ', $candidate->type), $score['expected_impact'], $score['priority_score']);
    }

    private function whyNow(ContentOpportunityCandidate $candidate): string
    {
        return match ((string) ($candidate->sourceSignals['source'] ?? '')) {
            'competitor_intelligence', 'competitor_topic_signal' => 'Competitor signals are already active, so delaying lets competitors keep shaping the query space.',
            'content_inventory' => 'Lifecycle and visibility scores indicate existing support content is ready for a refresh or expansion.',
            default => 'Company intelligence identifies this as strategically relevant for the current positioning and authority map.',
        };
    }

    private function competitorPressure(ContentOpportunityCandidate $candidate): string
    {
        return str_contains((string) ($candidate->sourceSignals['source'] ?? ''), 'competitor')
            ? 'Competitor intelligence shows active coverage or pressure on this topic.'
            : 'No direct competitor pressure source was required, but this opportunity can defend category authority.';
    }

    private function aiVisibilityOpportunity(ContentOpportunityCandidate $candidate): string
    {
        return in_array($candidate->type, ['faq_opportunity', 'answer_block_opportunity', 'implementation_guide', 'comparison_page'], true)
            ? 'The format is well suited for answer extraction, entity reinforcement, and AI visibility citations.'
            : 'The page can strengthen entity coverage and internal context for AI systems.';
    }

    private function localizationRecommendation(ContentOpportunityCandidate $candidate): array
    {
        return [
            'recommended' => in_array($candidate->type, ['comparison_page', 'bofu_page', 'use_case_page', 'campaign_cluster'], true),
            'priority_locales' => ['en'],
            'reason' => 'Localize after validating demand and strategic fit in the source locale.',
        ];
    }

    private function cta(string $type): string
    {
        return match ($type) {
            'comparison_page', 'bofu_page', 'feature_page' => 'Book a demo',
            'campaign_cluster' => 'Plan this campaign',
            default => 'Explore Argusly',
        };
    }

    private function schema(string $type): string
    {
        return match ($type) {
            'faq_opportunity', 'answer_block_opportunity' => 'FAQPage',
            'implementation_guide' => 'HowTo',
            'campaign_cluster' => 'CollectionPage',
            default => 'Article',
        };
    }
}
