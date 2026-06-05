<?php

namespace App\Services\ContentChain;

use App\Models\Content;
use App\Models\ContentChainGuidance;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChainedSuggestionGenerator
{
    /**
     * @param array<string,mixed> $signals
     * @return Collection<int,array<string,mixed>>
     */
    public function generate(Content $content, array $signals, ?ContentChainGuidance $guidance = null): Collection
    {
        $sourceScore = (float) ($signals['source_score'] ?? 0.0);
        $minScore = (float) config('content_chain.suggestions.source_min_score', 45);
        $hasManualSteer = $guidance && (
            $guidance->is_source_enabled
            || filled($guidance->explicit_topic)
            || filled($guidance->preferred_angle)
        );

        if ($sourceScore < $minScore && ! $hasManualSteer) {
            return collect();
        }

        $topic = trim((string) ($guidance?->explicit_topic ?: ($signals['topic_keyword'] ?? $content->primary_keyword ?? $content->title)));
        $keyword = trim((string) ($guidance?->target_keyword ?: ($signals['primary_keyword'] ?? $content->primary_keyword ?? '')));
        $audience = trim((string) ($guidance?->target_audience ?: ($signals['target_audience'] ?? '')));
        $intent = trim((string) ($guidance?->target_intent ?: ($signals['target_intent'] ?? '')));
        $angle = trim((string) ($guidance?->preferred_angle ?: ''));
        $goalType = trim((string) ($guidance?->goal_type ?: ''));
        $maxSuggestions = max(1, (int) config('content_chain.suggestions.max_growth_suggestions_per_content', 6));

        $catalog = collect((array) config('content_chain.suggestions.types', []));
        if ($goalType !== '') {
            $catalog = $catalog->sortByDesc(fn (array $item): int => (string) ($item['goal_type'] ?? '') === $goalType ? 1 : 0);
        }

        $usedTitles = [];

        return $catalog
            ->map(function (array $typeConfig, string $typeKey) use ($content, $topic, $keyword, $audience, $intent, $angle, $signals, $goalType): array {
                $effectiveGoalType = (string) ($goalType !== '' ? $goalType : ($typeConfig['goal_type'] ?? 'follow_up'));
                $title = $this->titleForType($typeKey, $typeConfig, $topic, $keyword, $angle);
                $rationale = $this->rationaleForType($content, $typeConfig, $signals, $angle);

                return [
                    'suggestion_kind' => 'growth',
                    'suggestion_type' => $typeKey,
                    'goal_type' => $effectiveGoalType,
                    'title' => $title,
                    'rationale' => $rationale,
                    'meta' => [
                        'topic' => $topic,
                        'target_keyword' => $keyword !== '' ? $keyword : $topic,
                        'target_audience' => $audience,
                        'target_intent' => $intent,
                        'preferred_angle' => $angle,
                        'source_title' => (string) $content->title,
                    ],
                ];
            })
            ->filter(function (array $row) use (&$usedTitles): bool {
                $key = Str::lower(trim((string) ($row['title'] ?? '')));
                if ($key === '' || in_array($key, $usedTitles, true)) {
                    return false;
                }

                $usedTitles[] = $key;

                return true;
            })
            ->take($maxSuggestions)
            ->values();
    }

    /**
     * @param array<string,mixed> $typeConfig
     */
    private function titleForType(string $typeKey, array $typeConfig, string $topic, string $keyword, string $angle): string
    {
        $subject = $keyword !== '' ? $keyword : $topic;
        $prefix = trim((string) ($typeConfig['title_prefix'] ?? ''));

        return match ($typeKey) {
            'comparison' => trim(sprintf('%s %s vs common alternatives', $prefix, $subject)),
            'how_to' => trim(sprintf('%s %s for your team', $prefix, $subject)),
            'use_case' => trim(sprintf('%s %s in practice', $prefix, $subject)),
            'mistakes' => trim(sprintf('%s %s', $prefix, $subject)),
            'support' => trim(sprintf('%s %s for the wider cluster', $prefix, $subject)),
            'alternative' => trim(sprintf('%s %s', $prefix, $subject)),
            default => trim(sprintf('%s %s', $prefix, $subject)),
        } . ($angle !== '' ? ' - ' . $angle : '');
    }

    /**
     * @param array<string,mixed> $typeConfig
     * @param array<string,mixed> $signals
     */
    private function rationaleForType(Content $content, array $typeConfig, array $signals, string $angle): string
    {
        $parts = [
            sprintf('Built from %s as a strong source article.', (string) $content->title),
        ];

        if (is_numeric($signals['quality_score'] ?? null)) {
            $parts[] = sprintf('Quality signal %.1f supports expansion.', (float) $signals['quality_score']);
        }

        if (is_numeric($signals['page_views'] ?? null) && (int) ($signals['page_views'] ?? 0) > 0) {
            $parts[] = sprintf('%d tracked page views indicate audience demand.', (int) $signals['page_views']);
        }

        if (is_numeric($signals['engagement_rate'] ?? null) && (float) ($signals['engagement_rate'] ?? 0) > 0) {
            $parts[] = sprintf('Engagement rate %.1f%% suggests follow-up potential.', (float) $signals['engagement_rate']);
        }

        if ($angle !== '') {
            $parts[] = 'Manual editorial angle: ' . $angle . '.';
        }

        $label = trim((string) ($typeConfig['label'] ?? 'Follow-up'));
        $parts[] = $label . ' format fits the current gap in the cluster.';

        return implode(' ', $parts);
    }
}
