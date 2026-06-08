<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Integrations\LegacyCredentialImportService;
use Illuminate\Console\Command;

class BackfillWorkspaceApiKeysCommand extends Command
{
    protected $signature = 'argusly:backfill-workspace-api-keys
        {--workspace= : Optional workspace UUID}
        {--dry-run : Preview changes only}';

    protected $description = 'Imports legacy site and organization credentials into api_keys as compatibility metadata records.';

    public function handle(LegacyCredentialImportService $importService): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Workspace::query()->orderBy('created_at');
        if ($workspaceId !== '') {
            $query->where('id', $workspaceId);
        }

        $workspaces = $query->get(['id', 'name', 'organization_id']);
        if ($workspaces->isEmpty()) {
            $this->warn('No workspaces found for import.');

            return self::SUCCESS;
        }

        $totals = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
        ];

        foreach ($workspaces as $workspace) {
            $result = $importService->importWorkspace($workspace, $dryRun);

            $totals['created'] += (int) ($result['created'] ?? 0);
            $totals['updated'] += (int) ($result['updated'] ?? 0);
            $totals['skipped'] += (int) ($result['skipped'] ?? 0);
            $totals['conflicts'] += (int) ($result['conflicts'] ?? 0);

            $this->line(sprintf(
                '%s (%s): created=%d updated=%d skipped=%d conflicts=%d',
                (string) $workspace->name,
                (string) $workspace->id,
                (int) ($result['created'] ?? 0),
                (int) ($result['updated'] ?? 0),
                (int) ($result['skipped'] ?? 0),
                (int) ($result['conflicts'] ?? 0),
            ));
        }

        $this->newLine();
        $this->table(['metric', 'count'], [
            ['workspaces', $workspaces->count()],
            ['created', $totals['created']],
            ['updated', $totals['updated']],
            ['skipped', $totals['skipped']],
            ['conflicts', $totals['conflicts']],
            ['mode', $dryRun ? 'dry-run' : 'apply'],
        ]);

        return $totals['conflicts'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
