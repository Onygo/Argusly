<?php

namespace App\Console\Commands;

use App\Services\Content\ContentFamilyIntegrityRepairService;
use Illuminate\Console\Command;

class RepairContentFamilyIntegrityCommand extends Command
{
    protected $signature = 'content:repair-family-integrity
        {--dry-run : Preview repairs without persisting changes}';

    protected $description = 'Repair duplicate locale rows, mirrored source links, and broken localization families.';

    public function handle(ContentFamilyIntegrityRepairService $repairService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $repairService->repair($dryRun);

        $reports = collect($result['reports'] ?? []);
        if ($reports->isEmpty()) {
            $this->info('No family integrity repairs detected.');

            return self::SUCCESS;
        }

        $rows = $reports->map(function (array $report): array {
            $issues = collect([
                ($report['duplicates'] ?? []) !== [] ? 'duplicate locales' : null,
                ($report['mirrored_links'] ?? []) !== [] ? 'mirrored links' : null,
            ])->filter()->implode(', ');

            return [
                'family_root_id' => (string) ($report['family_root_id'] ?? ''),
                'source_locale' => strtoupper((string) ($report['source_locale'] ?? '')),
                'locales' => implode(', ', array_map('strtoupper', (array) ($report['locales'] ?? []))),
                'issues' => $issues !== '' ? $issues : 'normalized',
                'archived' => count((array) ($report['archived_duplicate_ids'] ?? [])),
                'drafts' => count((array) ($report['draft_updates'] ?? [])),
                'publications' => count((array) ($report['publication_moves'] ?? [])),
            ];
        })->all();

        $this->table(
            ['family_root_id', 'source_locale', 'locales', 'issues', 'archived', 'drafts', 'publications'],
            $rows
        );

        if ($this->output->isVerbose()) {
            foreach ($reports as $report) {
                foreach ((array) ($report['duplicates'] ?? []) as $duplicate) {
                    $this->line(sprintf(
                        'Family %s locale %s keeps %s and archives %s',
                        (string) ($report['family_root_id'] ?? ''),
                        strtoupper((string) ($duplicate['locale'] ?? '')),
                        (string) ($duplicate['canonical_id'] ?? ''),
                        implode(', ', (array) ($duplicate['duplicate_ids'] ?? [])),
                    ));
                }

                foreach ((array) ($report['mirrored_links'] ?? []) as $mirroredLink) {
                    $this->line(sprintf(
                        'Family %s mirrored link: %s',
                        (string) ($report['family_root_id'] ?? ''),
                        (string) $mirroredLink,
                    ));
                }
            }
        }

        if ($dryRun) {
            $this->warn(sprintf(
                'Dry run: %d affected family/families, %d duplicate row(s), %d draft(s), %d publication(s).',
                (int) ($result['affected_families'] ?? 0),
                (int) ($result['archived_duplicates'] ?? 0),
                (int) ($result['reattached_drafts'] ?? 0),
                (int) ($result['reattached_publications'] ?? 0),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Repaired %d family/families. Archived %d duplicate row(s), reattached %d draft(s), moved %d publication(s).',
            (int) ($result['affected_families'] ?? 0),
            (int) ($result['archived_duplicates'] ?? 0),
            (int) ($result['reattached_drafts'] ?? 0),
            (int) ($result['reattached_publications'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
