<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\LlmRequest;
use App\Models\SignalAlert;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AlertService;
use App\Services\PlatformHealthService;
use App\Services\QueueHealthService;
use App\Services\SchedulerMonitorService;
use App\Services\SourceHealthService;
use Illuminate\Contracts\View\View;

class PlatformOverviewController extends Controller
{
    public function __invoke(
        PlatformHealthService $health,
        QueueHealthService $queues,
        SourceHealthService $sources,
        SchedulerMonitorService $scheduler,
        AlertService $alerts,
    ): View
    {
        return view('admin.platform.overview', [
            'health' => $health->snapshot(),
            'queue' => $queues->snapshot(),
            'sourceHealth' => $sources->snapshot(),
            'scheduler' => $scheduler->snapshot(),
            'alertStats' => $alerts->statistics(),
            'metrics' => [
                'feature_flags' => FeatureFlag::query()->count(),
                'enabled_feature_flags' => FeatureFlag::query()->where('enabled', true)->count(),
                'webhook_endpoints' => WebhookEndpoint::query()->count(),
                'failed_webhook_deliveries' => WebhookDelivery::query()->where('status', 'failed')->count(),
                'open_alerts' => SignalAlert::query()->open()->count(),
                'ai_requests_today' => LlmRequest::query()->where('created_at', '>=', now()->startOfDay())->count(),
                'failed_ai_requests_today' => LlmRequest::query()->where('status', 'failed')->where('created_at', '>=', now()->startOfDay())->count(),
            ],
        ]);
    }
}
