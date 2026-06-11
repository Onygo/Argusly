<?php

namespace App\Console\Commands\SignalIntelligence;

use App\Models\Workspace;
use App\Services\SignalIntelligence\LlmTrackingSignalAdapter;
use Illuminate\Console\Command;

class IngestLlmTrackingCommand extends Command
{
    protected $signature = 'signal-intelligence:ingest-llm-tracking {--workspace= : Optional workspace id}';

    protected $description = 'Convert existing LLM tracking runs into Signal Intelligence mentions and events.';

    public function handle(LlmTrackingSignalAdapter $adapter): int
    {
        if (! (bool) config('features.signal_intelligence', false)) {
            $this->warn('Signal Intelligence is disabled. No data processed.');

            return self::SUCCESS;
        }

        $workspace = $this->workspace();
        $stats = $adapter->ingest($workspace);

        $this->info('LLM tracking ingestion completed.');
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
