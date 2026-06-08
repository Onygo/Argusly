<?php

namespace App\Console\Commands;

use App\Jobs\DeliverDraftJob;
use App\Models\Draft;
use Illuminate\Console\Command;

class DispatchDraftDeliveries extends Command
{
    protected $signature = 'drafts:dispatch-deliveries {--limit=25}';
    protected $description = 'Dispatch WordPress deliveries for drafts that are ready.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = 25;
        }

        // Selecteer drafts die klaar zijn en nog niet delivered
        $draftIds = Draft::query()
            ->where('status', 'ready_to_deliver')
            ->whereIn('delivery_status', ['pending', 'failed', 'missing_remote'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        foreach ($draftIds as $id) {
            DeliverDraftJob::dispatch($id)->onQueue((string) config('argusly.webhooks.queue', 'deliveries'));
        }

        $this->info('Dispatched ' . count($draftIds) . ' deliveries.');

        return self::SUCCESS;
    }
}
