<?php

namespace App\Console\Commands;

use App\Models\Draft;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use Illuminate\Console\Command;

class DeliverDraftsCommand extends Command
{
    protected $signature = 'argusly:deliverDrafts {--limit=25}';
    protected $description = 'Deliver ready drafts to WordPress via webhook';

    public function handle(DeliverDraftToWordPress $delivery): int
    {
        $limit = (int) $this->option('limit');

        $draftIds = Draft::query()
            ->where('status', 'ready_to_deliver')
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $processed = 0;

        foreach ($draftIds as $draftId) {
            $draft = $delivery->markDelivering($draftId);

            if (! $draft) {
                continue;
            }

            $result = $delivery->deliver($draft);

            if ($result['ok'] === true) {
                $delivery->markDelivered($draft, true);
                $this->line('Delivered draft ' . $draft->id . ' status ' . $result['status']);
            } else {
                $error = 'Webhook failed, http ' . ($result['status'] ?? 'n a') . ', ' . ($result['error'] ?? $result['body'] ?? 'unknown');
                $delivery->markFailed($draft, $error);
                $this->line('Failed draft ' . $draft->id . ' ' . $error);
            }

            $processed++;
        }

        $this->info('Done. Processed: ' . $processed);

        return self::SUCCESS;
    }
}
