<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\LlmRequest;
use App\Models\OutboxMessage;
use App\Models\SignalAlert;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PlatformHealthService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(): array
    {
        return [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $this->queue(),
            'ai_runtime' => $this->aiRuntime(),
            'webhooks' => $this->webhooks(),
            'sources' => $this->sources(),
            'scheduler' => $this->scheduler(),
            'alerts' => $this->alerts(),
            'feature_flags' => $this->featureFlags(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'healthy', 'label' => 'Database', 'detail' => 'Connection is available.'];
        } catch (Throwable $exception) {
            return ['status' => 'critical', 'label' => 'Database', 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        try {
            Cache::put('platform-health-check', now()->toIso8601String(), 5);

            return ['status' => 'healthy', 'label' => 'Cache', 'detail' => 'Cache writes are available.'];
        } catch (Throwable $exception) {
            return ['status' => 'warning', 'label' => 'Cache', 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $status = $failed > 0 ? 'warning' : 'healthy';

        return [
            'status' => $status,
            'label' => 'Queue',
            'detail' => "{$pending} pending jobs, {$failed} failed jobs.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiRuntime(): array
    {
        $failed = Schema::hasTable('llm_requests')
            ? LlmRequest::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count()
            : 0;

        return [
            'status' => $failed > 0 ? 'warning' : 'healthy',
            'label' => 'AI Runtime',
            'detail' => "{$failed} failed AI requests in the last 24 hours.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webhooks(): array
    {
        $failed = Schema::hasTable('webhook_deliveries')
            ? WebhookDelivery::query()->where('status', 'failed')->count()
            : 0;

        return [
            'status' => $failed > 0 ? 'warning' : 'healthy',
            'label' => 'Webhooks',
            'detail' => "{$failed} failed webhook deliveries.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featureFlags(): array
    {
        $enabled = Schema::hasTable('feature_flags')
            ? FeatureFlag::query()->where('enabled', true)->count()
            : 0;
        $failedOutbox = Schema::hasTable('outbox_messages')
            ? OutboxMessage::query()->where('status', 'failed')->count()
            : 0;

        return [
            'status' => $failedOutbox > 0 ? 'warning' : 'healthy',
            'label' => 'Feature Gates',
            'detail' => "{$enabled} enabled flags, {$failedOutbox} failed outbox messages.",
        ];
    }

    private function sources(): array
    {
        $snapshot = app(SourceHealthService::class)->snapshot();
        $critical = $snapshot['critical'];
        $warning = $snapshot['warning'] + $snapshot['stale'];

        return [
            'status' => $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : 'healthy'),
            'label' => 'Sources',
            'detail' => "{$snapshot['healthy']} healthy, {$snapshot['warning']} warning, {$snapshot['critical']} critical sources.",
        ];
    }

    private function scheduler(): array
    {
        $snapshot = app(SchedulerMonitorService::class)->snapshot();

        return [
            'status' => $snapshot['status'],
            'label' => 'Scheduler',
            'detail' => ($snapshot['last_run_at'] ? "Last heartbeat {$snapshot['last_run_at']}." : 'No scheduler heartbeat recorded.').' '.$snapshot['due_visibility_schedules'].' due visibility schedules.',
        ];
    }

    private function alerts(): array
    {
        $open = Schema::hasTable('signal_alerts')
            ? SignalAlert::query()->open()->count()
            : 0;
        $critical = Schema::hasTable('signal_alerts')
            ? SignalAlert::query()->open()->where('severity', 'critical')->count()
            : 0;

        return [
            'status' => $critical > 0 ? 'critical' : ($open > 0 ? 'warning' : 'healthy'),
            'label' => 'Alerts',
            'detail' => "{$open} open alerts, {$critical} critical.",
        ];
    }
}
