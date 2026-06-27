<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSeriesRunArticleJob;
use App\Models\ContentSeries;
use App\Models\ContentSeriesGenerationRun;
use App\Models\ContentSeriesGenerationRunArticle;
use App\Services\Content\SeriesArticleGenerationService;
use Illuminate\Console\Command;

class RetryFailedSeriesArticlesCommand extends Command
{
    protected $signature = 'content:series:retry-failed
        {series : Content series id}
        {--article=* : Restrict to one or more article numbers}
        {--queue=generation : Queue name for generation jobs}
        {--include-open-errors : Also retry open pending/generating/brief rows that already contain an error message}
        {--dry-run : Preview failed articles without resetting or dispatching jobs}';

    protected $description = 'Reset and re-dispatch failed content series generation articles.';

    public function handle(SeriesArticleGenerationService $generationService): int
    {
        $seriesId = (string) $this->argument('series');
        $queue = trim((string) $this->option('queue')) ?: 'generation';
        $includeOpenErrors = (bool) $this->option('include-open-errors');
        $articleNumbers = collect((array) $this->option('article'))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        $series = ContentSeries::query()->find($seriesId);
        if (! $series) {
            $this->error("Content series not found: {$seriesId}");

            return self::FAILURE;
        }

        $run = ContentSeriesGenerationRun::query()
            ->where('series_id', $seriesId)
            ->where('status', ContentSeriesGenerationRun::STATUS_FAILED)
            ->latest('created_at')
            ->first()
            ?: ContentSeriesGenerationRun::query()
                ->where('series_id', $seriesId)
                ->latest('created_at')
                ->first();

        if (! $run) {
            $this->warn('No generation run found for this series.');

            return self::SUCCESS;
        }

        $query = ContentSeriesGenerationRunArticle::query()
            ->where('run_id', (string) $run->id)
            ->where('series_id', $seriesId)
            ->where(function ($builder) use ($includeOpenErrors): void {
                $builder->where('status', ContentSeriesGenerationRunArticle::STATUS_FAILED);

                if ($includeOpenErrors) {
                    $builder->orWhere(function ($inner): void {
                        $inner->whereIn('status', [
                            ContentSeriesGenerationRunArticle::STATUS_PENDING,
                            ContentSeriesGenerationRunArticle::STATUS_GENERATING,
                            ContentSeriesGenerationRunArticle::STATUS_BRIEF,
                        ])->whereNotNull('error_message')
                            ->where('error_message', '!=', '');
                    });
                }
            })
            ->orderBy('article_number');

        if ($articleNumbers !== []) {
            $query->whereIn('article_number', $articleNumbers);
        }

        $failedArticles = $query->get();

        if ($failedArticles->isEmpty()) {
            $message = $includeOpenErrors
                ? 'No failed or open errored generation articles matched this series/run.'
                : 'No failed generation articles matched this series/run. Use --include-open-errors for pending/generating rows with an error message.';
            $this->warn($message);

            return self::SUCCESS;
        }

        $rows = $failedArticles
            ->map(fn (ContentSeriesGenerationRunArticle $article): array => [
                'article' => (int) $article->article_number,
                'run_article_id' => (string) $article->id,
                'title' => (string) $article->title,
                'error' => mb_substr((string) $article->error_message, 0, 120),
            ])
            ->all();

        $this->table(['article', 'run_article_id', 'title', 'error'], $rows);

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d failed article(s) would be retried.', $failedArticles->count()));

            return self::SUCCESS;
        }

        foreach ($failedArticles as $article) {
            $article->update([
                'status' => ContentSeriesGenerationRunArticle::STATUS_PENDING,
                'error_message' => null,
                'finished_at' => null,
            ]);

            GenerateSeriesRunArticleJob::dispatch((string) $article->id)->onQueue($queue);
        }

        $generationService->syncRunProgress($run->fresh());

        $this->info(sprintf(
            'Retried %d failed article(s) for series %s on queue %s.',
            $failedArticles->count(),
            $seriesId,
            $queue
        ));

        return self::SUCCESS;
    }
}
