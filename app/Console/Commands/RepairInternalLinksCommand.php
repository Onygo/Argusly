<?php

namespace App\Console\Commands;

use App\Services\Content\InternalLinkRepairService;
use Illuminate\Console\Command;

class RepairInternalLinksCommand extends Command
{
    protected $signature = 'content:repair-internal-links
        {--dry-run : Preview repairs without persisting changes}
        {--content= : Restrict the repair to a single content id}
        {--family= : Restrict the repair to a localization family/root id}
        {--site= : Restrict the repair to a client site id}
        {--workspace= : Restrict the repair to a workspace id}
        {--locale= : Restrict source contents to a locale, for example nl}
        {--rerun-linking : Re-run the existing internal linking flow after repair when a safe synced draft is available}
        {--remove-unresolved : Remove unresolved publication links and keep the anchor text only}
        {--allow-cross-locale : Allow a cross-locale fallback when no canonical target exists in the source locale}';

    protected $description = 'Repair outdated internal publication links in content bodies using current canonical content/publication state.';

    public function handle(InternalLinkRepairService $repairService): int
    {
        $result = $repairService->repair(
            filters: [
                'content' => $this->option('content'),
                'family' => $this->option('family'),
                'site' => $this->option('site'),
                'workspace' => $this->option('workspace'),
                'locale' => $this->option('locale'),
            ],
            dryRun: (bool) $this->option('dry-run'),
            removeUnresolved: (bool) $this->option('remove-unresolved'),
            rerunLinking: (bool) $this->option('rerun-linking'),
            allowCrossLocaleFallback: (bool) $this->option('allow-cross-locale'),
        );

        $summary = $result['summary'] ?? [];

        $this->info(sprintf(
            'Scanned %d content item(s), inspected %d link(s), changed %d content item(s).',
            (int) ($summary['contents_scanned'] ?? 0),
            (int) ($summary['links_inspected'] ?? 0),
            (int) ($summary['contents_changed'] ?? 0),
        ));

        $this->line(sprintf(
            'Actions: replaced=%d removed=%d unchanged=%d skipped_contents=%d',
            (int) ($summary['replaced'] ?? 0),
            (int) ($summary['removed'] ?? 0),
            (int) ($summary['unchanged'] ?? 0),
            (int) ($summary['contents_skipped'] ?? 0),
        ));

        if ((bool) ($result['options']['rerun_linking'] ?? false)) {
            $this->line(sprintf(
                'Internal linking rerun: requested=%d executed=%d',
                (int) ($summary['rerun_requested'] ?? 0),
                (int) ($summary['rerun_executed'] ?? 0),
            ));
        }

        $reasonRows = collect($result['reasons'] ?? [])
            ->map(fn (int $count, string $reason): array => [
                'reason' => $reason,
                'count' => $count,
            ])
            ->values()
            ->all();

        if ($reasonRows !== []) {
            $this->table(['reason', 'count'], $reasonRows);
        }

        $contentRows = collect($result['contents'] ?? [])
            ->map(fn (array $row): array => [
                'content_id' => (string) ($row['content_id'] ?? ''),
                'locale' => strtoupper((string) ($row['locale'] ?? '')),
                'body_source' => (string) ($row['body_source'] ?? ''),
                'inspected' => (int) ($row['links_inspected'] ?? 0),
                'replaced' => (int) ($row['replaced'] ?? 0),
                'removed' => (int) ($row['removed'] ?? 0),
                'unchanged' => (int) ($row['unchanged'] ?? 0),
                'changed' => (bool) ($row['changed'] ?? false) ? 'yes' : 'no',
                'rerun' => (bool) data_get($row, 'rerun.executed', false) ? 'yes' : ((bool) data_get($row, 'rerun.requested', false) ? 'skipped' : 'no'),
            ])
            ->all();

        if ($contentRows !== []) {
            $this->table(['content_id', 'locale', 'body_source', 'inspected', 'replaced', 'removed', 'unchanged', 'changed', 'rerun'], $contentRows);
        }

        $skippedRows = collect($result['skipped_contents'] ?? [])
            ->map(fn (array $row): array => [
                'content_id' => (string) ($row['content_id'] ?? ''),
                'locale' => strtoupper((string) ($row['locale'] ?? '')),
                'body_source' => (string) ($row['body_source'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
            ])
            ->all();

        if ($skippedRows !== []) {
            $this->table(['content_id', 'locale', 'body_source', 'reason'], $skippedRows);
        }

        $reportRows = collect($result['report_rows'] ?? [])
            ->map(fn (array $row): array => [
                'source_content_id' => (string) ($row['source_content_id'] ?? ''),
                'source_locale' => strtoupper((string) ($row['source_locale'] ?? '')),
                'action' => (string) ($row['action'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'found_url' => (string) ($row['found_url'] ?? ''),
                'replacement_url' => (string) ($row['replacement_url'] ?? ''),
            ])
            ->all();

        if ($reportRows !== []) {
            $this->table(['source_content_id', 'source_locale', 'action', 'reason', 'found_url', 'replacement_url'], $reportRows);
        }

        if ((bool) ($result['dry_run'] ?? false)) {
            $this->warn('Dry run only. No changes were persisted.');

            return self::SUCCESS;
        }

        $this->info('Internal link repair completed.');

        return self::SUCCESS;
    }
}
