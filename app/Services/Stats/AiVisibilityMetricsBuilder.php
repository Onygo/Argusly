<?php

namespace App\Services\Stats;

use App\Models\LlmTrackingQueryRun;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiVisibilityMetricsBuilder
{
    public function rebuild(?string $analyticsSiteId = null): int
    {
        if (! $this->tablesAvailable()) {
            return 0;
        }

        $analyticsSites = DB::table('analytics_sites')
            ->select(['id', 'client_site_id'])
            ->when(
                is_string($analyticsSiteId) && $analyticsSiteId !== '',
                fn ($query) => $query->where('id', $analyticsSiteId)
            )
            ->get();

        $analyticsByClientSite = $analyticsSites
            ->mapWithKeys(fn ($row): array => [(string) $row->client_site_id => (string) $row->id])
            ->all();

        if ($analyticsByClientSite === []) {
            return 0;
        }

        $aggregated = [];

        LlmTrackingQueryRun::query()
            ->with('trackingQuery:id,client_site_id')
            ->where('status', 'succeeded')
            ->where(function ($query): void {
                $query->where('is_cached', false)->orWhereNull('is_cached');
            })
            ->when(
                is_string($analyticsSiteId) && $analyticsSiteId !== '',
                function ($query) use ($analyticsByClientSite): void {
                    $clientSiteIds = array_keys($analyticsByClientSite);
                    $query->whereHas('trackingQuery', fn ($builder) => $builder->whereIn('client_site_id', $clientSiteIds));
                }
            )
            ->orderBy('id')
            ->chunkById(200, function (Collection $runs) use (&$aggregated, $analyticsByClientSite): void {
                foreach ($runs as $run) {
                    $clientSiteId = (string) ($run->trackingQuery?->client_site_id ?? '');
                    $siteId = (string) ($analyticsByClientSite[$clientSiteId] ?? '');
                    if ($siteId === '') {
                        continue;
                    }

                    $brandMentions = $this->sumMentionCounts((array) ($run->brand_hits ?? []));
                    $competitorMentions = $this->sumMentionCounts((array) ($run->competitor_hits ?? []));

                    foreach ((array) ($run->url_hits ?? []) as $urlHit) {
                        $rawUrl = trim((string) ($urlHit['target_url'] ?? $urlHit['url'] ?? ''));
                        $normalizedUrl = AnalyticsUrlKey::normalizeUrl($rawUrl);
                        if ($normalizedUrl === null) {
                            continue;
                        }

                        $urlKey = AnalyticsUrlKey::fromUrl($normalizedUrl);
                        if ($urlKey === '') {
                            continue;
                        }

                        $citations = max(1, (int) ($urlHit['count'] ?? 1));
                        $bucket = $siteId . '|' . $urlKey;

                        if (! isset($aggregated[$bucket])) {
                            $aggregated[$bucket] = [
                                'analytics_site_id' => $siteId,
                                'url' => $normalizedUrl,
                                'url_key' => $urlKey,
                                'llm_citations' => 0,
                                'brand_mentions' => 0,
                                'competitor_mentions' => 0,
                            ];
                        }

                        $aggregated[$bucket]['llm_citations'] += $citations;
                        $aggregated[$bucket]['brand_mentions'] += $brandMentions;
                        $aggregated[$bucket]['competitor_mentions'] += $competitorMentions;
                    }
                }
            });

        if (is_string($analyticsSiteId) && $analyticsSiteId !== '') {
            DB::table('content_ai_visibility')->where('analytics_site_id', $analyticsSiteId)->delete();
        } else {
            DB::table('content_ai_visibility')->delete();
        }

        if ($aggregated === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($aggregated as $item) {
            $competitors = max(1, (int) ($item['competitor_mentions'] ?? 0));
            $llmCitations = (int) ($item['llm_citations'] ?? 0);
            $brandMentions = (int) ($item['brand_mentions'] ?? 0);
            $score = round((($llmCitations * 2) + ($brandMentions * 1.5)) / $competitors, 2);

            $rows[] = [
                'analytics_site_id' => (string) $item['analytics_site_id'],
                'url' => (string) $item['url'],
                'url_key' => (string) $item['url_key'],
                'llm_citations' => $llmCitations,
                'brand_mentions' => $brandMentions,
                'competitor_mentions' => max(0, (int) ($item['competitor_mentions'] ?? 0)),
                'ai_visibility_score' => max(0.0, $score),
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('content_ai_visibility')->insert($rows);

        return count($rows);
    }

    /**
     * @param  array<int,array<string,mixed>>  $mentions
     */
    private function sumMentionCounts(array $mentions): int
    {
        return collect($mentions)
            ->map(fn (array $row): int => max(0, (int) ($row['count'] ?? 0)))
            ->sum();
    }

    private function tablesAvailable(): bool
    {
        return Schema::hasTable('analytics_sites')
            && Schema::hasTable('content_ai_visibility')
            && Schema::hasTable('llm_tracking_queries')
            && Schema::hasTable('llm_tracking_query_runs');
    }
}
