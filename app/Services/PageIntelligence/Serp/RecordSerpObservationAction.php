<?php

namespace App\Services\PageIntelligence\Serp;

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\ClientSite;
use App\Models\PageSerpObservation;
use App\Models\SerpQuery;
use App\Models\SerpQuerySet;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use App\Services\PageIntelligence\SubmitMonitoredPageAction;
use App\Services\SignalIntelligence\SignalEventIngestor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordSerpObservationAction
{
    public function __construct(
        private readonly SubmitMonitoredPageAction $submitMonitoredPage,
        private readonly SerpVisibilityScoreCalculator $scoreCalculator,
        private readonly SignalEventIngestor $signalEventIngestor,
        private readonly PageIntelligenceScoreCalculator $intelligenceScoreCalculator,
    ) {
    }

    /**
     * @param SerpObservationResult|array<string,mixed> $result
     */
    public function execute(Workspace $workspace, SerpObservationResult|array $result, ?ClientSite $site = null): PageSerpObservation
    {
        $result = is_array($result) ? SerpObservationResult::fromArray($result) : $result;
        $observedAt = $result->observedAtOrNow();

        return DB::transaction(function () use ($workspace, $site, $result, $observedAt): PageSerpObservation {
            $pageResult = $this->submitMonitoredPage->execute(
                workspace: $workspace,
                url: $result->pageUrl,
                site: $site,
                sourceType: 'serp',
                pageType: 'serp_result',
                extraMetadata: [
                    'serp' => [
                        'last_query' => $result->query,
                        'last_search_engine' => $result->searchEngine,
                        'last_observed_at' => $observedAt->toISOString(),
                    ],
                ],
            );

            $score = $this->scoreCalculator->calculate($result);
            [$querySetId, $queryId] = $this->validatedQueryReferences($workspace, $result);
            $competitorPresence = $this->linkedCompetitorPresence($workspace, $site, $result->competitorPresence);
            $previous = $this->previousObservation(
                workspace: $workspace,
                pageId: (string) $pageResult->page->id,
                result: $result,
                observedAt: $observedAt,
                querySetId: $querySetId,
            );

            $observation = PageSerpObservation::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $workspace->id,
                'client_site_id' => $site?->id ?? $pageResult->page->client_site_id,
                'monitored_page_id' => $pageResult->page->id,
                'page_snapshot_id' => $pageResult->page->latestSnapshot()->value('id'),
                'serp_query_set_id' => $querySetId,
                'serp_query_id' => $queryId,
                'query' => trim($result->query),
                'query_hash' => $this->queryHash($result->query),
                'locale' => $result->locale,
                'country' => $result->country ? strtoupper($result->country) : null,
                'device' => $result->device,
                'search_engine' => $result->searchEngine,
                'observed_at' => $observedAt,
                'result_type' => $result->resultType,
                'position' => $result->position,
                'absolute_position' => $result->absolutePosition ?? $result->position,
                'page_url' => $pageResult->url->firstSeenUrl,
                'page_url_hash' => hash('sha256', $pageResult->url->firstSeenUrl),
                'domain' => $pageResult->url->domain,
                'title' => $result->title,
                'snippet' => $result->snippet,
                'serp_features_json' => $result->serpFeatures,
                'competitor_presence_json' => $competitorPresence,
                'search_volume' => $result->searchVolume,
                'keyword_intent' => $result->keywordIntent,
                'click_potential' => $result->clickPotential,
                'visibility_score' => $score['score'],
                'breakdown_json' => $score['breakdown'],
                'raw_payload_json' => $this->jsonable($result->rawPayload),
                'provider_key' => $result->providerKey,
                'metadata_json' => array_replace_recursive($result->metadata, [
                    'page_created' => $pageResult->created,
                    'canonical_url' => $pageResult->page->canonical_url,
                    'score_input_provenance' => [
                        'serp_visibility_score_model' => data_get($score, 'breakdown.model.key'),
                        'serp_visibility_score_version' => data_get($score, 'breakdown.model.version'),
                    ],
                ]),
            ]);

            if ($previous !== null) {
                $this->emitPositionChangeSignal($workspace, $observation, $previous);
            }

            if ($snapshot = $pageResult->page->latestSnapshot()->first()) {
                $this->intelligenceScoreCalculator->calculate($snapshot);
            }

            return $observation->refresh();
        });
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function validatedQueryReferences(Workspace $workspace, SerpObservationResult $result): array
    {
        $querySetId = $result->serpQuerySetId;
        $queryId = $result->serpQueryId;

        if ($querySetId !== null) {
            $querySet = SerpQuerySet::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($querySetId)
                ->first();

            if (! $querySet) {
                throw new InvalidArgumentException('The SERP query set does not belong to the selected workspace.');
            }
        }

        if ($queryId !== null) {
            $query = SerpQuery::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($queryId)
                ->first();

            if (! $query) {
                throw new InvalidArgumentException('The SERP query does not belong to the selected workspace.');
            }

            if ($querySetId !== null && (string) $query->serp_query_set_id !== (string) $querySetId) {
                throw new InvalidArgumentException('The SERP query does not belong to the selected query set.');
            }

            $querySetId ??= (string) $query->serp_query_set_id;
        }

        return [$querySetId, $queryId];
    }

    /**
     * @param array<int|string,mixed> $competitorPresence
     * @return array<int|string,mixed>
     */
    private function linkedCompetitorPresence(Workspace $workspace, ?ClientSite $site, array $competitorPresence): array
    {
        if ($competitorPresence === []) {
            return [];
        }

        $competitors = SiteCompetitor::query()
            ->where('workspace_id', $workspace->id)
            ->when($site?->id, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($site): void {
                $query->whereNull('client_site_id')->orWhere('client_site_id', $site->id);
            }))
            ->get()
            ->keyBy(fn (SiteCompetitor $competitor): string => strtolower((string) $competitor->domain));

        return collect($competitorPresence)
            ->map(function (mixed $entry) use ($competitors): mixed {
                if (is_string($entry)) {
                    $entry = ['domain' => $entry];
                }

                if (! is_array($entry)) {
                    return $entry;
                }

                $domain = $this->normalizedDomain((string) ($entry['domain'] ?? $entry['url'] ?? ''));
                if ($domain === '') {
                    return $entry;
                }

                $competitor = $competitors[$domain] ?? null;

                return array_filter(array_replace($entry, [
                    'domain' => $domain,
                    'site_competitor_id' => $competitor?->id,
                    'site_competitor_name' => $competitor?->name,
                    'linked' => $competitor !== null,
                ]), fn (mixed $value): bool => $value !== null);
            })
            ->values()
            ->all();
    }

    private function normalizedDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);

        return strtolower(trim((string) $host));
    }

    private function previousObservation(Workspace $workspace, string $pageId, SerpObservationResult $result, mixed $observedAt, ?string $querySetId): ?PageSerpObservation
    {
        return PageSerpObservation::query()
            ->where('workspace_id', $workspace->id)
            ->where('monitored_page_id', $pageId)
            ->where('query_hash', $this->queryHash($result->query))
            ->where('query', trim($result->query))
            ->where('search_engine', $result->searchEngine)
            ->where('device', $result->device)
            ->where(function (Builder $query) use ($result): void {
                $result->locale === null
                    ? $query->whereNull('locale')
                    : $query->where('locale', $result->locale);
            })
            ->where(function (Builder $query) use ($result): void {
                $country = $result->country ? strtoupper($result->country) : null;
                $country === null
                    ? $query->whereNull('country')
                    : $query->where('country', $country);
            })
            ->where(function (Builder $query) use ($result): void {
                $result->providerKey === null
                    ? $query->whereNull('provider_key')
                    : $query->where('provider_key', $result->providerKey);
            })
            ->where(function (Builder $query) use ($querySetId): void {
                $querySetId === null
                    ? $query->whereNull('serp_query_set_id')
                    : $query->where('serp_query_set_id', $querySetId);
            })
            ->where('observed_at', '<', $observedAt)
            ->whereNotNull('absolute_position')
            ->latest('observed_at')
            ->first();
    }

    private function emitPositionChangeSignal(Workspace $workspace, PageSerpObservation $current, PageSerpObservation $previous): void
    {
        if ($current->absolute_position === null || $previous->absolute_position === null) {
            return;
        }

        $delta = $previous->absolute_position - $current->absolute_position;
        if ($delta === 0) {
            return;
        }

        $isGain = $delta > 0;
        $magnitude = abs($delta);
        $severity = $isGain ? SignalSeverity::INFO : ($magnitude >= 5 ? SignalSeverity::HIGH : SignalSeverity::MEDIUM);
        $strength = min(100, max(20, $magnitude * 10));
        $topic = $isGain ? 'SERP visibility gain' : 'SERP visibility loss';

        $this->signalEventIngestor->ingestEvent($workspace, [
            'client_site_id' => $current->client_site_id,
            'category' => $isGain ? SignalCategory::AI_VISIBILITY->value : SignalCategory::RISK->value,
            'type' => $isGain ? SignalType::TOPIC_TRENDING->value : SignalType::RISK_DECLINING_VISIBILITY->value,
            'severity' => $severity->value,
            'status' => SignalStatus::NEW->value,
            'topic' => $topic,
            'entity_name' => $current->domain,
            'entity_key' => 'serp:'.$current->domain,
            'signal_strength' => $strength,
            'confidence_score' => 85,
            'impact_score' => min(100, (float) $current->visibility_score + $magnitude),
            'risk_score' => $isGain ? null : min(100, 40 + ($magnitude * 8)),
            'opportunity_score' => $isGain ? min(100, 40 + ($magnitude * 8)) : null,
            'observed_at' => $current->observed_at,
            'evidence' => [[
                'type' => 'page_serp_observation',
                'page_serp_observation_id' => $current->id,
                'previous_page_serp_observation_id' => $previous->id,
                'query' => $current->query,
                'previous_position' => $previous->absolute_position,
                'current_position' => $current->absolute_position,
                'delta' => $delta,
            ]],
            'metrics' => [
                'previous_absolute_position' => $previous->absolute_position,
                'current_absolute_position' => $current->absolute_position,
                'position_delta' => $delta,
                'visibility_score' => (float) $current->visibility_score,
            ],
            'metadata' => [
                'source' => 'page_intelligence_serp',
                'direction' => $isGain ? 'gain' : 'loss',
                'monitored_page_id' => $current->monitored_page_id,
                'page_serp_observation_id' => $current->id,
                'previous_page_serp_observation_id' => $previous->id,
                'search_engine' => $current->search_engine,
                'device' => $current->device,
                'country' => $current->country,
                'locale' => $current->locale,
            ],
            'dedupe_hash' => $this->signalEventIngestor->dedupeHash([
                'workspace_id' => $workspace->id,
                'source' => 'page_intelligence_serp',
                'observation_id' => $current->id,
                'direction' => $isGain ? 'gain' : 'loss',
            ]),
        ]);
    }

    /**
     * @param array<string|int,mixed> $payload
     * @return array<string|int,mixed>
     */
    private function jsonable(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function queryHash(string $query): string
    {
        return hash('sha256', mb_strtolower(trim($query)));
    }
}
