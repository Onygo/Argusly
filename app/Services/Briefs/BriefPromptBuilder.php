<?php

namespace App\Services\Briefs;

use App\Models\Brief;

class BriefPromptBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function buildDraftMeta(Brief $brief): array
    {
        $secondaryKeywords = $this->normalizeList($brief->secondary_keywords);
        $keyPoints = $this->normalizeList($brief->key_points);
        $preferredLength = $this->resolvePreferredLength(
            (int) ($brief->desired_length_min ?? 0),
            (int) ($brief->desired_length_max ?? 0)
        );

        $strategyContext = $this->buildPrompt($brief);

        return [
            'language' => $brief->language ?: 'nl',
            'primary_keyword' => (string) ($brief->primary_keyword ?? ''),
            'secondary_keywords' => $secondaryKeywords,
            'tone' => (string) ($brief->tone_of_voice ?: ''),
            'audience' => (string) ($brief->target_audience ?: $brief->audience ?: ''),
            'preferred_length' => $preferredLength,
            'notes' => trim((string) $brief->notes . "\n\n" . $strategyContext),
            'content_type' => (string) ($brief->content_type ?: 'blog'),
            'funnel_stage' => (string) ($brief->funnel_stage ?: ''),
            'search_intent' => (string) ($brief->search_intent ?: ''),
            'unique_angle' => (string) ($brief->unique_angle ?: ''),
            'key_points' => $keyPoints,
            'call_to_action' => (string) ($brief->call_to_action ?: ''),
        ];
    }

    public function buildPrompt(Brief $brief): string
    {
        $lines = [
            'BRIEF CONTEXT',
            'Title: ' . (string) ($brief->title ?? ''),
            'Content type: ' . (string) ($brief->content_type ?: 'blog'),
            'Language: ' . (string) ($brief->language ?: 'nl'),
        ];

        if (trim((string) $brief->target_audience) !== '') {
            $lines[] = 'Target audience: ' . trim((string) $brief->target_audience);
        }

        if (trim((string) $brief->funnel_stage) !== '') {
            $lines[] = 'Funnel stage: ' . trim((string) $brief->funnel_stage);
        }

        if (trim((string) $brief->search_intent) !== '') {
            $lines[] = 'Search intent: ' . trim((string) $brief->search_intent);
        }

        if (trim((string) $brief->tone_of_voice) !== '') {
            $lines[] = 'Tone of voice: ' . trim((string) $brief->tone_of_voice);
        }

        if (trim((string) $brief->unique_angle) !== '') {
            $lines[] = 'Unique angle: ' . trim((string) $brief->unique_angle);
        }

        $secondary = $this->normalizeList($brief->secondary_keywords);
        if ($secondary !== []) {
            $lines[] = 'Secondary keywords: ' . implode(', ', $secondary);
        }

        $keyPoints = $this->normalizeList($brief->key_points);
        if ($keyPoints !== []) {
            $lines[] = 'Key points:';
            foreach ($keyPoints as $point) {
                $lines[] = '- ' . $point;
            }
        }

        if (trim((string) $brief->call_to_action) !== '') {
            $lines[] = 'Call to action: ' . trim((string) $brief->call_to_action);
        }

        return implode("\n", $lines);
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value)));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $string) ?: [])));
    }

    private function resolvePreferredLength(int $min, int $max): string
    {
        if ($min >= 2000 || $max >= 2200) {
            return 'pillar';
        }

        if ($min >= 1300 || $max >= 1400) {
            return 'long';
        }

        if ($max > 0 && $max <= 850) {
            return 'short';
        }

        return 'medium';
    }
}
