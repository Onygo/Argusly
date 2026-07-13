<?php

namespace App\Services\Content;

use App\Models\ContentSeries;
use App\Services\Content\ContentSeriesArticleSyncService;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;

class SeriesStrategyService
{
    public function __construct(
        private readonly LlmManager $llmManager,
        private readonly ContentSeriesArticleSyncService $seriesArticleSyncService,
        private readonly SeriesStructureService $seriesStructureService,
        private readonly IntentDetectionService $intentDetectionService,
        private readonly SeriesLocaleResolver $seriesLocaleResolver,
    ) {
    }

    /**
     * @return array{angle:string,articles:array<int,array{article_number:int,title:string,primary_keyword:string,secondary_keywords:array<int,string>,internal_links_to:array<int,int>,is_pillar:bool}>}
     */
    public function generateStrategy(ContentSeries $series): array
    {
        $series->loadMissing('site.workspace');

        $articleCount = max(1, min(20, (int) $series->articles_count));
        $supportingKeywords = collect((array) ($series->supporting_keywords ?? []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        $intentKeys = $this->resolveIntentKeys($series, $supportingKeywords);
        $editorialPlanArticles = collect((array) data_get($series->strategy_json, 'articles', []))
            ->filter(fn ($row): bool => is_array($row) && trim((string) data_get($row, 'title', '')) !== '')
            ->values()
            ->all();
        $editorialPlanText = $editorialPlanArticles !== []
            ? json_encode($editorialPlanArticles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $existingMeta = is_array(data_get($series->strategy_json, 'meta')) ? (array) data_get($series->strategy_json, 'meta') : [];
        $sourceUrl = trim((string) ($existingMeta['source_url'] ?? ''));
        $sourceReferences = collect((array) ($existingMeta['source_references'] ?? []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        if ($sourceReferences === [] && $sourceUrl !== '') {
            $sourceReferences = [$sourceUrl];
        }
        $strategicPositioning = trim((string) ($existingMeta['strategic_positioning'] ?? ''));
        $completeBriefing = trim((string) data_get($existingMeta, 'complete_briefing.raw', ''));
        $targetLanguage = $this->seriesLocaleResolver->resolve($series);
        $targetLanguageLabel = $this->seriesLocaleResolver->promptLabel($targetLanguage);

        $prompt = implode("\n", [
            'Create a chained SEO content strategy as strict JSON.',
            'Target language: ' . $targetLanguageLabel . '. Write the strategy angle, article titles, primary keywords, secondary keywords, and editorial angles in this language.',
            'Keep brand names, product names, acronyms, and source titles unchanged.',
            'Main topic: ' . (string) $series->main_topic,
            'Primary keyword: ' . (string) $series->primary_keyword,
            'Supporting keywords: ' . (! empty($supportingKeywords) ? implode(', ', $supportingKeywords) : 'none'),
            ! empty($intentKeys) ? 'Content intent: ' . implode(', ', $intentKeys) . '.' : null,
            ! empty($intentKeys) ? 'Align the chain and each article angle with these intents.' : null,
            'Audience: ' . (string) ($series->audience ?: 'B2B decision makers'),
            'Tone: ' . (string) ($series->tone ?: 'professional'),
            'Funnel stage: ' . (string) ($series->funnel_stage ?: 'consideration'),
            'Generate exactly ' . $articleCount . ' articles.',
            $sourceReferences !== [] ? 'Source references: ' . implode(' | ', $sourceReferences) : null,
            $sourceReferences !== [] ? 'Use these sources as strategic context and market signals only. Do not summarize, copy, or mirror any source; create an original Argusly-led perspective.' : null,
            $strategicPositioning !== '' ? 'Strategic positioning: ' . $strategicPositioning : null,
            $strategicPositioning !== '' ? 'Every article should reflect this positioning with original insights, practical examples, and a distinct point of view.' : null,
            $completeBriefing !== '' ? 'Complete user-supplied briefing: ' . \Illuminate\Support\Str::limit($completeBriefing, 30000, '') : null,
            $completeBriefing !== '' ? 'Treat the complete briefing as the source of truth for message, audience, positioning, topics to cover, and things to avoid.' : null,
            $editorialPlanText !== '' ? 'Editorial article plan supplied by the user: ' . $editorialPlanText : null,
            $editorialPlanText !== '' ? 'Preserve supplied article titles exactly. Use supplied editorial_angle values as article-specific guidance. Fill missing keywords, secondary keywords, and internal links around those titles.' : null,
            'Each article must include:',
            '- title',
            '- primary_keyword',
            '- secondary_keywords (array)',
            '- editorial_angle (string, when useful)',
            '- internal_links_to (array of article numbers that this article should link to)',
            'Return JSON only with this shape:',
            '{"angle":"...","articles":[{"title":"...","primary_keyword":"...","secondary_keywords":["..."],"editorial_angle":"...","internal_links_to":[2,3]}]}',
        ]);

        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', 'You are a senior SEO strategist. Reason deeply and return strict JSON only.'),
                    new LlmMessage('user', $prompt),
                ],
                model: (string) config('llm.providers.openai.reasoning_model', config('llm.providers.openai.default_model')),
                temperature: 0.2,
                maxTokens: 2400,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'intelligence_analysis',
                    'modality' => 'text',
                    'workspaceId' => (string) ($series->site?->workspace_id ?? ''),
                    'siteId' => (string) ($series->site_id ?? ''),
                    'seriesId' => (string) $series->id,
                    'trigger' => 'content_series_strategy',
                    'strategy_mode' => 'reasoning',
                ],
            ),
            '{"angle":"...","articles":[{"title":"...","primary_keyword":"...","secondary_keywords":["..."],"internal_links_to":[2]}]}'
        );

        $normalized = $this->normalizeStrategy(
            payload: is_array($response->json) ? $response->json : [],
            articleCount: $articleCount,
            series: $series,
            supportingKeywords: $supportingKeywords,
            editorialPlanArticles: $editorialPlanArticles
        );

        $series->update([
            'status' => ContentSeries::STATUS_STRATEGY_GENERATED,
            'strategy_json' => array_merge($normalized, [
                'meta' => array_merge($existingMeta, [
                    'generated_at' => now()->toIso8601String(),
                    'provider' => (string) $response->providerName,
                    'model' => (string) $response->modelUsed,
                    'request_id' => (string) $response->requestId,
                    'usage' => $response->usage->toArray(),
                    'intent_keys' => $intentKeys,
                    'language' => $targetLanguage->value,
                ]),
            ]),
        ]);

        $this->seriesArticleSyncService->sync($series->fresh());

        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $supportingKeywords
     * @return array{angle:string,articles:array<int,array{article_number:int,title:string,primary_keyword:string,secondary_keywords:array<int,string>,internal_links_to:array<int,int>,is_pillar:bool}>}
     */
    private function normalizeStrategy(array $payload, int $articleCount, ContentSeries $series, array $supportingKeywords, array $editorialPlanArticles = []): array
    {
        $articles = [];
        $rawArticles = is_array($payload['articles'] ?? null) ? $payload['articles'] : [];
        $rawInternalLinksByNumber = [];

        for ($i = 1; $i <= $articleCount; $i++) {
            $raw = is_array($rawArticles[$i - 1] ?? null) ? $rawArticles[$i - 1] : [];
            $planned = is_array($editorialPlanArticles[$i - 1] ?? null) ? $editorialPlanArticles[$i - 1] : [];

            $primaryKeyword = trim((string) ($raw['primary_keyword'] ?? ''));
            if ($primaryKeyword === '') {
                $primaryKeyword = trim((string) data_get($planned, 'primary_keyword', ''))
                    ?: ($supportingKeywords[$i - 1] ?? ((string) $series->primary_keyword . ' ' . $i));
            }

            $title = trim((string) data_get($planned, 'title', '')) ?: trim((string) ($raw['title'] ?? ''));
            if ($title === '') {
                $title = ucfirst($primaryKeyword);
            }

            $secondaryKeywords = collect((array) ($raw['secondary_keywords'] ?? []))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->take(6)
                ->values()
                ->all();

            if (empty($secondaryKeywords)) {
                $secondaryKeywords = collect($supportingKeywords)
                    ->reject(fn (string $value) => mb_strtolower($value) === mb_strtolower($primaryKeyword))
                    ->take(4)
                    ->values()
                    ->all();
            }

            $rawInternalLinksByNumber[$i] = collect((array) ($raw['internal_links_to'] ?? []))
                ->map(function ($value): int {
                    if (is_numeric($value)) {
                        return (int) $value;
                    }

                    preg_match('/\d+/', (string) $value, $matches);
                    return isset($matches[0]) ? (int) $matches[0] : 0;
                })
                ->filter(fn (int $value) => $value > 0)
                ->filter(fn (int $value) => $value !== $i)
                ->filter(fn (int $value) => $value <= $articleCount)
                ->unique()
                ->values()
                ->all();

            $articles[] = [
                'article_number' => $i,
                'title' => $title,
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'editorial_angle' => trim((string) data_get($planned, 'editorial_angle', ''))
                    ?: trim((string) data_get($raw, 'editorial_angle', '')),
                'is_pillar' => false,
                'internal_links_to' => [],
            ];
        }

        $suggestedPillarArticleNumber = $this->seriesStructureService->suggestPillarArticleNumber(
            new ContentSeries([
                'main_topic' => $series->main_topic,
                'primary_keyword' => $series->primary_keyword,
                'strategy_json' => ['articles' => $articles],
            ])
        ) ?? 1;

        $articles = collect($articles)
            ->map(function (array $article) use ($articleCount, $rawInternalLinksByNumber, $suggestedPillarArticleNumber): array {
                $articleNumber = (int) $article['article_number'];
                $internalLinks = $rawInternalLinksByNumber[$articleNumber] ?? [];

                if ($internalLinks === [] && $articleCount > 1) {
                    $internalLinks = $articleNumber === $suggestedPillarArticleNumber
                        ? collect(range(1, $articleCount))
                            ->reject(fn (int $value): bool => $value === $articleNumber)
                            ->values()
                            ->all()
                        : [$suggestedPillarArticleNumber];
                }

                $article['internal_links_to'] = $internalLinks;

                return $article;
            })
            ->values()
            ->all();

        $angle = trim((string) ($payload['angle'] ?? ''));
        if ($angle === '') {
            $angle = 'A connected cluster that progressively addresses awareness, evaluation, and conversion intent.';
        }

        return [
            'angle' => $angle,
            'articles' => $articles,
        ];
    }

    /**
     * @param  array<int, string>  $supportingKeywords
     * @return array<int, string>
     */
    private function resolveIntentKeys(ContentSeries $series, array $supportingKeywords): array
    {
        $selected = \App\Support\ContentIntentCatalog::normalizeKeys((array) ($series->intent_keys ?? []));
        if ($selected !== []) {
            return $selected;
        }

        return $this->intentDetectionService->detectFromKeywords(
            (string) $series->primary_keyword,
            $supportingKeywords
        );
    }
}
