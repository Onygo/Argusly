<?php

namespace App\Console\Commands;

use App\Services\Support\SupportSnapshotService;
use Illuminate\Console\Command;

class CleanupSupportSnapshotsCommand extends Command
{
    protected $signature = 'support:cleanup-snapshots {--days=7 : Retain snapshot files for this many days}';

    protected $description = 'Delete expired support snapshot JSON files from storage/app/support.';

    public function handle(SupportSnapshotService $snapshots): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = $snapshots->cleanupSnapshots($days);

        $this->info("Deleted {$deleted} support snapshot file(s).");

        return self::SUCCESS;
    }
}

