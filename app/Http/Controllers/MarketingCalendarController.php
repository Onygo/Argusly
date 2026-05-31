<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\MarketingCalendarItem;
use App\Models\User;
use App\Services\MarketingCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingCalendarController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly MarketingCalendarService $calendar,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $brand = $this->currentBrand->get($user) ?? abort(403);

        Gate::authorize('view_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $filters = $request->validate([
            'mode' => ['nullable', Rule::in(['month', 'week'])],
            'type' => ['nullable', Rule::in(MarketingCalendarItem::TYPES)],
            'status' => ['nullable', Rule::in(MarketingCalendarItem::STATUSES)],
            'starts' => ['nullable', 'date'],
            'ends' => ['nullable', 'date'],
        ]);

        return view('app.calendar.index', [
            'account' => $account,
            'brand' => $brand,
            'items' => $this->calendar->paginatedForTenant($account, $brand, $filters),
            'upcoming' => $this->calendar->upcoming($account, $brand),
            'filters' => $filters,
            'types' => MarketingCalendarItem::TYPES,
            'statuses' => MarketingCalendarItem::STATUSES,
            'mode' => $filters['mode'] ?? 'month',
        ]);
    }
}
