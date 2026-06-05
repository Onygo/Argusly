<?php

namespace App\Jobs;

use App\Models\ContentSeriesGenerationRunArticle;
use App\Services\Content\SeriesArticleGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateSeriesRunArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 360;
    public bool $failOnTimeout = true;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public string $runArticleId
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('series-run-article:' . $this->runArticleId))
                ->expireAfter(900)
                ->releaseAfter(60),
        ];
    }

    public function handle(SeriesArticleGenerationService $generationService): void
    {
        $runArticle = ContentSeriesGenerationRunArticle::query()
            ->with(['run', 'series'])
            ->findOrFail($this->runArticleId);

        $startedAt = microtime(true);

        try {
            $generationService->generateRunArticle($runArticle, $this->attempts(), $this->tries);

            Log::info('Series article generation job completed.', [
                'series_id' => (string) $runArticle->series_id,
                'run_id' => (string) $runArticle->run_id,
                'run_article_id' => (string) $runArticle->id,
                'article_number' => (int) $runArticle->article_number,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Series article generation job attempt failed.', [
                'series_id' => (string) $runArticle->series_id,
                'run_id' => (string) $runArticle->run_id,
                'run_article_id' => (string) $runArticle->id,
                'article_number' => (int) $runArticle->article_number,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $runArticle = ContentSeriesGenerationRunArticle::query()->find($this->runArticleId);
        if (! $runArticle) {
            return;
        }

        app(SeriesArticleGenerationService::class)->markRunArticleFailed(
            $runArticle,
            mb_substr($exception->getMessage(), 0, 5000),
            true
        );

        Log::error('Series article generation job failed permanently.', [
            'series_id' => (string) $runArticle->series_id,
            'run_id' => (string) $runArticle->run_id,
            'run_article_id' => (string) $runArticle->id,
            'article_number' => (int) $runArticle->article_number,
            'error' => $exception->getMessage(),
        ]);
    }
}
