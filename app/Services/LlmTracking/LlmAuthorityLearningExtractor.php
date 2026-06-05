<?php

namespace App\Services\LlmTracking;

use App\Models\LlmAuthorityEntityCandidate;
use App\Models\LlmAuthorityLearning;
use App\Models\LlmTrackingQueryRun;
use Illuminate\Support\Str;

class LlmAuthorityLearningExtractor
{
    public function extractForCandidate(LlmAuthorityEntityCandidate $candidate, LlmTrackingQueryRun $run): void
    {
        $candidate->loadMissing('trackingQuery');
        $provider = trim((string) ($run->provider ?? ''));
        $contexts = collect((array) data_get($candidate->evidence, 'latest_context', []))
            ->map(fn ($context): string => trim((string) $context))
            ->filter()
            ->values();

        $domains = collect((array) ($candidate->source_urls ?? []))
            ->map(fn ($url): string => Str::lower((string) parse_url((string) $url, PHP_URL_HOST)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $positioning = $contexts->first() ?: ((string) $candidate->brand_name . ' is repeatedly surfaced for this tracked category.');
        $this->upsertLearning($candidate, $run, $provider, 'positioning', [
            'title' => $candidate->brand_name . ' positioning signal',
            'summary' => $positioning,
            'recommended_action' => 'Compare this phrasing with the tracked brand positioning and close missing category associations with concise, source-backed copy.',
            'priority' => 2,
        ]);

        if ($domains !== []) {
            $this->upsertLearning($candidate, $run, $provider, 'cited_source_domains', [
                'title' => $candidate->brand_name . ' cited source footprint',
                'summary' => $candidate->brand_name . ' appears alongside cited domains such as ' . implode(', ', array_slice($domains, 0, 4)) . '.',
                'recommended_action' => 'Prioritize earned authority on comparable third-party domains instead of relying only on owned pages.',
                'priority' => 1,
            ]);
        }

        if ((string) $candidate->entity_category !== 'competitor') {
            $this->upsertLearning($candidate, $run, $provider, 'authority_benchmark', [
                'title' => $candidate->brand_name . ' authority benchmark',
                'summary' => $candidate->brand_name . ' is categorized as ' . str_replace('_', ' ', (string) $candidate->entity_category) . ', so it should be used as an authority pattern, not automatically as a direct competitor.',
                'recommended_action' => 'Use the evidence to learn retrieval and citation patterns before deciding whether to track it as a competitor.',
                'priority' => 3,
            ]);
        }
    }

    /**
     * @param array{title:string,summary:string,recommended_action:string,priority:int} $payload
     */
    private function upsertLearning(LlmAuthorityEntityCandidate $candidate, LlmTrackingQueryRun $run, string $provider, string $type, array $payload): void
    {
        LlmAuthorityLearning::query()->updateOrCreate(
            [
                'client_site_id' => $candidate->client_site_id,
                'llm_authority_entity_candidate_id' => $candidate->id,
                'llm_tracking_query_id' => $run->llm_tracking_query_id,
                'provider' => $provider !== '' ? $provider : null,
                'learning_type' => $type,
            ],
            [
                'workspace_id' => $candidate->workspace_id,
                'site_competitor_id' => $candidate->site_competitor_id,
                'title' => $payload['title'],
                'summary' => $payload['summary'],
                'evidence' => [
                    'candidate_id' => $candidate->id,
                    'brand_name' => $candidate->brand_name,
                    'entity_category' => $candidate->entity_category,
                    'source_urls' => $candidate->source_urls,
                    'provider_breakdown' => $candidate->provider_breakdown,
                    'latest_context' => data_get($candidate->evidence, 'latest_context', []),
                ],
                'recommended_action' => $payload['recommended_action'],
                'priority' => $payload['priority'],
                'status' => 'active',
            ],
        );
    }
}
