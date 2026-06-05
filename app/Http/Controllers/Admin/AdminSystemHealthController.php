<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\QueueAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminSystemHealthController extends Controller
{
    public function __construct(private readonly QueueAdminService $queues) {}

    public function index(): View
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $queueConfigured = (bool) config('queue.connections.' . $queueConnection);
        $webhookQueue = trim((string) config('publishlayer.webhooks.queue', 'default'));
        if ($webhookQueue === '') {
            $webhookQueue = 'default';
        }

        $dbConnection = (string) config('database.default', '');
        $dbStatus = 'ok';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $exception) {
            $dbStatus = 'error';
        }

        $storageDisk = (string) config('filesystems.default', 'local');
        $storageConfig = config('filesystems.disks.' . $storageDisk);
        $storageStatus = is_array($storageConfig) ? 'configured' : 'missing';

        if (is_array($storageConfig) && ($storageConfig['driver'] ?? '') === 'local') {
            $rootPath = (string) ($storageConfig['root'] ?? '');
            $storageStatus = $rootPath !== '' && is_dir($rootPath) ? 'ok' : 'missing_path';
        }

        $failedJobsCount = null;
        $failedJobsTotalCount = null;
        if (Schema::hasTable('failed_jobs')) {
            $failedJobsBaseQuery = DB::table('failed_jobs')->where('queue', $webhookQueue);
            $failedJobsCount = (int) (clone $failedJobsBaseQuery)
                ->where('failed_at', '>=', now()->subDay())
                ->count();
            $failedJobsTotalCount = (int) (clone $failedJobsBaseQuery)->count();
        }

        return view('admin.system-health.index', [
            'checks' => [
                'app_environment' => app()->environment(),
                'queue_connection' => $queueConnection,
                'queue_configured' => $queueConfigured,
                'cache_driver' => (string) config('cache.default', 'file'),
                'db_connection' => $dbConnection,
                'db_status' => $dbStatus,
                'storage_disk' => $storageDisk,
                'storage_status' => $storageStatus,
                'storage_driver' => is_array($storageConfig) ? (string) ($storageConfig['driver'] ?? 'unknown') : 'unknown',
                'failed_jobs_count' => $failedJobsCount,
                'failed_jobs_total_count' => $failedJobsTotalCount,
                'webhook_queue' => $webhookQueue,
            ],
            'queue_summary' => $this->queues->getQueueStats(),
        ]);
    }
}
