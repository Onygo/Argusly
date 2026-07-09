<?php

namespace App\Services\PerformanceIntelligence;

use Carbon\CarbonInterface;

class PerformanceInsightService
{
    /**
     * @param  array<string, PerformanceTrend>  $metricTrends
     * @param  array<int, PerformancePageSummary>  $pages
     * @param  array<int, PerformanceTopicSummary>  $topics
     * @param  array<int, PerformanceChannelSummary>  $channels
     * @param  array<int, PerformanceMarketPackSummary>  $marketPacks
     * @return array<int, PerformanceSignal>
     */
    public function generate(
        array $metricTrends,
        array $pages,
        array $topics,
        array $channels,
        array $marketPacks,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $signals = [];

        foreach ($metricTrends as $trend) {
            foreach ($this->globalSignalTypes($trend) as $type) {
                $signals[] = $this->signalFromTrend(
                    type: $type,
                    subjectType: 'workspace',
                    subjectKey: 'workspace',
                    subjectName: 'Workspace',
                    trend: $trend,
                    periodStart: $from,
                    periodEnd: $to,
                );
            }
        }

        foreach ($channels as $summary) {
            foreach ($summary->trends as $trend) {
                if ($summary->channelKey === 'organic_search' && $trend->direction === 'growth') {
                    $signals[] = $this->signalFromTrend(
                        type: 'organic_growth',
                        subjectType: 'channel',
                        subjectKey: $summary->channelKey,
                        subjectName: $summary->channelName,
                        trend: $trend,
                        periodStart: $from,
                        periodEnd: $to,
                    );
                }

                $signals[] = $this->signalFromTrend(
                    type: 'channel_momentum',
                    subjectType: 'channel',
                    subjectKey: $summary->channelKey,
                    subjectName: $summary->channelName,
                    trend: $trend,
                    periodStart: $from,
                    periodEnd: $to,
                );
            }
        }

        foreach ($topics as $summary) {
            foreach ($summary->trends as $trend) {
                $signals[] = $this->signalFromTrend(
                    type: 'topic_momentum',
                    subjectType: 'topic',
                    subjectKey: $summary->topicKey,
                    subjectName: $summary->topicName,
                    trend: $trend,
                    periodStart: $from,
                    periodEnd: $to,
                );
            }
        }

        foreach ($pages as $summary) {
            foreach ($summary->trends as $trend) {
                $signals[] = $this->signalFromTrend(
                    type: 'content_momentum',
                    subjectType: 'page',
                    subjectKey: $summary->pageId,
                    subjectName: $summary->title ?: $summary->url,
                    trend: $trend,
                    periodStart: $from,
                    periodEnd: $to,
                );
            }
        }

        foreach ($marketPacks as $summary) {
            foreach ($summary->trends as $trend) {
                $signals[] = $this->signalFromTrend(
                    type: 'market_pack_momentum',
                    subjectType: 'market_pack',
                    subjectKey: $summary->marketPackKey,
                    subjectName: $summary->marketPackName,
                    trend: $trend,
                    periodStart: $from,
                    periodEnd: $to,
                );
            }
        }

        foreach (array_merge($metricTrends, ...array_map(fn ($summary): array => $summary->trends, array_merge($pages, $topics, $channels, $marketPacks))) as $trend) {
            if (! $trend instanceof PerformanceTrend || $trend->isInsufficient() || $trend->direction === 'flat') {
                continue;
            }

            $signals[] = $this->signalFromTrend(
                type: $trend->direction === 'decline' ? 'performance_risk' : 'performance_opportunity',
                subjectType: 'metric',
                subjectKey: $trend->metricKey,
                subjectName: $trend->metricKey,
                trend: $trend,
                periodStart: $from,
                periodEnd: $to,
            );
        }

        return collect($signals)
            ->filter(fn (?PerformanceSignal $signal): bool => $signal instanceof PerformanceSignal)
            ->filter(fn (PerformanceSignal $signal): bool => $signal->observationIds !== [])
            ->unique(fn (PerformanceSignal $signal): string => $signal->key)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function globalSignalTypes(PerformanceTrend $trend): array
    {
        if ($trend->isInsufficient() || $trend->direction === 'flat') {
            return [];
        }

        return match ($this->metricCategory($trend->metricKey)) {
            'traffic' => ['traffic_trend'],
            'visibility' => ['visibility_trend'],
            'engagement' => ['engagement_trend'],
            default => [],
        };
    }

    private function signalFromTrend(
        string $type,
        string $subjectType,
        string $subjectKey,
        ?string $subjectName,
        PerformanceTrend $trend,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): ?PerformanceSignal {
        if ($trend->isInsufficient() || $trend->direction === 'flat') {
            return null;
        }

        $key = hash('sha1', implode('|', [
            'performance-intelligence',
            $type,
            $subjectType,
            $subjectKey,
            $trend->metricKey,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
        ]));

        return new PerformanceSignal(
            key: 'performance-intelligence:'.$key,
            type: $type,
            subjectType: $subjectType,
            subjectKey: $subjectKey,
            subjectName: $subjectName,
            metricKey: $trend->metricKey,
            direction: $trend->direction,
            confidence: $trend->confidence,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            sourceMetrics: $trend->sourceMetrics,
            observationIds: $trend->observationIds,
            explanation: $this->explanation($type, $subjectName ?: $subjectKey, $trend),
            metadata: [
                'current_value' => $trend->currentValue,
                'previous_value' => $trend->previousValue,
                'absolute_change' => $trend->absoluteChange,
                'growth_percent' => $trend->growthPercent,
                'metric_category' => $this->metricCategory($trend->metricKey),
            ],
        );
    }

    private function explanation(string $type, string $subject, PerformanceTrend $trend): string
    {
        $verb = $trend->direction === 'growth' ? 'grew' : 'declined';
        $percent = $trend->growthPercent === null ? 'an unknown amount' : number_format(abs($trend->growthPercent), 2).'%';
        $current = $trend->currentValue === null ? 'no current value' : number_format($trend->currentValue, 2);
        $previous = $trend->previousValue === null ? 'no previous value' : number_format($trend->previousValue, 2);
        $count = count($trend->observationIds);

        return strtr('{subject} {metric} {verb} by {percent}, from {previous} to {current}. This {type} signal is based on {count} traced observations.', [
            '{subject}' => $subject,
            '{metric}' => $trend->metricKey,
            '{verb}' => $verb,
            '{percent}' => $percent,
            '{previous}' => $previous,
            '{current}' => $current,
            '{count}' => (string) $count,
            '{type}' => str_replace('_', ' ', $type),
        ]);
    }

    private function metricCategory(string $metricKey): string
    {
        $metric = mb_strtolower($metricKey);

        foreach (['sessions', 'users', 'clicks', 'pageviews', 'views', 'traffic'] as $part) {
            if (str_contains($metric, $part)) {
                return 'traffic';
            }
        }

        foreach (['impressions', 'visibility', 'position', 'rank', 'citation', 'topic_ownership'] as $part) {
            if (str_contains($metric, $part)) {
                return 'visibility';
            }
        }

        foreach (['engagement', 'ctr', 'duration', 'event', 'comment', 'share', 'reaction', 'follower'] as $part) {
            if (str_contains($metric, $part)) {
                return 'engagement';
            }
        }

        return 'performance';
    }
}
