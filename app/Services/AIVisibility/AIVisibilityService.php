<?php

namespace App\Services\AIVisibility;

use App\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AIVisibilityService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $cache = [];

    /**
     * @return array{
     *   score:int|null,
     *   trend:int,
     *   provider_pills:array<int,array{provider:string,score:int|null,tone:string}>,
     *   citation_count:int,
     *   sentiment:?string,
     *   entities_detected:array<int,string>
     * }
     */
    public function forContent(Content $content): array
    {
        $cacheKey = (string) $content->id;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $content->loadMissing('clientSite.analyticsSite', 'aiVisibilitySnapshots');

        $snapshots = $content->aiVisibilitySnapshots
            ->groupBy(fn ($snapshot): string => (string) $snapshot->provider)
            ->map(fn (Collection $rows) => $rows->sortByDesc('captured_at')->values());

        $latestProviderScores = $snapshots
            ->map(function (Collection $rows, string $provider): array {
                $latest = $rows->first();

                return [
                    'provider' => $provider,
                    'score' => is_numeric($latest?->visibility_score) ? (int) $latest->visibility_score : null,
                    'tone' => $this->scoreTone(is_numeric($latest?->visibility_score) ? (int) $latest->visibility_score : null),
                ];
            })
            ->values();

        $trend = (int) round($snapshots->map(function (Collection $rows): int {
            $latest = $rows->get(0);
            $previous = $rows->get(1);

            return (int) (($latest?->visibility_score ?? 0) - ($previous?->visibility_score ?? $latest?->visibility_score ?? 0));
        })->avg() ?? 0);

        $fallbackScore = $this->fallbackScore($content);
        $snapshotScore = $latestProviderScores->pluck('score')->filter(fn ($score) => is_int($score))->avg();
        $score = is_numeric($snapshotScore) ? (int) round((float) $snapshotScore) : $fallbackScore;

        $result = [
            'score' => $score,
            'trend' => $trend,
            'provider_pills' => $latestProviderScores->isNotEmpty()
                ? $latestProviderScores->take(4)->all()
                : $this->mockProviders($fallbackScore),
            'citation_count' => (int) $content->aiVisibilitySnapshots->sum('citation_count'),
            'sentiment' => $content->aiVisibilitySnapshots->sortByDesc('captured_at')->first()?->sentiment,
            'entities_detected' => $content->aiVisibilitySnapshots
                ->flatMap(fn ($snapshot): array => (array) ($snapshot->entities_detected ?? []))
                ->filter(fn ($entity): bool => is_string($entity) && trim($entity) !== '')
                ->unique()
                ->values()
                ->take(8)
                ->all(),
        ];

        return $this->cache[$cacheKey] = $result;
    }

    private function fallbackScore(Content $content): ?int
    {
        if (is_numeric($content->ai_visibility_score)) {
            return (int) $content->ai_visibility_score;
        }

        $analyticsSiteId = (string) ($content->clientSite?->analyticsSite?->id ?? '');
        $urlKey = collect([
            trim((string) ($content->canonical_url_key ?? '')),
            trim((string) ($content->publish_url_key ?? '')),
        ])->first(fn (string $value): bool => $value !== '');

        if ($analyticsSiteId !== '' && $urlKey !== '') {
            $row = DB::table('content_ai_visibility')
                ->where('analytics_site_id', $analyticsSiteId)
                ->where('url_key', $urlKey)
                ->first(['ai_visibility_score']);

            if (is_numeric($row?->ai_visibility_score ?? null)) {
                return max(0, min(100, (int) round((float) $row->ai_visibility_score)));
            }
        }

        if (is_numeric($content->aeo_score)) {
            return max(0, min(100, (int) $content->aeo_score));
        }

        return null;
    }

    /**
     * @return array<int,array{provider:string,score:int|null,tone:string}>
     */
    private function mockProviders(?int $score): array
    {
        return collect(['ChatGPT', 'Perplexity', 'Gemini', 'Claude'])
            ->map(function (string $provider, int $index) use ($score): array {
                $providerScore = $score === null ? null : max(0, min(100, $score - ($index * 4)));

                return [
                    'provider' => $provider,
                    'score' => $providerScore,
                    'tone' => $this->scoreTone($providerScore),
                ];
            })
            ->all();
    }

    private function scoreTone(?int $score): string
    {
        return match (true) {
            ! is_int($score) => 'slate',
            $score >= 70 => 'green',
            $score >= 40 => 'amber',
            default => 'red',
        };
    }
}
