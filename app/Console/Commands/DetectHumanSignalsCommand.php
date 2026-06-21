<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\HumanSignals\HumanSignalDetectionService;
use Illuminate\Console\Command;

class DetectHumanSignalsCommand extends Command
{
    protected $signature = 'human-signals:detect {--workspace= : Limit detection to one workspace UUID}';

    protected $description = 'Detect Human Signals from AI visibility, FAQ, campaign, content, and signal intelligence data.';

    public function handle(HumanSignalDetectionService $detector): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $query = Workspace::query()->orderBy('created_at');

        if ($workspaceId !== '') {
            $query->whereKey($workspaceId);
        }

        $total = 0;
        $query->chunkById(50, function ($workspaces) use ($detector, &$total): void {
            foreach ($workspaces as $workspace) {
                $signals = $detector->detectForWorkspace($workspace);
                $total += $signals->count();
                $this->line(sprintf('%s: %d signal(s)', $workspace->display_name, $signals->count()));
            }
        });

        $this->info(sprintf('Detected or refreshed %d Human Signal(s).', $total));

        return self::SUCCESS;
    }
}
