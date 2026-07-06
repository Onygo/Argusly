<?php

namespace App\Services\PageIntelligence\Serp;

use App\Jobs\PageIntelligence\EvaluatePageAlertRulesJob;
use App\Models\ClientSite;
use App\Models\PageSerpObservation;
use App\Models\SerpQuery;
use App\Models\SerpQuerySet;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ImportSerpObservationsAction
{
    public function __construct(
        private readonly SerpProviderRegistry $providers,
        private readonly RecordSerpObservationAction $recordObservation,
    ) {
    }

    /**
     * @param array<string,mixed> $parameters
     * @return Collection<int,PageSerpObservation>
     */
    public function execute(Workspace $workspace, string $providerKey, array $parameters, ?ClientSite $site = null, ?SerpQuerySet $querySet = null): Collection
    {
        if ($querySet !== null && (string) $querySet->workspace_id !== (string) $workspace->id) {
            throw new InvalidArgumentException('The SERP query set does not belong to the selected workspace.');
        }

        $parameters = array_replace([
            'provider_key' => $providerKey,
            'serp_query_set_id' => $querySet?->id,
        ], $parameters);

        $observations = collect();

        foreach ($this->providers->get($providerKey)->observe($parameters) as $result) {
            $result = $this->attachQuery($workspace, $querySet, $result);
            $observations->push($this->recordObservation->execute($workspace, $result, $site));
        }

        if ($observations->isNotEmpty()) {
            EvaluatePageAlertRulesJob::dispatch()->afterCommit();
        }

        return $observations;
    }

    private function attachQuery(Workspace $workspace, ?SerpQuerySet $querySet, SerpObservationResult $result): SerpObservationResult
    {
        if ($result->serpQueryId !== null || $querySet === null) {
            return $result;
        }

        $query = SerpQuery::query()->firstOrCreate([
            'serp_query_set_id' => $querySet->id,
            'query_hash' => hash('sha256', mb_strtolower(trim($result->query))),
            'search_engine' => $result->searchEngine,
            'country' => $result->country ? strtoupper($result->country) : null,
            'device' => $result->device,
        ], [
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $querySet->client_site_id,
            'query' => $result->query,
            'locale' => $result->locale ?? $querySet->locale,
            'keyword_intent' => $result->keywordIntent,
            'search_volume' => $result->searchVolume,
            'status' => SerpQuery::STATUS_ACTIVE,
            'metadata_json' => [
                'created_from' => 'manual_import',
                'provider_key' => $result->providerKey,
            ],
        ]);

        return new SerpObservationResult(
            query: $result->query,
            pageUrl: $result->pageUrl,
            locale: $result->locale ?? $query->locale,
            country: $result->country ?? $query->country,
            device: $result->device,
            searchEngine: $result->searchEngine,
            observedAt: $result->observedAt,
            resultType: $result->resultType,
            position: $result->position,
            absolutePosition: $result->absolutePosition,
            title: $result->title,
            snippet: $result->snippet,
            serpFeatures: $result->serpFeatures,
            competitorPresence: $result->competitorPresence,
            searchVolume: $result->searchVolume ?? $query->search_volume,
            keywordIntent: $result->keywordIntent ?? $query->keyword_intent,
            clickPotential: $result->clickPotential,
            rawPayload: $result->rawPayload,
            providerKey: $result->providerKey,
            serpQuerySetId: (string) $querySet->id,
            serpQueryId: (string) $query->id,
            metadata: array_replace_recursive($result->metadata, [
                'serp_query_set_id' => $querySet->id,
                'serp_query_id' => $query->id,
            ]),
        );
    }
}
