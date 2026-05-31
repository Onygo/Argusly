<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Ga4MetricSnapshot;
use App\Models\IntegrationConnection;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\Module;
use App\Models\SubscriptionModule;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationPermissionService;
use App\Services\IntelligenceSignalService;
use App\Services\MentionIntelligenceService;
use App\Services\RecommendationService;
use App\Services\TopicIntelligenceService;
use App\Services\VisibilityMonitoringService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        IntegrationPermissionService $integrationPermissions,
        IntelligenceSignalService $signals,
        RecommendationService $recommendations,
        VisibilityMonitoringService $visibility,
        CreditService $credits,
        TopicIntelligenceService $topics,
        MentionIntelligenceService $mentions,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        return view('app.dashboard', [
            'account' => $account,
            'brand' => $brand,
            'accountRole' => $account ? $this->roleForAccount($user, $account) : null,
            'brandRole' => $brand ? $this->roleForBrand($user, $brand) : null,
            'activeModules' => $account ? $this->activeModules($account) : collect(),
            'connectedIntegrationsCount' => $account ? $this->connectedIntegrationsCount($user, $account, $brand, $integrationPermissions) : 0,
            'availableCredits' => $account ? $credits->balance($account) : null,
            'recentActivity' => $account ? $this->recentActivity($account, $brand) : collect(),
            'intelligenceFeed' => $account ? $signals->recentForTenant($account, $brand, 4) : collect(),
            'intelligenceStats' => $account ? $signals->statisticsForTenant($account, $brand) : ['open' => 0, 'critical' => 0, 'high' => 0, 'unreviewed' => 0],
            'recommendations' => $account ? $recommendations->recentForTenant($account, $brand, 4) : collect(),
            'recommendationStats' => $account ? $recommendations->statisticsForTenant($account, $brand) : ['open' => 0, 'accepted' => 0, 'completed' => 0],
            'visibilityStats' => $account ? $visibility->dashboardStats($account, $brand) : ['checks' => 0, 'latest_score' => null, 'mentions_found' => 0, 'providers' => 0],
            'topTopics' => $account ? $topics->topTopics($account, $brand) : collect(),
            'recentMentions' => $account ? $mentions->recentForTenant($account, $brand, 5) : collect(),
            'mentionSentimentOverview' => $account ? $mentions->sentimentOverview($account, $brand) : ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0, 'unknown' => 0, 'total' => 0],
            'ga4Stats' => $account ? $this->ga4Stats($account, $brand) : ['sessions' => 0, 'users' => 0, 'pageviews' => 0, 'conversions' => 0],
            'searchConsoleStats' => $account ? $this->searchConsoleStats($account, $brand) : ['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null],
        ]);
    }

    private function roleForAccount(User $user, Account $account): ?string
    {
        return $user->roleAssignments()
            ->where('account_id', $account->id)
            ->whereNull('brand_id')
            ->where(fn (Builder $query) => $this->activeRoleWindow($query))
            ->whereHas('role')
            ->with('role')
            ->get()
            ->sortByDesc(fn ($assignment) => $assignment->role->priority)
            ->first()
            ?->role
            ?->display_name;
    }

    private function roleForBrand(User $user, Brand $brand): ?string
    {
        return $user->roleAssignments()
            ->where('account_id', $brand->account_id)
            ->where('brand_id', $brand->id)
            ->where(fn (Builder $query) => $this->activeRoleWindow($query))
            ->whereHas('role')
            ->with('role')
            ->get()
            ->sortByDesc(fn ($assignment) => $assignment->role->priority)
            ->first()
            ?->role
            ?->display_name;
    }

    /**
     * @return Collection<int, Module>
     */
    private function activeModules(Account $account): Collection
    {
        return SubscriptionModule::query()
            ->active()
            ->where('account_id', $account->id)
            ->whereHas('account', fn (Builder $query) => $query->where('status', 'active'))
            ->whereHas('subscription', fn (Builder $query) => $query->active())
            ->whereHas('module', fn (Builder $query) => $query->where('is_active', true))
            ->with('module')
            ->get()
            ->pluck('module')
            ->filter()
            ->unique('id')
            ->values();
    }

    private function connectedIntegrationsCount(User $user, Account $account, ?Brand $brand, IntegrationPermissionService $integrationPermissions): int
    {
        return IntegrationConnection::query()
            ->active()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->get()
            ->filter(fn (IntegrationConnection $connection) => $integrationPermissions->canUse($user, $connection, $account, $brand)
                || $integrationPermissions->canManage($user, $connection, $account, $brand))
            ->count();
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    private function recentActivity(Account $account, ?Brand $brand): Collection
    {
        return ActivityLog::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with('user')
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    private function activeRoleWindow(Builder $query): void
    {
        $query->where(function (Builder $window): void {
            $window->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now());
        })->where(function (Builder $window): void {
            $window->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @return array{sessions: int, users: int, pageviews: int, conversions: int}
     */
    private function ga4Stats(Account $account, ?Brand $brand): array
    {
        $totals = Ga4MetricSnapshot::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where('brand_id', $brand->id),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->where('date', '>=', now()->subDays(29)->toDateString())
            ->selectRaw('COALESCE(SUM(sessions), 0) as sessions_total')
            ->selectRaw('COALESCE(SUM(users), 0) as users_total')
            ->selectRaw('COALESCE(SUM(pageviews), 0) as pageviews_total')
            ->selectRaw('COALESCE(SUM(conversions), 0) as conversions_total')
            ->first();

        return [
            'sessions' => (int) ($totals?->sessions_total ?? 0),
            'users' => (int) ($totals?->users_total ?? 0),
            'pageviews' => (int) ($totals?->pageviews_total ?? 0),
            'conversions' => (int) ($totals?->conversions_total ?? 0),
        ];
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float|null, position: float|null}
     */
    private function searchConsoleStats(Account $account, ?Brand $brand): array
    {
        $totals = SearchConsoleQuerySnapshot::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where('brand_id', $brand->id),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->where('date', '>=', now()->subDays(29)->toDateString())
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks_total')
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions_total')
            ->selectRaw('AVG(ctr) as ctr_average')
            ->selectRaw('AVG(position) as position_average')
            ->first();

        return [
            'clicks' => (int) ($totals?->clicks_total ?? 0),
            'impressions' => (int) ($totals?->impressions_total ?? 0),
            'ctr' => $totals?->ctr_average !== null ? (float) $totals->ctr_average : null,
            'position' => $totals?->position_average !== null ? (float) $totals->position_average : null,
        ];
    }
}
