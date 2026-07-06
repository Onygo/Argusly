<?php

namespace App\Console\Commands\PageIntelligence;

use App\Jobs\PageIntelligence\ExtractPageContentJob;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PageContentExtractor;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ExtractPageContentCommand extends Command
{
    protected $signature = 'page-intelligence:extract
        {pageSnapshotId : Page snapshot UUID}
        {--sync : Extract immediately instead of dispatching a queued job}';

    protected $description = 'Extract normalized content from a PageSnapshot into PageContentExtraction.';

    public function handle(PageContentExtractor $extractor): int
    {
        $snapshot = PageSnapshot::query()->find((string) $this->argument('pageSnapshotId'));
        if (! $snapshot instanceof PageSnapshot) {
            $this->error('Page snapshot not found.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('sync')) {
            ExtractPageContentJob::dispatch((string) $snapshot->id);

            $this->info('Page content extraction queued.');
            $this->line('Snapshot: '.$snapshot->id);
            $this->line('Monitored page: '.$snapshot->monitored_page_id);

            return self::SUCCESS;
        }

        try {
            $result = $extractor->extract($snapshot);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Page content extraction %s.', $result->state()));
        $this->line('Extraction: '.$result->extraction->id);
        $this->line('Snapshot: '.$result->snapshot->id);
        $this->line('Monitored page: '.$result->page->id);
        $this->line('Title: '.($result->extraction->title ?: 'none'));
        $this->line('Language: '.($result->extraction->language ?: 'none'));
        $this->line('Words: '.($result->extraction->word_count ?? 0));
        $this->line('Quality score: '.($result->extraction->quality_score ?? 'none'));
        $this->line('Canonical conflict: '.($result->snapshot->canonical_conflict ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
