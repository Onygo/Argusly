<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Ga4MetricSnapshot;
use App\Models\Ga4Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
    ) {}

    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $brand = $this->currentBrand->get($user) ?? abort(403);

        Gate::forUser($user)->authorize('view_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $properties = Ga4Property::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->withCount('metricSnapshots')
            ->latest('last_synced_at')
            ->latest()
            ->get();

        $totals = Ga4MetricSnapshot::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->selectRaw('COALESCE(SUM(sessions), 0) as sessions_total')
            ->selectRaw('COALESCE(SUM(users), 0) as users_total')
            ->selectRaw('COALESCE(SUM(pageviews), 0) as pageviews_total')
            ->selectRaw('COALESCE(SUM(conversions), 0) as conversions_total')
            ->first();

        $latestSnapshots = Ga4MetricSnapshot::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with(['ga4Property', 'contentAsset'])
            ->when(
                $request->filled('property'),
                fn (Builder $query) => $query->where('ga4_property_id', $request->integer('property')),
            )
            ->latest('date')
            ->latest()
            ->limit(10)
            ->get();

        return view('app.analytics.index', [
            'account' => $account,
            'brand' => $brand,
            'properties' => $properties,
            'totals' => $totals,
            'latestSnapshots' => $latestSnapshots,
        ]);
    }
}
