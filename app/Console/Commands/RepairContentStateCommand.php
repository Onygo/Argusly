<?php

namespace App\Console\Commands;

use App\Services\Content\ContentStateRepairService;
use Illuminate\Console\Command;

class RepairContentStateCommand extends Command
{
    protected $signature = 'content:repair-state
        {--dry-run : Preview repairs without persisting changes}
        {--workspace= : Restrict the repair to a workspace id}
        {--site= : Restrict the repair to a client site id}
        {--content= : Restrict the repair to a content id and its immediate localization family}';

    protected $description = 'Repair content families, publication locales, and legacy content shadows from canonical publication state.';

    public function handle(ContentStateRepairService $repairService): int
    {
        $result = $repairService->repair([
            'workspace' => $this->option('workspace'),
            'site' => $this->option('site'),
            'content' => $this->option('content'),
        ], (bool) $this->option('dry-run'));

        $scope = $result['scope'] ?? [];
        $family = $result['family'] ?? [];
        $publicationLocales = $result['publication_locales'] ?? [];
        $publicationStates = $result['publication_states'] ?? [];
        $legacyShadows = $result['legacy_shadows'] ?? [];
        $reports = $result['reports'] ?? [];

        $this->info(sprintf(
            'Scanned %d content row(s) and %d publication row(s).',
            (int) ($scope['contents_scanned'] ?? 0),
            (int) ($scope['publications_scanned'] ?? 0),
        ));

        $this->line(sprintf(
            'Family repair: scanned=%d repaired=%d skipped=%d unresolved=%d',
            (int) ($family['scanned_rows'] ?? 0),
            (int) ($family['repaired_rows'] ?? 0),
            (int) ($family['skipped_rows'] ?? 0),
            (int) ($family['unrepairable_rows'] ?? 0),
        ));

        $this->line(sprintf(
            'Publication locales: scanned=%d mismatches=%d repaired=%d',
            (int) ($publicationLocales['scanned_rows'] ?? 0),
            (int) ($publicationLocales['mismatched_rows'] ?? 0),
            (int) ($publicationLocales['repaired_rows'] ?? 0),
        ));

        $this->line(sprintf(
            'Publication states: scanned=%d repaired=%d',
            (int) ($publicationStates['scanned_rows'] ?? 0),
            (int) ($publicationStates['repaired_rows'] ?? 0),
        ));

        $this->line(sprintf(
            'Legacy shadows: scanned=%d repaired=%d',
            (int) ($legacyShadows['scanned_rows'] ?? 0),
            (int) ($legacyShadows['repaired_rows'] ?? 0),
        ));

        $familyRows = collect($family['repairs'] ?? [])
            ->map(fn (array $repair): array => [
                'content_id' => (string) ($repair['content_id'] ?? ''),
                'locale' => strtoupper((string) ($repair['locale'] ?? '')),
                'source_id' => (string) ($repair['source_id'] ?? ''),
                'family_id' => (string) ($repair['family_id'] ?? ''),
            ])
            ->all();

        if ($familyRows !== []) {
            $this->table(['content_id', 'locale', 'source_id', 'family_id'], $familyRows);
        }

        $publicationLocaleRows = collect($publicationLocales['repairs'] ?? [])
            ->map(fn (array $repair): array => [
                'publication_id' => (string) ($repair['publication_id'] ?? ''),
                'content_id' => (string) ($repair['content_id'] ?? ''),
                'from' => strtoupper((string) ($repair['from'] ?? '')),
                'to' => strtoupper((string) ($repair['to'] ?? '')),
            ])
            ->all();

        if ($publicationLocaleRows !== []) {
            $this->table(['publication_id', 'content_id', 'from', 'to'], $publicationLocaleRows);
        }

        $publicationStateRows = collect($publicationStates['repairs'] ?? [])
            ->map(fn (array $repair): array => [
                'publication_id' => (string) ($repair['publication_id'] ?? ''),
                'content_id' => (string) ($repair['content_id'] ?? ''),
                'from_provider' => (string) ($repair['from_provider'] ?? ''),
                'to_provider' => (string) ($repair['to_provider'] ?? ''),
            ])
            ->all();

        if ($publicationStateRows !== []) {
            $this->table(['publication_id', 'content_id', 'from_provider', 'to_provider'], $publicationStateRows);
        }

        $shadowRows = collect($legacyShadows['repairs'] ?? [])
            ->map(fn (array $repair): array => [
                'content_id' => (string) ($repair['content_id'] ?? ''),
                'publication_id' => (string) ($repair['publication_id'] ?? ''),
                'fields' => implode(', ', array_keys((array) ($repair['changes'] ?? []))),
            ])
            ->all();

        if ($shadowRows !== []) {
            $this->table(['content_id', 'publication_id', 'fields'], $shadowRows);
        }

        $this->line(sprintf(
            'Report: orphaned translations=%d orphaned publications=%d conflicting publications=%d invalid families=%d',
            count((array) ($reports['orphaned_translations'] ?? [])),
            count((array) ($reports['orphaned_publications'] ?? [])),
            count((array) ($reports['conflicting_publications'] ?? [])),
            count((array) ($reports['invalid_families'] ?? [])),
        ));

        if ((bool) ($result['dry_run'] ?? false)) {
            $this->warn('Dry run only. No changes were persisted.');

            return self::SUCCESS;
        }

        $this->info('Content state repair completed.');

        return self::SUCCESS;
    }
}
