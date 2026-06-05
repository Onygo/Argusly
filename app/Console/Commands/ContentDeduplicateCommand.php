<?php

namespace App\Console\Commands;

use App\Services\Content\ContentDeduplicationService;
use Illuminate\Console\Command;

class ContentDeduplicateCommand extends Command
{
    protected $signature = 'content:deduplicate
        {--dry-run : Inspect duplicates without changing data}
        {--execute : Soft delete duplicate rows}
        {--exact-title : Treat identical title + locale + site rows as duplicates regardless of creation window}
        {--families : Soft delete every locale row in duplicate families}
        {--title= : Restrict to duplicate groups whose title contains this text}
        {--limit=500 : Maximum duplicate groups to inspect}
        {--window=60 : Duplicate time window in minutes for title matches}';

    protected $description = 'Detect duplicate content rows and optionally soft delete non-canonical duplicates.';

    public function handle(ContentDeduplicationService $deduplicationService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $window = max(1, (int) $this->option('window'));
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run') || ! $execute;
        $exactTitle = (bool) $this->option('exact-title');
        $includeFamilies = (bool) $this->option('families');
        $titleFilter = trim((string) $this->option('title'));

        $groups = $deduplicationService->detectDuplicateGroups($limit, $window, $exactTitle)
            ->when($titleFilter !== '', fn ($collection) => $collection
                ->filter(fn (array $group): bool => str_contains(
                    mb_strtolower((string) ($group['title'] ?? '')),
                    mb_strtolower($titleFilter)
                ))
                ->values());

        if ($groups->isEmpty()) {
            $this->info('No duplicate groups found.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($groups as $group) {
            $this->line(sprintf(
                '[%s] keep=%s duplicates=%s title="%s"',
                strtoupper((string) $group['language']),
                (string) $group['canonical_id'],
                implode(', ', $group['duplicate_ids']),
                (string) $group['title'],
            ));

            if ($dryRun) {
                continue;
            }

            $result = $deduplicationService->cleanupDuplicateGroup($group, $includeFamilies);
            $deleted += (int) $result['deleted_count'];
        }

        if ($dryRun) {
            $this->comment('Dry run only. Re-run with --execute to soft delete duplicate rows.');
        } else {
            $this->info("Soft deleted {$deleted} duplicate content row(s).");
        }

        return self::SUCCESS;
    }
}
