<?php

namespace App\Console\Commands\SignalIntelligence;

use App\Models\Workspace;
use App\Services\SignalIntelligence\CompetitorSignalAdapter;
use Illuminate\Console\Command;

class IngestCompetitorsCommand extends Command
{
    protected $signature = 'signal-intelligence:ingest-competitors {--workspace= : Optional workspace id}';

    protected $description = 'Convert existing competitor intelligence into Signal Intelligence mentions and events.';

    public function handle(CompetitorSignalAdapter $adapter): int
    {
        if (! (bool) config('features.signal_intelligence', false)) {
            $this->warn('Signal Intelligence is disabled. No data processed.');

            return self::SUCCESS;
        }

        $workspace = $this->workspace();
        $stats = $adapter->ingest($workspace);

        $this->info('Competitor ingestion completed.');
        $this->table(['metric', 'count'], collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }

    private function workspace(): ?Workspace
    {
        $workspaceId = trim((string) $this->option('workspace'));

        if ($workspaceId === '') {
            return null;
        }

        return Workspace::query()->findOrFail($workspaceId);
    }
}
