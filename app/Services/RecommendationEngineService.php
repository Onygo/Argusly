<?php

namespace App\Services;

use App\Models\IntelligenceSignal;
use App\Models\Recommendation;
use Illuminate\Support\Collection;

class RecommendationEngineService
{
    public function __construct(private readonly EvidenceService $evidence) {}

    /**
     * @return Collection<int, Recommendation>
     */
    public function generateForSignal(IntelligenceSignal $signal): Collection
    {
        return collect($this->rulesFor($signal))
            ->map(fn (array $attributes) => $this->store($signal, $attributes))
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rulesFor(IntelligenceSignal $signal): array
    {
        $payload = $signal->payload ?? [];
        $score = (int) ($payload['score'] ?? $payload['health_score'] ?? $signal->impact_score ?? 0);
        $contentAssetId = $payload['content_asset_id'] ?? null;

        return match (true) {
            $signal->type === 'content_opportunity',
            $signal->type === 'lifecycle_score_degraded' => $this->contentOpportunityRecommendations($signal, $payload, $contentAssetId, $score),
            $signal->category === 'social' && $signal->type === 'publishing_failed' => [
                $this->recommendation(
                    'Reconnect LinkedIn profile',
                    'A social post failed and the connected profile may need attention before retrying.',
                    'Reconnect the LinkedIn profile, confirm permissions and retry the failed social post.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 92, 'confidence_score' => 96],
                ),
            ],
            $signal->category === 'social' && $signal->type === 'publishing_completed' => [
                $this->recommendation(
                    'Monitor social engagement',
                    'Published social content should be monitored for engagement and follow-up opportunities.',
                    'Review the published post performance and schedule a follow-up if it supports the campaign.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 45, 'confidence_score' => 86],
                ),
            ],
            $signal->type === 'content_audit_completed' => $this->contentAuditRecommendations($signal, $payload),
            $signal->type === 'content_event',
            $signal->type === 'publishing_completed',
            $signal->type === 'integration_event' && ($payload['event'] ?? null) === 'publishing_completed' => [
                $this->recommendation(
                    'Generate LinkedIn post',
                    'Newly published content can be repurposed into a social distribution asset.',
                    'Generate a LinkedIn post that summarizes the strongest point and links back to the published asset.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 45, 'confidence_score' => 86],
                ),
            ],
            $signal->category === 'visibility',
            $signal->type === 'visibility_change' => [
                $this->recommendation(
                    'Connect Search Console',
                    'Visibility signals become more actionable when Search Console data is available for the brand.',
                    'Connect Search Console to enrich visibility changes with query, page and indexing data.',
                    $signal,
                    ['impact_score' => 70, 'confidence_score' => 82],
                ),
            ],
            $signal->type === 'generation_completed' => [
                $this->recommendation(
                    'Create Answer Block',
                    'Generated content can be converted into a reusable answer asset for AI and search surfaces.',
                    'Extract the clearest direct answer from the generation and save it as an answer block.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 50, 'confidence_score' => 84],
                ),
            ],
            $signal->type === 'publishing_failed' => [
                $this->recommendation(
                    'Review publishing integration',
                    'Publishing failed and may block distribution until the channel or integration is fixed.',
                    'Check the publishing channel, integration permissions and error payload before retrying.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 90, 'confidence_score' => 96],
                ),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function contentOpportunityRecommendations(IntelligenceSignal $signal, array $payload, mixed $contentAssetId, int $score): array
    {
        if (($payload['domain_event_type'] ?? null) === 'ContentAssetMissingSocialDistribution') {
            return [
                $this->recommendation(
                    'Create LinkedIn post',
                    'This content asset has no social distribution yet.',
                    'Create a LinkedIn post from this article and schedule it for the active brand profile.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 68, 'confidence_score' => 90],
                ),
            ];
        }

        if (($payload['domain_event_type'] ?? null) === 'CampaignMissingScheduledSocialPosts') {
            return [
                $this->recommendation(
                    'Schedule social distribution',
                    'This campaign has content but no scheduled social posts.',
                    'Schedule social distribution for the campaign so its content has a clear publishing path.',
                    $signal,
                    ['impact_score' => 78, 'confidence_score' => 90],
                ),
            ];
        }

        return [
            $this->recommendation(
                'Refresh article',
                'The source signal indicates this article is stale, degraded or underperforming.',
                'Refresh the article with current evidence, clearer structure and updated answer-focused sections.',
                $signal,
                ['content_asset_id' => $contentAssetId],
            ),
            $this->recommendation(
                'Run content audit',
                'A fresh audit will reveal the highest-impact visibility and structure fixes before editing.',
                'Run a content audit and use the findings to prioritize the refresh plan.',
                $signal,
                ['content_asset_id' => $contentAssetId, 'impact_score' => min(100, max(55, $score + 10))],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function contentAuditRecommendations(IntelligenceSignal $signal, array $payload): array
    {
        $recommendations = [
            $this->recommendation(
                'Create FAQ',
                'The audit found opportunities to answer common questions more directly.',
                'Create an FAQ section that covers the most important customer questions surfaced by the audit.',
                $signal,
                ['content_asset_id' => $payload['content_asset_id'] ?? null, 'impact_score' => 62, 'confidence_score' => 84],
            ),
            $this->recommendation(
                'Create Answer Block',
                'Answer-shaped content can help the brand appear in AI and search answer surfaces.',
                'Create an answer block from the clearest recommendation in the audit.',
                $signal,
                ['content_asset_id' => $payload['content_asset_id'] ?? null, 'impact_score' => 58, 'confidence_score' => 86],
            ),
        ];

        if ((int) ($payload['score'] ?? 100) < 70) {
            $recommendations[] = $this->recommendation(
                'Refresh article',
                'The audit score suggests the article needs editorial and structural improvements.',
                'Refresh the article using the audit issues and recommendations as the editing checklist.',
                $signal,
                ['content_asset_id' => $payload['content_asset_id'] ?? null],
            );
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function recommendation(string $title, string $summary, string $action, IntelligenceSignal $signal, array $overrides = []): array
    {
        return [
            'title' => $title,
            'summary' => $summary,
            'recommended_action' => $action,
            'impact_score' => $overrides['impact_score'] ?? $signal->impact_score,
            'confidence_score' => $overrides['confidence_score'] ?? $signal->confidence_score,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(IntelligenceSignal $signal, array $attributes): Recommendation
    {
        $recommendation = Recommendation::query()->updateOrCreate(
            [
                'account_id' => $signal->account_id,
                'signal_id' => $signal->id,
                'title' => $attributes['title'],
            ],
            [
                ...$attributes,
                'brand_id' => $signal->brand_id,
                'status' => Recommendation::query()
                    ->where('account_id', $signal->account_id)
                    ->where('signal_id', $signal->id)
                    ->where('title', $attributes['title'])
                    ->value('status') ?? 'new',
            ],
        );

        if (! $recommendation->evidenceItems()->exists()) {
            $this->evidence->copyBetweenSubjects($signal, $recommendation);
        }

        return $recommendation;
    }
}
