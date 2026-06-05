<?php

namespace App\Services\Content;

use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use Illuminate\Support\Collection;

class SeriesStructureService
{
    private const LONG_TAIL_MARKERS = [
        ' vs ',
        ' versus ',
        'compare',
        'comparison',
        'checklist',
        'template',
        'templates',
        'example',
        'examples',
        'faq',
        'faqs',
        'step by step',
        'steps',
        'use case',
        'use cases',
        'workflow',
        'workflows',
        'for ',
        'best ',
        'top ',
        'tools',
        'mistakes',
        'questions',
    ];

    private const BROAD_SCOPE_MARKERS = [
        'overview',
        'fundamentals',
        'foundation',
        'foundations',
        'basics',
        'guide',
        'strategy',
        'framework',
        'playbook',
        'introduction',
    ];

    public function __construct(
        private readonly ContentSeriesArticleSyncService $seriesArticleSyncService,
    ) {
    }

    public function applySuggestedPillar(ContentSeries $series): ?ContentSeriesArticle
    {
        $series->loadMissing('seriesArticles');

        if ($series->hasPillarArticle()) {
            return $series->getPillarArticle();
        }

        $articleNumber = $this->suggestPillarArticleNumber($series);
        if ($articleNumber === null) {
            return null;
        }

        return $this->seriesArticleSyncService->setPillar($series, $articleNumber);
    }

    public function suggestPillarArticleNumber(ContentSeries $series): ?int
    {
        $articles = $this->articleCandidates($series);
        if ($articles->isEmpty()) {
            return null;
        }

        $topicTokens = $this->tokenize(
            implode(' ', array_filter([
                (string) $series->main_topic,
                (string) $series->primary_keyword,
            ]))
        );
        $mainTopic = mb_strtolower(trim((string) $series->main_topic));
        $primaryKeyword = mb_strtolower(trim((string) $series->primary_keyword));

        return $articles
            ->map(function (array $article) use ($topicTokens, $mainTopic, $primaryKeyword): array {
                $text = trim(implode(' ', array_filter([
                    (string) ($article['title'] ?? ''),
                    (string) ($article['primary_keyword'] ?? ''),
                ])));

                $score = $this->matchScore($text, $topicTokens);
                $score += $this->scopeScore($text);

                $normalized = mb_strtolower($text);
                if ($mainTopic !== '' && str_contains($normalized, $mainTopic)) {
                    $score += 0.45;
                }

                if ($primaryKeyword !== '' && str_contains($normalized, $primaryKeyword)) {
                    $score += 0.30;
                }

                $score += max(0, 0.08 - (((int) $article['article_number'] - 1) * 0.02));

                return [
                    'article_number' => (int) $article['article_number'],
                    'score' => round($score, 4),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $scoreCompare = $right['score'] <=> $left['score'];
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                return $left['article_number'] <=> $right['article_number'];
            })
            ->first()['article_number'] ?? null;
    }

    /**
     * @return Collection<int,array{article_number:int,title:string,primary_keyword:string}>
     */
    private function articleCandidates(ContentSeries $series): Collection
    {
        $strategyArticles = collect((array) data_get($series->strategy_json, 'articles', []))
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row, int $index): array {
                return [
                    'article_number' => (int) data_get($row, 'article_number', $index + 1),
                    'title' => trim((string) data_get($row, 'title', '')),
                    'primary_keyword' => trim((string) data_get($row, 'primary_keyword', '')),
                ];
            })
            ->filter(fn (array $row): bool => $row['article_number'] > 0)
            ->values();

        if ($strategyArticles->isNotEmpty()) {
            return $strategyArticles;
        }

        $series->loadMissing('seriesArticles');

        return $series->seriesArticles
            ->map(fn (ContentSeriesArticle $article): array => [
                'article_number' => (int) $article->article_number,
                'title' => trim((string) $article->title),
                'primary_keyword' => trim((string) $article->primary_keyword),
            ])
            ->filter(fn (array $row): bool => $row['article_number'] > 0)
            ->values();
    }

    /**
     * @param  array<int,string>  $topicTokens
     */
    private function matchScore(string $text, array $topicTokens): float
    {
        if ($text === '' || $topicTokens === []) {
            return 0.0;
        }

        $articleTokens = $this->tokenize($text);
        if ($articleTokens === []) {
            return 0.0;
        }

        $shared = count(array_intersect($topicTokens, $articleTokens));

        return ($shared / max(count($topicTokens), 1)) * 0.9;
    }

    private function scopeScore(string $text): float
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return 0.0;
        }

        $score = 0.35;
        $wordCount = str_word_count($normalized);

        if ($wordCount <= 5) {
            $score += 0.18;
        } elseif ($wordCount <= 8) {
            $score += 0.08;
        } else {
            $score -= min(0.18, ($wordCount - 8) * 0.02);
        }

        if (str_contains($normalized, '?')) {
            $score -= 0.16;
        }

        foreach (self::LONG_TAIL_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                $score -= 0.12;
            }
        }

        foreach (self::BROAD_SCOPE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                $score += 0.14;
            }
        }

        return $score;
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $value): array
    {
        return collect(preg_split('/[^[:alnum:]]+/u', mb_strtolower($value)) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => $token !== '' && mb_strlen($token) > 2)
            ->reject(fn (string $token): bool => in_array($token, [
                'the',
                'and',
                'for',
                'with',
                'from',
                'into',
                'your',
                'this',
                'that',
            ], true))
            ->unique()
            ->values()
            ->all();
    }
}
