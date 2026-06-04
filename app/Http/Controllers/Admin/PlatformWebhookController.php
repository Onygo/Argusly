<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RetryWebhookDeliveryJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookEventCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformWebhookController extends Controller
{
    public function index(WebhookEventCatalog $catalog): View
    {
        return view('admin.platform.webhooks', [
            'accounts' => Account::query()->orderBy('name')->get(),
            'brands' => Brand::query()->with('account')->orderBy('name')->get(),
            'events' => $catalog->events(),
            'endpoints' => WebhookEndpoint::query()
                ->with(['account', 'brand'])
                ->withCount('deliveries')
                ->latest()
                ->paginate(20),
            'deliveries' => WebhookDelivery::query()
                ->with(['endpoint', 'account', 'brand'])
                ->latest()
                ->limit(30)
                ->get(),
            'statuses' => WebhookEndpoint::STATUSES,
        ]);
    }

    public function store(Request $request, WebhookEventCatalog $catalog): RedirectResponse
    {
        $data = $this->validated($request, $catalog);

        WebhookEndpoint::query()->create($data);

        return back()->with('status', 'Webhook endpoint created.');
    }

    public function update(Request $request, WebhookEndpoint $endpoint, WebhookEventCatalog $catalog): RedirectResponse
    {
        $data = $this->validated($request, $catalog);

        $endpoint->update($data);

        return back()->with('status', 'Webhook endpoint updated.');
    }

    public function retry(WebhookDelivery $delivery): RedirectResponse
    {
        RetryWebhookDeliveryJob::dispatch($delivery->id);

        return back()->with('status', 'Webhook delivery queued for retry.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, WebhookEventCatalog $catalog): array
    {
        $events = array_keys($catalog->events());

        $data = $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'status' => ['required', Rule::in(WebhookEndpoint::STATUSES)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', Rule::in($events)],
            'signing_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $brand = isset($data['brand_id']) ? Brand::query()->find($data['brand_id']) : null;

        if ($brand && ! isset($data['account_id'])) {
            $data['account_id'] = $brand->account_id;
        }

        return $data;
    }
}
