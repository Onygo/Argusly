<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentSeries;
use Illuminate\Support\Facades\Log;
use Throwable;

class SeriesTranslationService
{
    public function __construct(
        private readonly ContentTranslationCoordinator $translations,
    ) {}

    /**
     * @param  array<int,int|string>  $articleNumbers
     * @return array{
     *     target_language: SupportedLanguage,
     *     total: int,
     *     queued: int,
     *     skipped: int,
     *     failed: int,
     *     results: array<int,array<string,mixed>>,
     *     errors: array<int,string>
     * }
     */
    public function queueSeries(
        ContentSeries $series,
        string $targetLocale,
        ?string $userId = null,
        array $articleNumbers = [],
    ): array {
        $targetLanguage = SupportedLanguage::fromStringOrDefault($targetLocale);
        $requestedArticleNumbers = collect($articleNumbers)
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        $series->loadMissing([
            'contents.translationRequests',
            'contents.drafts',
            'seriesArticles',
        ]);

        $articleNumberByContentId = $series->seriesArticles
            ->filter(fn ($article): bool => $article->content_id !== null)
            ->mapWithKeys(fn ($article): array => [(string) $article->content_id => (int) $article->article_number]);

        $sourceArticles = $series->contents
            ->filter(function (Content $content) use ($targetLanguage, $articleNumberByContentId, $requestedArticleNumbers): bool {
                $articleNumber = (int) ($articleNumberByContentId->get((string) $content->id, 0));

                if ($requestedArticleNumbers->isNotEmpty() && ! $requestedArticleNumbers->contains($articleNumber)) {
                    return false;
                }

                if ($content->isTranslationVariant() || ! (bool) $content->is_source_locale) {
                    return false;
                }

                return $content->localeCode() !== $targetLanguage->value;
            })
            ->sortBy(fn (Content $content): int => (int) ($articleNumberByContentId->get((string) $content->id, 999999)))
            ->values();

        $summary = [
            'target_language' => $targetLanguage,
            'total' => $sourceArticles->count(),
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
            'results' => [],
            'errors' => [],
        ];

        foreach ($sourceArticles as $content) {
            $articleNumber = (int) ($articleNumberByContentId->get((string) $content->id, 0));

            try {
                $queued = $this->translations->queue($content, $targetLanguage->value, $userId);

                $summary['queued']++;
                $summary['results'][] = [
                    'content_id' => (string) $content->id,
                    'article_number' => $articleNumber,
                    'status' => 'queued',
                    'mode' => (string) ($queued['mode'] ?? 'translate'),
                ];
            } catch (Throwable $exception) {
                $message = $exception->getMessage();
                $alreadyQueued = str_contains(strtolower($message), 'already queued')
                    || str_contains(strtolower($message), 'already processing');

                if ($alreadyQueued) {
                    $summary['skipped']++;
                    $summary['results'][] = [
                        'content_id' => (string) $content->id,
                        'article_number' => $articleNumber,
                        'status' => 'skipped',
                        'message' => $message,
                    ];

                    continue;
                }

                $summary['failed']++;
                $summary['errors'][] = $articleNumber > 0
                    ? "Article {$articleNumber}: {$message}"
                    : $message;
                $summary['results'][] = [
                    'content_id' => (string) $content->id,
                    'article_number' => $articleNumber,
                    'status' => 'failed',
                    'message' => $message,
                ];

                Log::warning('content.series.translation.article_failed', [
                    'series_id' => (string) $series->id,
                    'content_id' => (string) $content->id,
                    'article_number' => $articleNumber,
                    'target_locale' => $targetLanguage->value,
                    'user_id' => $userId,
                    'exception_class' => $exception::class,
                    'exception_message' => $message,
                ]);
            }
        }

        Log::info('content.series.translation.queued', [
            'series_id' => (string) $series->id,
            'target_locale' => $targetLanguage->value,
            'user_id' => $userId,
            'total' => $summary['total'],
            'queued' => $summary['queued'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
        ]);

        return $summary;
    }
}
