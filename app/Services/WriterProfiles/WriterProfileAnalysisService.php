<?php

namespace App\Services\WriterProfiles;

use App\Models\Content;
use App\Models\WriterProfile;
use App\Models\WriterProfileSource;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WriterProfileAnalysisService
{
    public function __construct(
        private readonly LlmManager $llm,
    ) {}

    /**
     * @param  array<int, array{title?:string,text?:string,content_id?:string,source_url?:string,language?:string}>  $texts
     * @return array<string, mixed>
     */
    public function analyze(WriterProfile $profile, array $texts, bool $persistSources = true): array
    {
        $normalized = $this->normalizeTexts($texts);
        $wordCount = array_sum(array_column($normalized, 'word_count'));

        if ($normalized === []) {
            $result = $this->fallbackAnalysis([], 0);
        } else {
            $response = $this->llm->generateJson(
                new LlmRequest(
                    messages: [
                        new LlmMessage('system', WriterProfilePromptTemplates::analysisSystemPrompt()),
                        new LlmMessage('user', $this->analysisUserPrompt($profile, $normalized)),
                    ],
                    temperature: 0.25,
                    maxTokens: 1600,
                    responseFormat: 'json',
                    metadata: [
                        'feature' => 'writer_profile_analysis',
                        'workspaceId' => (string) $profile->workspace_id,
                        'writerProfileId' => (string) $profile->id,
                    ],
                ),
                'Return a JSON object describing the reusable writer style profile.',
            );

            $result = $this->sanitizeAnalysis($response->json ?: [], count($normalized), $wordCount);
        }

        $profile->forceFill([
            'tone_summary' => $result['tone_summary'],
            'writing_style_summary' => $result['writing_style_summary'],
            'structure_summary' => $result['structure_summary'],
            'vocabulary_notes' => $result['vocabulary_notes'],
            'formatting_preferences' => $result['formatting_preferences'],
            'do_rules' => $result['do_rules'],
            'dont_rules' => $result['dont_rules'],
            'example_patterns' => $result['example_patterns'],
            'confidence_score' => $result['confidence_score'],
            'last_analyzed_at' => now(),
            'metadata' => array_replace_recursive((array) $profile->metadata, [
                'analysis' => [
                    'source_count' => count($normalized),
                    'total_word_count' => $wordCount,
                    'persisted_source_text' => $persistSources && $profile->retain_source_text,
                ],
            ]),
        ])->save();

        $this->storeSources($profile, $normalized, $persistSources && $profile->retain_source_text);

        return $result;
    }

    /**
     * @param  array<int, string>  $contentIds
     */
    public function analyzeFromContent(WriterProfile $profile, array $contentIds): array
    {
        $contents = Content::query()
            ->where('workspace_id', $profile->workspace_id)
            ->whereIn('id', $contentIds)
            ->with('drafts')
            ->get();

        $texts = $contents->map(function (Content $content): array {
            $draft = $content->drafts->sortByDesc('created_at')->first();

            return [
                'title' => (string) $content->title,
                'text' => $this->plainText((string) ($draft?->content_html ?? '')),
                'content_id' => (string) $content->id,
                'source_url' => (string) ($content->published_url ?? ''),
                'language' => (string) ($content->language?->value ?? $content->language ?? ''),
            ];
        })->all();

        return $this->analyze($profile, $texts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $texts
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTexts(array $texts): array
    {
        return collect($texts)
            ->map(function (array $source): array {
                $text = Str::of((string) ($source['text'] ?? ''))->replaceMatches('/\s+/', ' ')->trim()->toString();

                return [
                    'title' => trim((string) ($source['title'] ?? 'Untitled')),
                    'text' => $text,
                    'content_id' => trim((string) ($source['content_id'] ?? '')) ?: null,
                    'source_url' => trim((string) ($source['source_url'] ?? '')) ?: null,
                    'language' => trim((string) ($source['language'] ?? '')) ?: null,
                    'word_count' => str_word_count(strip_tags($text)),
                ];
            })
            ->filter(fn (array $source): bool => $source['word_count'] >= 25)
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $texts
     */
    private function analysisUserPrompt(WriterProfile $profile, array $texts): string
    {
        $samples = collect($texts)->map(function (array $source, int $index): string {
            return "SOURCE ".($index + 1)."\nTitle: ".$source['title']."\nLanguage: ".($source['language'] ?: 'unknown')."\nWord count: ".$source['word_count']."\nText excerpt for analysis only:\n".Str::limit((string) $source['text'], 4500, '');
        })->implode("\n\n---\n\n");

        return trim(implode("\n\n", [
            'Profile name: '.$profile->name,
            'Profile scope: '.$profile->profile_scope,
            'Source type: '.$profile->source_type,
            'Analyze the combined style across these examples. Ignore facts unless they reveal vocabulary style.',
            'Detect tone, sentence rhythm, structure, CTA style, vocabulary and jargon level, formatting preferences, and reusable do/don’t rules.',
            'Set confidence_score between 0 and 1 based on number of sources, total word count, and consistency.',
            $samples,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizeAnalysis(array $payload, int $sourceCount = 0, int $wordCount = 0): array
    {
        $fallback = $this->fallbackAnalysis([], $wordCount);

        return [
            'tone_summary' => $this->cleanString(data_get($payload, 'tone_summary'), $fallback['tone_summary']),
            'writing_style_summary' => $this->cleanString(data_get($payload, 'writing_style_summary'), $fallback['writing_style_summary']),
            'structure_summary' => $this->cleanString(data_get($payload, 'structure_summary'), $fallback['structure_summary']),
            'vocabulary_notes' => $this->cleanString(data_get($payload, 'vocabulary_notes'), $fallback['vocabulary_notes']),
            'formatting_preferences' => $this->cleanString(data_get($payload, 'formatting_preferences'), $fallback['formatting_preferences']),
            'do_rules' => $this->cleanList(Arr::wrap(data_get($payload, 'do_rules', []))),
            'dont_rules' => $this->cleanList(array_merge(
                Arr::wrap(data_get($payload, 'dont_rules', [])),
                ['Do not reuse unique sentences, claims, examples, anecdotes, or recognizable formulations from source material.']
            )),
            'example_patterns' => $this->cleanList(Arr::wrap(data_get($payload, 'example_patterns', []))),
            'confidence_score' => $this->confidence(data_get($payload, 'confidence_score'), $sourceCount, $wordCount),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function cleanList(array $items): array
    {
        return collect($items)
            ->map(fn ($item): string => trim(is_array($item) ? (string) ($item['rule'] ?? $item['text'] ?? '') : (string) $item))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    private function cleanString(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 2000, '') : $fallback;
    }

    private function confidence(mixed $suggested, int $sourceCount, int $wordCount): float
    {
        $base = min(0.9, ($sourceCount * 0.12) + min(0.55, $wordCount / 6000));
        $value = is_numeric($suggested) ? (float) $suggested : $base;

        return round(max(0.05, min(0.98, ($value + $base) / 2)), 3);
    }

    /**
     * @param  array<int, mixed>  $unused
     * @return array<string, mixed>
     */
    private function fallbackAnalysis(array $unused, int $wordCount): array
    {
        unset($unused);

        return [
            'tone_summary' => 'Clear, practical, and editorially restrained.',
            'writing_style_summary' => 'Use concise paragraphs, concrete observations, and a direct explanation before moving to action.',
            'structure_summary' => 'Open with the problem, add an insight, then close with a practical next step.',
            'vocabulary_notes' => 'Prefer plain professional language over heavy jargon.',
            'formatting_preferences' => 'Short paragraphs, scannable headings, and restrained lists.',
            'do_rules' => ['Be concrete and useful.', 'Keep paragraphs short.', 'Connect insight to action.'],
            'dont_rules' => ['Do not use hype.', 'Do not copy source wording.'],
            'example_patterns' => ['Problem -> insight -> action.', 'Direct claim followed by practical consequence.'],
            'confidence_score' => $this->confidence(null, 0, $wordCount),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function storeSources(WriterProfile $profile, array $sources, bool $retainText): void
    {
        foreach ($sources as $source) {
            WriterProfileSource::query()->create([
                'writer_profile_id' => (string) $profile->id,
                'content_id' => $source['content_id'],
                'title' => $source['title'],
                'source_text' => $retainText ? $source['text'] : null,
                'source_url' => $source['source_url'],
                'language' => $source['language'],
                'word_count' => $source['word_count'],
                'analyzed_at' => now(),
                'metadata' => [
                    'source_text_retained' => $retainText,
                    'privacy_note' => $retainText ? null : 'Source text was analyzed transiently and not stored permanently.',
                ],
            ]);
        }
    }

    private function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
