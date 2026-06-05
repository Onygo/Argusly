<?php

namespace App\Console\Commands;

use App\Models\ContentTranslation;
use App\Services\Content\TranslationRecoveryService;
use App\Services\Translation\TranslationLockRepairService;
use Illuminate\Console\Command;

class RepairStaleTranslationLocksCommand extends Command
{
    protected $signature = 'translations:repair-stale
        {--dry-run : Report stale translation locks without changing them}
        {--fix : Repair stale translation locks}
        {--force : Repair stale translation locks}
        {--apply : Backward compatible alias for --force}
        {--failed-only : Inspect only failed translation locks}
        {--requeue : Queue a fresh retry after repairing each stale translation}
        {--retry : Backward compatible alias for --requeue}
        {--content= : Restrict to a specific content id}
        {--locale= : Restrict to a specific target locale}
        {--limit=250 : Maximum number of rows to inspect}';

    protected $aliases = ['translation:repair-stale-locks', 'content:repair-translation-locks'];

    protected $description = 'Inspect locale translation locks and release stale queued or processing requests.';

    public function __construct(
        private readonly TranslationLockRepairService $repairService,
        private readonly TranslationRecoveryService $recovery,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) ($this->option('fix') || $this->option('force') || $this->option('apply'));
        $failedOnly = (bool) $this->option('failed-only');
        $retry = (bool) ($this->option('requeue') || $this->option('retry'));
        $contentId = trim((string) $this->option('content'));
        $locale = trim((string) $this->option('locale'));

        $stale = $this->repairService->findStaleTranslations(limit: $limit, includeFailed: true)
            ->when($contentId !== '', fn ($rows) => $rows->filter(fn (array $row): bool => (string) $row['translation']->content_id === $contentId))
            ->when($locale !== '', fn ($rows) => $rows->filter(fn (array $row): bool => (string) $row['translation']->target_locale === $locale))
            ->values();

        if ($failedOnly) {
            $stale = $stale
                ->filter(fn (array $row): bool => (string) $row['translation']->status === ContentTranslation::STATUS_FAILED)
                ->values();
        }

        if ($stale->isEmpty()) {
            $this->info('No stale translation locks detected.');

            return self::SUCCESS;
        }

        $this->table(
            ['Content', 'Locale', 'Status', 'Updated', 'Attempts', 'Reason'],
            $stale->map(fn (array $row): array => [
                (string) $row['translation']->content_id,
                $row['translation']->target_locale,
                $row['translation']->status,
                $row['translation']->updated_at?->toDateTimeString() ?? 'n/a',
                (string) ($row['attempts'] ?? 0),
                $row['reason'],
            ])->all()
        );

        if (! $apply) {
            $this->info(sprintf(
                'Dry run: %d stale translation lock(s) detected. Re-run with --fix to repair them.',
                $stale->count()
            ));

            return self::SUCCESS;
        }

        $fixed = 0;

        foreach ($stale as $row) {
            $fixed += $this->repairService->releaseLock(
                translation: $row['translation'],
                message: $this->repairService->staleMessage(
                    $row['translation'],
                    (string) $row['reason'],
                    (int) ($row['attempts'] ?? 0),
                    $row['linked_failed_jobs']
                ),
                keepFailedStatus: true,
            ) ? 1 : 0;
        }

        $retried = 0;

        if ($retry) {
            foreach ($stale as $row) {
                $translation = $row['translation']->fresh();

                if (! $translation instanceof ContentTranslation || ! $translation->content) {
                    continue;
                }

                $result = $this->recovery->retryExistingTranslation($translation);
                $retried += $result['ok'] ? 1 : 0;
            }
        }

        $this->info(sprintf('Released %d stale translation lock(s).', $fixed));

        if ($retry) {
            $this->info(sprintf('Queued %d translation retry job(s).', $retried));
        }

        return self::SUCCESS;
    }
}
