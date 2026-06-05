<?php

namespace App\Services\LlmTracking;

use App\Models\LlmAuthorityEntityCandidate;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\SiteCompetitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LlmAuthorityCandidateService
{
    public function __construct(
        private readonly LlmAuthorityLearningExtractor $learningExtractor,
    ) {}

    public function recordRun(LlmTrackingQueryRun $run): void
    {
        $run->loadMissing('trackingQuery.site');
        $query = $run->trackingQuery;
        if (! $query || ! $query->client_site_id || ! $query->workspace_id) {
            return;
        }

        foreach ((array) ($run->authority_entities ?? []) as $entity) {
            $candidate = $this->upsertCandidate($query, $run, $entity);
            if ($candidate && in_array((string) $candidate->status, ['candidate', 'accepted'], true) && (float) ($candidate->confidence_score ?? 0) >= 0.65) {
                $this->learningExtractor->extractForCandidate($candidate, $run);
            }
        }
    }

    public function accept(LlmAuthorityEntityCandidate $candidate): SiteCompetitor
    {
        $candidate->loadMissing('site');

        $domain = $this->domainFromCandidate($candidate);
        $existing = SiteCompetitor::query()
            ->where('client_site_id', $candidate->client_site_id)
            ->where(function ($query) use ($candidate, $domain): void {
                $query->whereRaw('LOWER(name) = ?', [Str::lower((string) $candidate->brand_name)]);
                if ($domain !== '') {
                    $query->orWhere('domain', $domain);
                }
            })
            ->first();

        $competitor = $existing ?: SiteCompetitor::query()->create([
            'workspace_id' => $candidate->workspace_id,
            'client_site_id' => $candidate->client_site_id,
            'name' => (string) $candidate->brand_name,
            'domain' => $domain !== '' ? $domain : Str::slug((string) $candidate->brand_name) . '.unknown',
            'notes' => 'Added from AI visibility authority-entity detection. Category: ' . (string) $candidate->entity_category,
            'is_active' => true,
        ]);

        $candidate->forceFill([
            'status' => 'accepted',
            'site_competitor_id' => $competitor->id,
        ])->save();

        return $competitor;
    }

    public function ignore(LlmAuthorityEntityCandidate $candidate): void
    {
        $candidate->forceFill(['status' => 'ignored'])->save();
    }

    /**
     * @return Collection<int,LlmAuthorityEntityCandidate>
     */
    public function candidatesForSite(string $siteId, array $statuses = ['candidate']): Collection
    {
        return LlmAuthorityEntityCandidate::query()
            ->where('client_site_id', $siteId)
            ->whereIn('status', $statuses)
            ->orderByDesc('confidence_score')
            ->orderByDesc('mention_count')
            ->limit(25)
            ->get();
    }

    /**
     * @param array<string,mixed> $entity
     */
    private function upsertCandidate(LlmTrackingQuery $query, LlmTrackingQueryRun $run, array $entity): ?LlmAuthorityEntityCandidate
    {
        $normalized = trim((string) ($entity['normalized_name'] ?? ''));
        if ($normalized === '') {
            return null;
        }

        /** @var LlmAuthorityEntityCandidate|null $candidate */
        $candidate = LlmAuthorityEntityCandidate::query()
            ->where('client_site_id', $query->client_site_id)
            ->where('normalized_name', $normalized)
            ->first();

        $provider = trim((string) ($run->provider ?? 'unknown')) ?: 'unknown';
        $rank = max(1, (int) ($entity['rank'] ?? 1));
        $mentionCount = max(1, (int) ($entity['mention_count'] ?? 1));
        $sourceUrls = collect((array) ($candidate?->source_urls ?? []))
            ->merge((array) ($entity['source_urls'] ?? []))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        $providerBreakdown = (array) ($candidate?->provider_breakdown ?? []);
        $providerBreakdown[$provider] = [
            'mention_count' => (int) data_get($providerBreakdown, $provider . '.mention_count', 0) + $mentionCount,
            'latest_rank' => $rank,
            'last_seen_at' => optional($run->run_at)->toIso8601String(),
            'models' => collect((array) data_get($providerBreakdown, $provider . '.models', []))
                ->push(trim((string) ($run->model ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];

        $queryBreakdown = (array) ($candidate?->query_breakdown ?? []);
        $queryKey = (string) $query->id;
        $queryBreakdown[$queryKey] = [
            'query' => (string) $query->query_text,
            'latest_variant' => (string) ($run->prompt_variant_key ?? 'exact'),
            'mention_count' => (int) data_get($queryBreakdown, $queryKey . '.mention_count', 0) + $mentionCount,
            'latest_rank' => $rank,
        ];

        $previousMentionCount = (int) ($candidate?->mention_count ?? 0);
        $newMentionCount = $previousMentionCount + $mentionCount;
        $previousAverage = is_numeric($candidate?->average_rank) ? (float) $candidate->average_rank : $rank;
        $averageRank = $previousMentionCount > 0
            ? (($previousAverage * $previousMentionCount) + ($rank * $mentionCount)) / $newMentionCount
            : $rank;

        $payload = [
            'workspace_id' => $query->workspace_id,
            'client_site_id' => $query->client_site_id,
            'llm_tracking_query_id' => $query->id,
            'brand_name' => (string) ($entity['brand_name'] ?? Str::headline($normalized)),
            'normalized_name' => $normalized,
            'entity_category' => (string) ($entity['entity_category'] ?? 'benchmark'),
            'mention_count' => $newMentionCount,
            'average_rank' => round($averageRank, 2),
            'latest_rank' => $rank,
            'first_seen_at' => $candidate?->first_seen_at ?: $run->run_at,
            'last_seen_at' => $run->run_at,
            'source_urls' => $sourceUrls,
            'provider_breakdown' => $providerBreakdown,
            'query_breakdown' => $queryBreakdown,
            'evidence' => [
                'latest_query' => (string) $query->query_text,
                'latest_provider' => $provider,
                'latest_model' => (string) ($run->model ?? ''),
                'latest_reason' => (string) ($entity['reason'] ?? ''),
                'latest_context' => array_slice((array) ($entity['context_snippets'] ?? []), 0, 3),
                'same_category' => (bool) ($entity['same_category'] ?? false),
            ],
            'confidence_score' => max((float) ($candidate?->confidence_score ?? 0), (float) ($entity['confidence_score'] ?? 0.5)),
            'status' => $candidate?->status ?: 'candidate',
        ];

        if ($candidate) {
            $candidate->forceFill($payload)->save();

            return $candidate;
        }

        return LlmAuthorityEntityCandidate::query()->create($payload);
    }

    private function domainFromCandidate(LlmAuthorityEntityCandidate $candidate): string
    {
        $url = collect((array) ($candidate->source_urls ?? []))->first();
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            return Str::lower(preg_replace('/^www\./', '', $host) ?? $host);
        }

        return '';
    }
}
