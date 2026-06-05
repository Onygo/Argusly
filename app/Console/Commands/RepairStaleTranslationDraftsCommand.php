<?php

namespace App\Console\Commands;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Draft;
use App\Services\Translation\TranslationService;
use Illuminate\Console\Command;

class RepairStaleTranslationDraftsCommand extends Command
{
    protected $signature = 'translation:repair-stale-drafts
        {--source-draft-id= : Inspect a single source draft}
        {--target-locale= : Restrict inspection to one target locale}
        {--apply : Cancel repairable stale translation drafts instead of previewing only}';

    protected $description = 'Inspect duplicate translation candidates and optionally cancel stale translation drafts that do not represent valid locale variants.';

    public function handle(TranslationService $translations): int
    {
        $sourceDraftId = trim((string) $this->option('source-draft-id'));
        $targetLocale = trim((string) $this->option('target-locale'));
        $apply = (bool) $this->option('apply');

        $sourceDrafts = Draft::query()
            ->with('content')
            ->when(
                $sourceDraftId !== '',
                fn ($query) => $query->whereKey($sourceDraftId),
                fn ($query) => $query->whereIn('draft_type', [
                    DraftType::ORIGINAL->value,
                    DraftType::HYBRID->value,
                ])
            )
            ->orderBy('created_at')
            ->get();

        if ($sourceDrafts->isEmpty()) {
            $this->warn('No source drafts matched the requested scope.');

            return self::SUCCESS;
        }

        $targetLanguages = $targetLocale !== ''
            ? [SupportedLanguage::fromStringOrDefault($targetLocale)]
            : SupportedLanguage::cases();

        $rows = [];
        $repairableDraftIds = [];

        foreach ($sourceDrafts as $sourceDraft) {
            $sourceLanguage = $translations->resolveSourceLanguage($sourceDraft);

            foreach ($targetLanguages as $targetLanguage) {
                if ($targetLanguage === $sourceLanguage) {
                    continue;
                }

                $inspection = $translations->inspectTargetLanguageAvailability($sourceDraft, $targetLanguage);

                foreach ($inspection['draft_records'] as $record) {
                    $rows[] = [
                        'source_draft_id' => $inspection['source_draft_id'],
                        'source_content_id' => $inspection['source_content_id'] ?? '',
                        'source_locale' => $inspection['source_locale'],
                        'target_locale' => $inspection['target_locale'],
                        'record_id' => (string) ($record['id'] ?? ''),
                        'model_type' => (string) ($record['model_type'] ?? 'draft'),
                        'locale' => (string) ($record['locale'] ?? ''),
                        'status' => (string) ($record['status'] ?? ''),
                        'is_source' => ($record['is_source'] ?? false) ? 'yes' : 'no',
                        'soft_deleted' => ($record['soft_deleted'] ?? false) ? 'yes' : 'no',
                        'legacy_migration' => ($record['legacy_migration'] ?? false) ? 'yes' : 'no',
                        'route_or_slug' => (string) ($record['route_or_slug'] ?? ''),
                        'blocking' => ($record['blocking'] ?? false) ? 'yes' : 'no',
                        'block_reason' => (string) ($record['block_reason'] ?? ''),
                        'content_id' => (string) ($record['content_id'] ?? ''),
                        'content_locale' => (string) ($record['content_locale'] ?? ''),
                        'content_status' => (string) ($record['content_status'] ?? ''),
                        'repairable' => ($record['repairable'] ?? false) ? 'yes' : 'no',
                    ];

                    if (($record['repairable'] ?? false) === true) {
                        $repairableDraftIds[] = (string) $record['id'];
                    }
                }
            }
        }

        if ($rows === []) {
            $this->info('No translation draft candidates were found for the requested scope.');

            return self::SUCCESS;
        }

        $this->table([
            'source_draft_id',
            'source_content_id',
            'source_locale',
            'target_locale',
            'record_id',
            'model_type',
            'locale',
            'status',
            'is_source',
            'soft_deleted',
            'legacy_migration',
            'route_or_slug',
            'blocking',
            'block_reason',
            'content_id',
            'content_locale',
            'content_status',
            'repairable',
        ], $rows);

        $uniqueRepairableDraftIds = collect($repairableDraftIds)
            ->filter()
            ->unique()
            ->values();

        if ($uniqueRepairableDraftIds->isEmpty()) {
            $this->info('No repairable stale translation drafts were detected.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn(sprintf(
                'Dry run: %d repairable stale translation draft(s) detected. Re-run with --apply to cancel them.',
                $uniqueRepairableDraftIds->count()
            ));

            return self::SUCCESS;
        }

        $updated = 0;

        Draft::query()
            ->whereIn('id', $uniqueRepairableDraftIds->all())
            ->get()
            ->each(function (Draft $draft) use (&$updated): void {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                $cleanupMeta = is_array(data_get($meta, 'translation_cleanup'))
                    ? data_get($meta, 'translation_cleanup')
                    : [];

                $meta['translation_cleanup'] = array_merge($cleanupMeta, [
                    'cleaned_at' => now()->toIso8601String(),
                    'cleaned_by' => self::class,
                    'reason' => 'stale_duplicate_translation_candidate',
                ]);

                $draft->forceFill([
                    'status' => 'cancelled',
                    'last_error' => 'Cancelled by translation:repair-stale-drafts because the linked locale variant is stale or invalid.',
                    'meta' => $meta,
                ])->save();

                $updated++;
            });

        $this->info(sprintf('Cancelled %d stale translation draft(s).', $updated));

        return self::SUCCESS;
    }
}
