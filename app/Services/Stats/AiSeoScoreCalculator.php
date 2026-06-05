<?php

namespace App\Services\Stats;

use App\Models\StatsMetricSetting;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiSeoScoreCalculator
{
    public const NORMALIZATION_CACHE_KEY = 'stats.ai_visibility.normalization.v1';
    public const NORMALIZATION_SETTING_KEY = 'ai_visibility_normalization';

    public function __construct(
        private readonly AiSeoScoreComposer $composer
    ) {
    }

    /**
     * @return array{
     *     processed:int,
     *     min:float,
     *     max:float,
     *     avg:float,
     *     p05:float,
     *     p95:float,
     *     formula_version:string,
     *     weights:array<string,float>
     * }
     */
    public function recalculate(?string $analyticsSiteId = null): array
    {
        $siteFilter = $this->normalizeSiteFilter($analyticsSiteId);
        $baseWeights = $this->composer->baseWeights();
        $formulaVersion = $this->composer->formulaVersion();

        if (! $this->tablesAvailable()) {
            return $this->emptySummary($formulaVersion, $baseWeights);
        }

        $rows = $this->buildSourceRows($siteFilter);
        if ($rows === []) {
            return $this->emptySummary($formulaVersion, $baseWeights);
        }

        $visibilityScores = array_map(
            static fn (array $row): ?float => ($row['has_ai_visibility'] ?? false)
                ? (float) ($row['ai_visibility_score'] ?? 0.0)
                : null,
            $rows,
        );
        $visibilityScores = array_values(array_filter($visibilityScores, static fn ($value): bool => is_numeric($value)));

        [$p05, $p95] = $this->computeNormalizationBounds($visibilityScores);
        $this->storeNormalizationBounds($p05, $p95, $siteFilter);

        $now = now();
        $upserts = [];
        $scoreValues = [];

        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $contentRoi = ($row['has_content_roi'] ?? false)
                ? max(0.0, min(100.0, (float) ($row['content_roi_score'] ?? 0.0)))
                : null;

            $aiVisibility = ($row['has_ai_visibility'] ?? false)
                ? (float) ($row['ai_visibility_score'] ?? 0.0)
                : null;

            $normalizedVisibility = $aiVisibility !== null
                ? $this->normalizeVisibilityScore($aiVisibility, $p05, $p95)
                : null;

            $composed = $this->composer->compose($contentRoi, $normalizedVisibility);
            $aiSeoScore = max(0.0, min(100.0, (float) ($composed['score'] ?? 0.0)));
            $scoreValues[] = $aiSeoScore;

            $upserts[] = [
                'url' => $url,
                'url_hash' => $this->rowHash($row, $url),
                'analytics_site_id' => $row['analytics_site_id'],
                'url_key' => $row['url_key'],
                'content_roi_score' => round((float) ($contentRoi ?? 0.0), 2),
                'ai_visibility_score' => round((float) ($aiVisibility ?? 0.0), 2),
                'ai_visibility_score_normalized' => round((float) ($normalizedVisibility ?? 0.0), 2),
                'ai_seo_score' => $aiSeoScore,
                'weights_json' => json_encode(
                    (array) ($composed['applied_weights'] ?? []),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'formula_version' => $formulaVersion,
                'inputs_json' => json_encode([
                    'base_weights' => $baseWeights,
                    'applied_weights' => $composed['applied_weights'] ?? [],
                    'normalized_inputs' => $composed['normalized_inputs'] ?? [],
                    'missing_inputs' => $composed['missing_inputs'] ?? [],
                    'source' => [
                        'analytics_site_id' => $row['analytics_site_id'],
                        'url_key' => $row['url_key'],
                        'content_metrics_updated_at' => $this->toIsoString($row['content_metrics_updated_at'] ?? null),
                        'ai_visibility_updated_at' => $this->toIsoString($row['ai_visibility_updated_at'] ?? null),
                    ],
                    'normalization_bounds' => [
                        'p05' => $p05,
                        'p95' => $p95,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'calculated_at' => $now,
                'content_metrics_updated_at' => $row['content_metrics_updated_at'],
                'ai_visibility_updated_at' => $row['ai_visibility_updated_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($upserts !== []) {
            DB::table('content_ai_seo_scores')->upsert(
                $upserts,
                ['url_hash'],
                [
                    'url',
                    'analytics_site_id',
                    'url_key',
                    'content_roi_score',
                    'ai_visibility_score',
                    'ai_visibility_score_normalized',
                    'ai_seo_score',
                    'weights_json',
                    'formula_version',
                    'inputs_json',
                    'calculated_at',
                    'content_metrics_updated_at',
                    'ai_visibility_updated_at',
                    'updated_at',
                ]
            );
        }

        $count = count($scoreValues);
        $sum = array_sum($scoreValues);

        return [
            'processed' => $count,
            'min' => $count > 0 ? (float) min($scoreValues) : 0.0,
            'max' => $count > 0 ? (float) max($scoreValues) : 0.0,
            'avg' => $count > 0 ? round($sum / $count, 2) : 0.0,
            'p05' => $p05,
            'p95' => $p95,
            'formula_version' => $formulaVersion,
            'weights' => $baseWeights,
        ];
    }

    public function normalizeVisibilityScore(float $score, float $p05, float $p95): float
    {
        if ($p95 <= $p05) {
            return round(max(0.0, min(100.0, $score)), 2);
        }

        if ($score <= $p05) {
            return 0.0;
        }

        if ($score >= $p95) {
            return 100.0;
        }

        return round((($score - $p05) / ($p95 - $p05)) * 100, 2);
    }

    /**
     * @param  array<int,float>  $scores
     * @return array{0:float,1:float}
     */
    public function computeNormalizationBounds(array $scores): array
    {
        $values = array_values(array_filter($scores, static fn ($value) => is_numeric($value)));
        if ($values === []) {
            return [0.0, 0.0];
        }

        sort($values);

        $p05 = $this->percentile($values, 0.05);
        $p95 = $this->percentile($values, 0.95);

        return [round($p05, 4), round($p95, 4)];
    }

    /**
     * @param  array<int,float>  $sortedValues
     */
    private function percentile(array $sortedValues, float $fraction): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return (float) $sortedValues[0];
        }

        $fraction = max(0.0, min(1.0, $fraction));
        $position = ($count - 1) * $fraction;
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return (float) $sortedValues[$lowerIndex];
        }

        $weight = $position - $lowerIndex;
        $lowerValue = (float) $sortedValues[$lowerIndex];
        $upperValue = (float) $sortedValues[$upperIndex];

        return $lowerValue + (($upperValue - $lowerValue) * $weight);
    }

    /**
     * @return array<int,array{
     *     analytics_site_id:string,
     *     url:string,
     *     url_key:string,
     *     content_roi_score:float,
     *     ai_visibility_score:float,
     *     has_content_roi:bool,
     *     has_ai_visibility:bool,
     *     content_metrics_updated_at:mixed,
     *     ai_visibility_updated_at:mixed
     * }>
     */
    private function buildSourceRows(?string $analyticsSiteId = null): array
    {
        $roiRows = DB::table('content_metrics')
            ->select(['id', 'analytics_site_id', 'url', 'url_key', 'roi_score', 'updated_at'])
            ->when($analyticsSiteId !== null, fn ($query) => $query->where('analytics_site_id', $analyticsSiteId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $visibilityRows = DB::table('content_ai_visibility')
            ->select(['id', 'analytics_site_id', 'url', 'url_key', 'ai_visibility_score', 'updated_at'])
            ->when($analyticsSiteId !== null, fn ($query) => $query->where('analytics_site_id', $analyticsSiteId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $rowsByUrl = [];
        $roiApplied = [];
        $visibilityApplied = [];

        foreach ($roiRows as $row) {
            $siteId = trim((string) ($row->analytics_site_id ?? ''));
            $urlKey = $this->resolveUrlKey((string) ($row->url_key ?? ''), (string) ($row->url ?? ''));
            if ($siteId === '' || $urlKey === '') {
                continue;
            }

            $bucket = $siteId . '|' . $urlKey;

            if (! array_key_exists($bucket, $rowsByUrl)) {
                $rowsByUrl[$bucket] = [
                    'analytics_site_id' => $siteId,
                    'url_key' => $urlKey,
                    'url' => $this->resolveUrl((string) ($row->url ?? ''), $urlKey),
                    'content_roi_score' => 0.0,
                    'ai_visibility_score' => 0.0,
                    'has_content_roi' => false,
                    'has_ai_visibility' => false,
                    'content_metrics_updated_at' => null,
                    'ai_visibility_updated_at' => null,
                ];
            }

            if (! isset($roiApplied[$bucket])) {
                $rowsByUrl[$bucket]['content_roi_score'] = (float) ($row->roi_score ?? 0.0);
                $rowsByUrl[$bucket]['has_content_roi'] = true;
                $rowsByUrl[$bucket]['content_metrics_updated_at'] = $row->updated_at;
                $roiApplied[$bucket] = true;
            }
        }

        foreach ($visibilityRows as $row) {
            $siteId = trim((string) ($row->analytics_site_id ?? ''));
            $urlKey = $this->resolveUrlKey((string) ($row->url_key ?? ''), (string) ($row->url ?? ''));
            if ($siteId === '' || $urlKey === '') {
                continue;
            }

            $bucket = $siteId . '|' . $urlKey;

            if (! array_key_exists($bucket, $rowsByUrl)) {
                $rowsByUrl[$bucket] = [
                    'analytics_site_id' => $siteId,
                    'url_key' => $urlKey,
                    'url' => $this->resolveUrl((string) ($row->url ?? ''), $urlKey),
                    'content_roi_score' => 0.0,
                    'ai_visibility_score' => 0.0,
                    'has_content_roi' => false,
                    'has_ai_visibility' => false,
                    'content_metrics_updated_at' => null,
                    'ai_visibility_updated_at' => null,
                ];
            }

            if (! isset($visibilityApplied[$bucket])) {
                $rowsByUrl[$bucket]['ai_visibility_score'] = (float) ($row->ai_visibility_score ?? 0.0);
                $rowsByUrl[$bucket]['has_ai_visibility'] = true;
                $rowsByUrl[$bucket]['ai_visibility_updated_at'] = $row->updated_at;
                $visibilityApplied[$bucket] = true;
            }
        }

        ksort($rowsByUrl);

        return array_values($rowsByUrl);
    }

    private function storeNormalizationBounds(float $p05, float $p95, ?string $analyticsSiteId = null): void
    {
        $payload = [
            'p05' => $p05,
            'p95' => $p95,
        ];

        StatsMetricSetting::query()->updateOrCreate(
            ['metric_key' => $this->normalizationSettingKey($analyticsSiteId)],
            [
                'settings_json' => $payload,
                'calculated_at' => now(),
            ]
        );

        Cache::forever($this->normalizationCacheKey($analyticsSiteId), $payload);
    }

    private function resolveUrlKey(string $rawUrlKey, string $rawUrl): string
    {
        $urlKey = trim($rawUrlKey);
        if ($urlKey !== '') {
            return mb_substr(mb_strtolower($urlKey), 0, 512);
        }

        $normalizedUrl = AnalyticsUrlKey::normalizeUrl($rawUrl);
        if ($normalizedUrl === null) {
            return '';
        }

        return AnalyticsUrlKey::fromUrl($normalizedUrl);
    }

    private function resolveUrl(string $rawUrl, string $urlKey): string
    {
        $normalizedUrl = AnalyticsUrlKey::normalizeUrl($rawUrl);
        if ($normalizedUrl !== null) {
            return $normalizedUrl;
        }

        return 'https://' . ltrim($urlKey, '/');
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function rowHash(array $row, string $url): string
    {
        $siteId = trim((string) ($row['analytics_site_id'] ?? ''));
        $urlKey = trim((string) ($row['url_key'] ?? ''));

        if ($siteId !== '' && $urlKey !== '') {
            return hash('sha256', $siteId . '|' . $urlKey);
        }

        return hash('sha256', $url);
    }

    private function normalizeSiteFilter(?string $analyticsSiteId): ?string
    {
        $siteId = trim((string) $analyticsSiteId);

        return $siteId !== '' ? $siteId : null;
    }

    private function normalizationSettingKey(?string $analyticsSiteId): string
    {
        if ($analyticsSiteId === null) {
            return self::NORMALIZATION_SETTING_KEY;
        }

        return self::NORMALIZATION_SETTING_KEY . ':' . $analyticsSiteId;
    }

    private function normalizationCacheKey(?string $analyticsSiteId): string
    {
        if ($analyticsSiteId === null) {
            return self::NORMALIZATION_CACHE_KEY;
        }

        return self::NORMALIZATION_CACHE_KEY . ':' . $analyticsSiteId;
    }

    private function toIsoString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);

        return $raw !== '' ? $raw : null;
    }

    /**
     * @param  array<string,float>  $weights
     * @return array{
     *     processed:int,
     *     min:float,
     *     max:float,
     *     avg:float,
     *     p05:float,
     *     p95:float,
     *     formula_version:string,
     *     weights:array<string,float>
     * }
     */
    private function emptySummary(string $formulaVersion, array $weights): array
    {
        return [
            'processed' => 0,
            'min' => 0.0,
            'max' => 0.0,
            'avg' => 0.0,
            'p05' => 0.0,
            'p95' => 0.0,
            'formula_version' => $formulaVersion,
            'weights' => $weights,
        ];
    }

    private function tablesAvailable(): bool
    {
        return Schema::hasTable('content_metrics')
            && Schema::hasTable('content_ai_visibility')
            && Schema::hasTable('content_ai_seo_scores')
            && Schema::hasTable('stats_metric_settings');
    }
}
