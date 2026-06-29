<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MosWriteAgenticExecutionCanonicalMetadataCommand extends Command
{
    protected $signature = 'mos:write-agentic-execution-canonical-metadata';

    protected $description = 'Report guarded Agentic execution canonical metadata writer configuration; historical backfill is unsupported.';

    public function handle(): int
    {
        $enabled = (bool) config('features.mos_agentic_execution_canonical_metadata_writer', false);

        $this->components->info('Agentic execution canonical metadata writer configuration.');
        $this->line('feature flag: '.($enabled ? 'enabled' : 'disabled'));
        $this->components->warn('Historical backfill is intentionally unsupported.');
        $this->line('Metadata is written only inside normal future Agentic execution row creation flows when the feature flag is enabled and the resolver reports a safe canonical bridge.');
        $this->line('No existing actions, action runs, pipelines, assets, briefs, drafts, approvals, feedback, audit logs, routes, lifecycle state, or rollback snapshots were updated.');

        return self::SUCCESS;
    }
}
