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
            'objectives' => $this->normalizeList(data_get($brief->client_refs, 'objectives', [])),
            'key_questions' => $this->normalizeList(data_get($brief->client_refs, 'key_questions', [])),
            'recommended_internal_links' => (array) data_get($brief->client_refs, 'recommended_internal_links', []),
            'recommended_external_references' => (array) data_get($brief->client_refs, 'recommended_external_references', []),
            'expected_entities' => $this->normalizeList(data_get($brief->client_refs, 'entity_coverage', [])),
            'schema_recommendations' => $this->normalizeList(data_get($brief->client_refs, 'schema_recommendations', [])),
            'faq_questions' => $this->normalizeList(data_get($brief->client_refs, 'faq_questions', [])),
            'distribution_suggestions' => $this->normalizeList(data_get($brief->client_refs, 'distribution_suggestions', [])),
            'success_metrics' => $this->normalizeList(data_get($brief->client_refs, 'success_metrics', [])),
            'humanization_notes' => $this->normalizeList(data_get($brief->client_refs, 'humanization_notes', [])),
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

        $this->appendList($lines, 'Editorial objectives', data_get($brief->client_refs, 'objectives', []));
        $this->appendList($lines, 'Key questions', data_get($brief->client_refs, 'key_questions', []));
        $this->appendList($lines, 'Expected entity coverage', data_get($brief->client_refs, 'entity_coverage', []));
        $this->appendList($lines, 'Schema recommendations', data_get($brief->client_refs, 'schema_recommendations', []));
        $this->appendList($lines, 'FAQ suggestions', data_get($brief->client_refs, 'faq_questions', []));
        $this->appendLinkList($lines, 'Recommended internal links', data_get($brief->client_refs, 'recommended_internal_links', []));
        $this->appendReferenceList($lines, 'Recommended external references', data_get($brief->client_refs, 'recommended_external_references', []));
        $this->appendList($lines, 'Distribution suggestions', data_get($brief->client_refs, 'distribution_suggestions', []));
        $this->appendList($lines, 'Success metrics', data_get($brief->client_refs, 'success_metrics', []));
        $this->appendList($lines, 'Humanization notes', data_get($brief->client_refs, 'humanization_notes', []));

        $completeBriefing = trim((string) data_get($brief->client_refs, 'complete_briefing.raw', ''));
        if ($completeBriefing !== '') {
            $lines[] = '';
            $lines[] = 'COMPLETE USER-SUPPLIED BRIEFING';
            $lines[] = \Illuminate\Support\Str::limit($completeBriefing, 30000, '');
            $lines[] = 'Treat the complete briefing as the source of truth when it conflicts with inferred defaults.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int,string> $lines
     */
    private function appendList(array &$lines, string $label, mixed $value): void
    {
        $items = $this->normalizeList($value);
        if ($items === []) {
            return;
        }

        $lines[] = $label . ':';
        foreach ($items as $item) {
            $lines[] = '- ' . $item;
        }
    }

    /**
     * @param array<int,string> $lines
     */
    private function appendLinkList(array &$lines, string $label, mixed $value): void
    {
        $items = collect((array) $value)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $lines[] = $label . ':';
        foreach ($items as $item) {
            $title = trim((string) data_get($item, 'title', ''));
            $anchor = trim((string) data_get($item, 'anchor_text', ''));
            $reason = trim((string) data_get($item, 'reason', ''));
            $lines[] = '- ' . trim($title . ($anchor !== '' ? ' | anchor: ' . $anchor : '') . ($reason !== '' ? ' | reason: ' . $reason : ''));
        }
    }

    /**
     * @param array<int,string> $lines
     */
    private function appendReferenceList(array &$lines, string $label, mixed $value): void
    {
        $items = collect((array) $value)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $lines[] = $label . ':';
        foreach ($items as $item) {
            $name = trim((string) data_get($item, 'name', ''));
            $reason = trim((string) data_get($item, 'reason', ''));
            $lines[] = '- ' . trim($name . ($reason !== '' ? ' | reason: ' . $reason : ''));
        }
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
