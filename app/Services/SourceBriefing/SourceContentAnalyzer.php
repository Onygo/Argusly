<?php

namespace App\Services\SourceBriefing;

use App\Models\ClientSite;
use App\Models\ContentSource;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SourceContentAnalyzer
{
    public function __construct(
        private readonly LlmManager $llmManager,
        private readonly WorkspaceSourceContextBuilder $contextBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(ContentSource $source, Workspace $workspace, ?ClientSite $site = null): array
    {
        $workspaceContext = $this->contextBuilder->build($workspace, $site);
        $fallback = $this->fallbackAnalysis($source, $workspaceContext);
        $startedAt = microtime(true);
        $estimatedTokens = (int) ceil(mb_strlen((string) $source->extracted_text) / 4);

        try {
            Log::info('source_briefing.ai_request_start', [
                'url' => (string) ($source->final_url ?: $source->source_url),
                'user_id' => $source->created_by_user_id,
                'workspace_id' => (string) $workspace->id,
                'mode' => (string) ($source->generation_output_mode ?: 'brief_only'),
                'content_source_id' => (string) $source->id,
                'estimated_tokens' => $estimatedTokens,
            ]);

            $response = $this->llmManager->generateJson(
                new LlmRequest(
                    messages: [
                        new LlmMessage('system', $this->systemPrompt()),
                        new LlmMessage('user', $this->userPrompt($source, $workspaceContext, $fallback)),
                    ],
                    temperature: 0.2,
                    maxTokens: 2200,
                    metadata: [
                        'feature' => 'source_briefing',
                        'sub_feature' => 'source_analysis',
                    ],
                ),
                null,
                ['feature' => 'source_briefing']
            );

            $json = is_array($response->json) ? $response->json : [];
            Log::info('source_briefing.ai_request_end', [
                'url' => (string) ($source->final_url ?: $source->source_url),
                'user_id' => $source->created_by_user_id,
                'workspace_id' => (string) $workspace->id,
                'mode' => (string) ($source->generation_output_mode ?: 'brief_only'),
                'content_source_id' => (string) $source->id,
                'ai_provider' => $response->providerName,
                'ai_model' => $response->modelUsed,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return array_merge($this->mergeFallback($fallback, $json), [
                '_debug' => [
                    'ai_provider' => $response->providerName,
                    'ai_model' => $response->modelUsed,
                    'generation_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'estimated_source_tokens' => $estimatedTokens,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::info('Source briefing analysis falling back to heuristic mode', [
                'content_source_id' => (string) $source->id,
                'url' => (string) ($source->final_url ?: $source->source_url),
                'user_id' => $source->created_by_user_id,
                'workspace_id' => (string) $workspace->id,
                'mode' => (string) ($source->generation_output_mode ?: 'brief_only'),
                'error' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            return array_merge($fallback, [
                '_debug' => [
                    'ai_provider' => 'heuristic',
                    'ai_model' => null,
                    'generation_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'estimated_source_tokens' => $estimatedTokens,
                    'fallback_reason' => $exception->getMessage(),
                ],
            ]);
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Return strict JSON only.
You are analyzing a public source article to support original brief generation.
This is not a rewrite task. Do not reproduce source wording or propose paraphrasing.
Use the source only for semantic analysis: themes, gaps, entities, structure, audience signals, questions, and opportunity discovery.
Infer strategic opportunities for a brand-aligned original article.
The eventual brief must enable original content, not copied sentences, paragraphs, examples, or section-by-section rewriting.

Return:
{
  "main_topic": "string",
  "primary_keyword": "string",
  "secondary_keywords": ["string"],
  "semantic_entities": ["string"],
  "search_intent": "informational|commercial|transactional|navigational",
  "likely_audience": "string",
  "funnel_stage": "awareness|consideration|decision|retention",
  "source_tone": "string",
  "key_claims": ["string"],
  "questions_answered": ["string"],
  "content_gaps": ["string"],
  "cta_style": "string",
  "suggested_differentiators": ["string"]
}
PROMPT;
    }

    /**
     * @param array<string, mixed> $workspaceContext
     * @param array<string, mixed> $fallback
     */
    private function userPrompt(ContentSource $source, array $workspaceContext, array $fallback): string
    {
        return implode("\n", [
            'Workspace context:',
            json_encode($workspaceContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'Source metadata:',
            json_encode([
                'url' => $source->final_url ?: $source->source_url,
                'domain' => $source->source_domain,
                'title' => $source->source_title,
                'language' => $source->source_language,
                'outline' => $source->extracted_outline_json,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'Source text excerpt:',
            Str::limit((string) $source->extracted_text, 8000, ''),
            '',
            'Heuristic baseline:',
            json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $workspaceContext
     * @return array<string, mixed>
     */
    private function fallbackAnalysis(ContentSource $source, array $workspaceContext): array
    {
        $title = trim((string) $source->source_title);
        $outline = is_array($source->extracted_outline_json) ? $source->extracted_outline_json : [];
        $text = trim((string) $source->extracted_text);
        $summary = trim((string) data_get($source->metadata_json, 'extraction.summary', ''));
        $candidateLines = collect([$title, (string) ($outline['h1'] ?? ''), $summary])->filter();
        $mainTopic = (string) $candidateLines->first();
        $keywords = $this->extractKeywordCandidates($title . "\n" . implode("\n", (array) ($outline['h2'] ?? [])) . "\n" . $text);
        $entities = $this->extractEntities($title . ' ' . Str::limit($text, 2000, ''));
        $audience = trim((string) data_get($workspaceContext, 'company_profile.target_audience', ''));
        if ($audience === '') {
            $audience = trim((string) data_get($workspaceContext, 'personas.0.name', ''));
        }

        return [
            'main_topic' => $mainTopic !== '' ? $mainTopic : 'Original article opportunity',
            'primary_keyword' => (string) ($keywords->first() ?? Str::slug($mainTopic, ' ')),
            'secondary_keywords' => $keywords->slice(1, 6)->values()->all(),
            'semantic_entities' => $entities->take(8)->values()->all(),
            'search_intent' => $this->inferSearchIntent($title, $text),
            'likely_audience' => $audience !== '' ? $audience : 'Prospects researching the topic',
            'funnel_stage' => $this->inferFunnelStage($title, $text),
            'source_tone' => $this->inferTone($text),
            'key_claims' => $this->extractBulletSentences($text, 5),
            'questions_answered' => $this->questionOpportunities($title, $outline, $text)->take(5)->values()->all(),
            'content_gaps' => $this->detectContentGaps($text, $outline),
            'cta_style' => $this->detectCtaStyle($text),
            'suggested_differentiators' => $this->workspaceDifferentiators($workspaceContext)->take(5)->values()->all(),
        ];
    }

    /**
     * @param array<string, mixed> $fallback
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function mergeFallback(array $fallback, array $json): array
    {
        return [
            'main_topic' => trim((string) ($json['main_topic'] ?? $fallback['main_topic'] ?? '')),
            'primary_keyword' => trim((string) ($json['primary_keyword'] ?? $fallback['primary_keyword'] ?? '')),
            'secondary_keywords' => $this->normalizeStringList($json['secondary_keywords'] ?? $fallback['secondary_keywords'] ?? []),
            'semantic_entities' => $this->normalizeStringList($json['semantic_entities'] ?? $fallback['semantic_entities'] ?? []),
            'search_intent' => trim((string) ($json['search_intent'] ?? $fallback['search_intent'] ?? 'informational')),
            'likely_audience' => trim((string) ($json['likely_audience'] ?? $fallback['likely_audience'] ?? '')),
            'funnel_stage' => trim((string) ($json['funnel_stage'] ?? $fallback['funnel_stage'] ?? 'awareness')),
            'source_tone' => trim((string) ($json['source_tone'] ?? $fallback['source_tone'] ?? '')) ?: 'practical',
            'key_claims' => $this->normalizeStringList($json['key_claims'] ?? $fallback['key_claims'] ?? []),
            'questions_answered' => $this->normalizeStringList($json['questions_answered'] ?? $fallback['questions_answered'] ?? []),
            'content_gaps' => $this->normalizeStringList($json['content_gaps'] ?? $fallback['content_gaps'] ?? []),
            'cta_style' => trim((string) ($json['cta_style'] ?? $fallback['cta_style'] ?? 'subtle')),
            'suggested_differentiators' => $this->normalizeStringList($json['suggested_differentiators'] ?? $fallback['suggested_differentiators'] ?? []),
        ];
    }

    private function extractKeywordCandidates(string $text): Collection
    {
        $clean = strtolower((string) preg_replace('/[^a-z0-9\p{L}\s-]+/u', ' ', $text));
        $tokens = collect(preg_split('/\s+/u', $clean) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => mb_strlen($token) > 2 && ! $this->isStopWord($token))
            ->values();

        $phrases = collect();
        foreach (range(0, max(0, $tokens->count() - 2)) as $index) {
            $first = (string) $tokens->get($index, '');
            $second = (string) $tokens->get($index + 1, '');
            if ($first !== '' && $second !== '') {
                $phrases->push(trim($first . ' ' . $second));
            }
        }

        return $phrases
            ->countBy()
            ->sortDesc()
            ->keys()
            ->merge($tokens->countBy()->sortDesc()->keys())
            ->map(fn (string $item): string => Str::title($item))
            ->unique()
            ->values();
    }

    private function extractEntities(string $text): Collection
    {
        preg_match_all('/\b([\p{Lu}][\p{L}\p{N}&.-]+(?:\s+[\p{Lu}][\p{L}\p{N}&.-]+)*)\b/u', $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => mb_strlen($item) > 2 && ! $this->isCommonCapitalizedWord($item))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->values();
    }

    private function inferSearchIntent(string $title, string $text): string
    {
        $haystack = strtolower($title . ' ' . Str::limit($text, 1200, ''));

        return match (true) {
            str_contains($haystack, 'pricing'),
            str_contains($haystack, 'buy'),
            str_contains($haystack, 'demo') => 'transactional',
            str_contains($haystack, 'best'),
            str_contains($haystack, 'vs'),
            str_contains($haystack, 'compare') => 'commercial',
            str_contains($haystack, 'login'),
            str_contains($haystack, 'dashboard') => 'navigational',
            default => 'informational',
        };
    }

    private function inferFunnelStage(string $title, string $text): string
    {
        return match ($this->inferSearchIntent($title, $text)) {
            'transactional' => 'decision',
            'commercial' => 'consideration',
            default => 'awareness',
        };
    }

    private function inferTone(string $text): string
    {
        $averageSentenceLength = max(1, (int) round(str_word_count(Str::limit($text, 1500, '')) / max(1, preg_match_all('/[.!?]+/', $text))));

        return match (true) {
            $averageSentenceLength >= 22 => 'expert and detailed',
            $averageSentenceLength >= 15 => 'practical and explanatory',
            default => 'clear and concise',
        };
    }

    /**
     * @return array<int, string>
     */
    private function extractBulletSentences(string $text, int $limit): array
    {
        return collect(preg_split('/(?<=[.!?])\s+/u', $text) ?: [])
            ->map(fn (string $sentence): string => trim($sentence))
            ->filter(fn (string $sentence): bool => str_word_count($sentence) >= 6)
            ->take($limit)
            ->map(fn (string $sentence): string => Str::limit($sentence, 180, ''))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $outline
     */
    private function questionOpportunities(string $title, array $outline, string $text): Collection
    {
        $questions = collect();

        if (str_starts_with(strtolower($title), 'what ')) {
            $questions->push(Str::finish(trim($title), '?'));
        }

        $questions = $questions->merge(collect((array) ($outline['h2'] ?? []))
            ->filter(fn (string $heading): bool => str_contains($heading, '?'))
            ->map(fn (string $heading): string => trim($heading)));

        if ($questions->isEmpty()) {
            $topic = trim((string) ($outline['h1'] ?? $title));
            if ($topic !== '') {
                $questions->push('What is ' . Str::lower($topic) . '?');
                $questions->push('Why does ' . Str::lower($topic) . ' matter?');
                $questions->push('How should you approach ' . Str::lower($topic) . '?');
            }
        }

        return $questions->unique()->values();
    }

    /**
     * @param array<string, mixed> $outline
     * @return array<int, string>
     */
    private function detectContentGaps(string $text, array $outline): array
    {
        $gaps = collect();
        $haystack = strtolower($text . ' ' . implode(' ', (array) ($outline['h2'] ?? [])));

        if (! str_contains($haystack, 'faq') && ! str_contains($haystack, 'question')) {
            $gaps->push('Add explicit FAQ-style questions for answer-driven search visibility.');
        }
        if (! str_contains($haystack, 'example') && ! str_contains($haystack, 'case')) {
            $gaps->push('Include concrete examples or real-world scenarios.');
        }
        if (! str_contains($haystack, 'mistake') && ! str_contains($haystack, 'avoid')) {
            $gaps->push('Cover common mistakes or anti-patterns to create a differentiated angle.');
        }
        if (! str_contains($haystack, 'tool') && ! str_contains($haystack, 'platform')) {
            $gaps->push('Mention practical tools, systems, or implementation choices.');
        }

        return $gaps->take(4)->values()->all();
    }

    private function detectCtaStyle(string $text): string
    {
        $haystack = strtolower($text);

        return match (true) {
            str_contains($haystack, 'book a demo'),
            str_contains($haystack, 'request a demo') => 'product demo CTA',
            str_contains($haystack, 'contact us'),
            str_contains($haystack, 'get in touch') => 'contact CTA',
            str_contains($haystack, 'download') => 'download CTA',
            default => 'subtle educational CTA',
        };
    }

    /**
     * @param array<string, mixed> $workspaceContext
     */
    private function workspaceDifferentiators(array $workspaceContext): Collection
    {
        return collect([
            data_get($workspaceContext, 'company_profile.value_proposition'),
            ...((array) data_get($workspaceContext, 'company_profile.proof_points', [])),
            ...((array) data_get($workspaceContext, 'company_profile.services', [])),
            ...((array) data_get($workspaceContext, 'brand_voice.preferred_terminology', [])),
        ])->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function isStopWord(string $token): bool
    {
        static $words = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'into', 'about', 'than',
            'wat', 'hoe', 'voor', 'met', 'een', 'van', 'dat', 'zijn', 'naar', 'over', 'niet',
        ];

        return in_array($token, $words, true);
    }

    private function isCommonCapitalizedWord(string $token): bool
    {
        return in_array($token, ['The', 'This', 'That', 'What', 'How', 'Why', 'Het', 'Een', 'Wat', 'Hoe'], true);
    }
}
