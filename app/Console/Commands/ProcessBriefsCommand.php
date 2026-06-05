<?php

namespace App\Console\Commands;

use App\Models\Brief;
use App\Services\BriefProcessing\BriefToDraftService;
use Illuminate\Console\Command;

class ProcessBriefsCommand extends Command
{
    protected $signature = 'publishlayer:processBriefs {--limit=25}';
    protected $description = 'Process queued briefs and create drafts';

    public function handle(BriefToDraftService $service): int
    {
        $limit = (int) $this->option('limit');

        $briefIds = Brief::query()
            ->where('status', 'queued')
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $processed = 0;

        foreach ($briefIds as $briefId) {
            $draft = $service->claimAndCreateDraft($briefId);

            if ($draft) {
                $processed++;
                $this->line('Processed brief ' . $briefId . ' draft ' . $draft->id);
            }
        }

        $this->info('Done. Processed: ' . $processed);

        return self::SUCCESS;
    }
}
