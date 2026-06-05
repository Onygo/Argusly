<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminWebhookController extends Controller
{
    public function index(): View
    {
        $billingWebhookEvents = collect();
        $publishLayerWebhookEvents = collect();

        if (Schema::hasTable('webhook_events')) {
            $billingWebhookEvents = WebhookEvent::query()
                ->latest('received_at')
                ->limit(50)
                ->get();
        }

        if (Schema::hasTable('publishlayer_webhook_events')) {
            $publishLayerWebhookEvents = DB::table('publishlayer_webhook_events')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        return view('admin.webhooks.index', [
            'billing_webhook_events' => $billingWebhookEvents,
            'publishlayer_webhook_events' => $publishLayerWebhookEvents,
            'has_billing_logs' => Schema::hasTable('webhook_events'),
            'has_publishlayer_logs' => Schema::hasTable('publishlayer_webhook_events'),
        ]);
    }
}
