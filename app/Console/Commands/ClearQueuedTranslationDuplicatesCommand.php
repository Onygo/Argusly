<?php

namespace App\Console\Commands;

use App\Services\Content\TranslationLockService;
use Illuminate\Console\Command;

class ClearQueuedTranslationDuplicatesCommand extends Command
{
    protected $signature = 'content:translation-clear-queued-duplicates
        {contentId? : Restrict to a specific source content id}
        {locale? : Restrict to a specific target locale}
        {--dry-run : Report duplicate queued jobs without deleting them}
        {--force : Delete older duplicate queued jobs}';

    protected $description = 'Inspect queued TranslateDraftJob rows and remove older duplicates for the same source content and target locale.';

    public function __construct(
        private readonly TranslationLockService $locks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $contentId = trim((string) $this->argument('contentId'));
        $locale = trim((string) $this->argument('locale'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run') || ! $force;

        $duplicates = $this->locks->clearQueuedDuplicateJobs(
            $contentId !== '' ? $contentId : null,
            $locale !== '' ? $locale : null,
            ! $dryRun,
        );

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate queued translation jobs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Source content', 'Locale', 'Keep job', 'Delete job ids'],
            $duplicates->map(fn (array $row): array => [
                (string) $row['source_content_id'],
                (string) $row['target_locale'],
                (string) data_get($row, 'keep.id', ''),
                collect($row['delete'] ?? [])->pluck('id')->implode(', '),
            ])->all()
        );

        $this->info($dryRun
            ? sprintf('Dry run: %d duplicate translation job group(s) detected.', $duplicates->count())
            : sprintf('Removed older queued jobs for %d duplicate translation group(s).', $duplicates->count()));

        return self::SUCCESS;
    }
}
