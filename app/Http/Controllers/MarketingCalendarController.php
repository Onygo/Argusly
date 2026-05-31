<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MarketingCalendarItem;
use App\Models\MarketingTask;
use App\Models\User;
use App\Services\MarketingCalendarService;
use App\Services\MarketingTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingCalendarController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly MarketingCalendarService $calendar,
        private readonly MarketingTaskService $tasks,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $currentBrand = $this->currentBrand->get($user) ?? abort(403);

        Gate::authorize('view_campaigns', ['account_id' => $account->id, 'brand_id' => $currentBrand->id]);

        $filters = $request->validate([
            'mode' => ['nullable', Rule::in(['month', 'week', 'list'])],
            'brand_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(MarketingCalendarItem::TYPES)],
            'status' => ['nullable', Rule::in(MarketingCalendarItem::STATUSES)],
            'assigned_to' => ['nullable', 'integer'],
            'starts' => ['nullable', 'date'],
            'ends' => ['nullable', 'date'],
        ]);
        $brand = $this->filterBrand($user, $account, $currentBrand, $filters['brand_id'] ?? null);
        $filters['brand_id'] = $brand?->id;
        $filters = $this->normalizeScopedFilters($account, $brand, $filters);
        $mode = $filters['mode'] ?? 'month';
        $items = $this->calendar->itemsForTenant($account, $brand, $filters);

        return view('app.calendar.index', [
            'account' => $account,
            'currentBrand' => $currentBrand,
            'brand' => $brand,
            'items' => $this->calendar->paginatedForTenant($account, $brand, $filters, $mode === 'list' ? 50 : 80),
            'periodItems' => $items,
            'itemsByDay' => $items->groupBy(fn (MarketingCalendarItem $item) => $item->start_at->toDateString()),
            'upcoming' => $this->calendar->upcoming($account, $brand),
            'filters' => $filters,
            'brands' => $this->accessibleBrands($user, $account),
            'campaigns' => $this->campaigns($account, $brand),
            'assignableUsers' => $this->tasks->assignableUsers($account, $brand),
            'types' => MarketingCalendarItem::TYPES,
            'statuses' => MarketingCalendarItem::STATUSES,
            'taskStatuses' => MarketingTask::STATUSES,
            'taskPriorities' => MarketingTask::PRIORITIES,
            'mode' => $mode,
            'calendarDays' => $this->calendarDays($mode, $filters['starts'] ?? null, $filters['ends'] ?? null),
        ]);
    }

    public function show(Request $request, MarketingCalendarItem $item): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $currentBrand = $this->currentBrand->get($user) ?? abort(403);

        Gate::authorize('view_campaigns', ['account_id' => $account->id, 'brand_id' => $currentBrand->id]);

        $itemBrand = $item->brand_id ? Brand::query()->where('account_id', $account->id)->findOrFail($item->brand_id) : null;
        $item = $this->calendar->findForTenant($account, $itemBrand, $item->id);

        return view('app.calendar.show', [
            'item' => $item,
            'account' => $account,
            'brand' => $item->brand,
        ]);
    }

    public function storeTask(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $currentBrand = $this->currentBrand->get($user) ?? abort(403);

        Gate::authorize('create', MarketingTask::class);

        $attributes = $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(MarketingTask::STATUSES)],
            'priority' => ['required', 'string', Rule::in(MarketingTask::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['required', 'date'],
        ]);

        $this->tasks->create($account, $currentBrand, $user, $attributes);

        return redirect()->route('app.calendar', [
            'mode' => $request->input('mode', 'month'),
            'starts' => Carbon::parse($attributes['due_at'])->toDateString(),
        ])->with('status', 'Calendar task created.');
    }

    private function filterBrand(User $user, mixed $account, Brand $currentBrand, mixed $brandId): ?Brand
    {
        if ($brandId === null || $brandId === '') {
            return $currentBrand;
        }

        if ((string) $brandId === '0') {
            return null;
        }

        $brand = Brand::query()->where('account_id', $account->id)->findOrFail((int) $brandId);

        if (! $user->brandMemberships()->where('account_id', $account->id)->where('brand_id', $brand->id)->where('status', 'active')->exists()) {
            abort(403);
        }

        return $brand;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeScopedFilters(mixed $account, ?Brand $brand, array $filters): array
    {
        if (($filters['campaign_id'] ?? null) !== null && $filters['campaign_id'] !== '') {
            $campaign = Campaign::query()->where('account_id', $account->id)
                ->when($brand !== null, fn ($query) => $query->where('brand_id', $brand->id))
                ->find((int) $filters['campaign_id']);

            if (! $campaign) {
                throw ValidationException::withMessages(['campaign_id' => 'The selected campaign is not available in this calendar scope.']);
            }
        }

        return $filters;
    }

    /**
     * @return Collection<int, Brand>
     */
    private function accessibleBrands(User $user, mixed $account): Collection
    {
        return $user->brands()
            ->wherePivot('account_id', $account->id)
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Campaign>
     */
    private function campaigns(mixed $account, ?Brand $brand): Collection
    {
        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn ($query) => $query->where('brand_id', $brand->id))
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function calendarDays(string $mode, mixed $starts = null, mixed $ends = null): Collection
    {
        $start = $starts ? Carbon::parse($starts)->startOfDay() : ($mode === 'week' ? now()->startOfWeek() : now()->startOfMonth()->startOfWeek());
        $end = $ends ? Carbon::parse($ends)->endOfDay() : ($mode === 'week' ? (clone $start)->endOfWeek() : now()->endOfMonth()->endOfWeek());

        if ($mode === 'list') {
            $end = $starts || $ends ? $end : now()->addDays(30)->endOfDay();
        }

        return collect(iterator_to_array(new \DatePeriod($start, new \DateInterval('P1D'), $end->copy()->addDay())))
            ->map(fn (\DateTimeInterface $date) => Carbon::instance($date));
    }
}
