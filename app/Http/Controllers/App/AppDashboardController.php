<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\OnboardingState;
use App\Models\Opportunity;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Services\Analytics\ContentPerformanceInsightService;
use App\Services\Assistant\AssistantFeedService;
use App\Services\CreditWalletService;
use App\Services\Dashboard\DashboardActionFirstService;
use App\Services\Growth\ProgrammaticGrowthBetaSummary;
use App\Services\Onboarding\FirstValueActivationService;
use App\Services\RecommendedActions\RecommendedActionEngine;
use App\View\Presenters\ContentIndexTreePresenter;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AppDashboardController extends Controller
{
    public function index(
        CreditWalletService $creditWalletService,
        ContentPerformanceInsightService $contentPerformanceInsightService,
        FirstValueActivationService $activationService,
        DashboardActionFirstService $actionFirstService,
        ProgrammaticGrowthBetaSummary $programmaticGrowthBetaSummary,
        AssistantFeedService $assistantFeedService,
        RecommendedActionEngine $recommendedActionEngine,
    ): View|RedirectResponse
    {
        $user = request()->user();
        $organization = $user?->organization;

        if (
            $user &&
            ! $user->is_admin &&
            Schema::hasTable('onboarding_states') &&
            request()->routeIs('app.dashboard')
        ) {
            $phase = OnboardingState::query()
                ->where('user_id', $user->id)
                ->first(['phase', 'completed_steps_json']);

            $isCompleted = false;
            if ($phase) {
                $steps = is_array($phase->completed_steps_json) ? $phase->completed_steps_json : [];
                $isCompleted = count(array_intersect(['intent', 'company_profile', 'connect_site'], $steps)) === 3;
            }

            if ($phase && $phase->phase !== OnboardingState::PHASE_ACTIVATED && ! $isCompleted) {
                return redirect()->route('app.onboarding.show');
            }
        }

        $clientSiteIds = $organization
            ? $organization->clientSites()->pluck('client_sites.id')->all()
            : [];

        $connectedSitesCount = $organization
            ? $organization->clientSites()->where('is_active', true)->count()
            : 0;

        $integrationsCount = $organization?->clientSites()->count() ?? 0;
        $briefCount = Brief::query()->whereIn('client_site_id', $clientSiteIds)->count();
        $recentArticleRootIds = empty($clientSiteIds)
            ? collect()
            : Content::query()
                ->whereIn('client_site_id', $clientSiteIds)
                ->where('status', '!=', 'archived')
                ->selectRaw(Content::localizationRootExpression('contents') . ' as article_root_id')
                ->selectRaw('MAX(updated_at) as latest_activity_at')
                ->groupByRaw(Content::localizationRootExpression('contents'))
                ->orderByDesc('latest_activity_at')
                ->limit(5)
                ->pluck('article_root_id');

        $recentContents = $recentArticleRootIds->isEmpty()
            ? collect()
            : Content::query()
                ->with([
                    'workspace',
                    'clientSite.analyticsSite',
                    'currentVersion',
                    'contentDestination',
                    'publications.destination',
                    'translationSourceContent.workspace',
                    'translationSourceContent.clientSite',
                    'translationSourceContent.contentDestination',
                    'translationSourceContent.publications.destination',
                    'translationSourceContent.localizedVariants.workspace',
                    'translationSourceContent.localizedVariants.clientSite',
                    'translationSourceContent.localizedVariants.contentDestination',
                    'translationSourceContent.localizedVariants.publications.destination',
                    'localizedVariants.workspace',
                    'localizedVariants.clientSite',
                    'localizedVariants.contentDestination',
                    'localizedVariants.publications.destination',
                    'series',
                    'seriesArticle',
                    'automation:id,workspace_id,name,locale,locales,include_translation',
                ])
                ->whereIn('client_site_id', $clientSiteIds)
                ->where('status', '!=', 'archived')
                ->whereInLocalizationRoots($recentArticleRootIds->all())
                ->get();

        $recentContentInsights = $contentPerformanceInsightService->forContents($recentContents);
        $performanceSummary = $contentPerformanceInsightService->summarize($recentContentInsights);
        $recentContentTree = ContentIndexTreePresenter::present(
            $recentContents,
            $recentContents instanceof Collection ? $recentContents : collect($recentContents),
            [],
            $recentContentInsights
        )
            ->flatMap(fn (array $group): array => $group['articles'] ?? [])
            ->sortByDesc('updated_timestamp')
            ->take(5)
            ->values();

        $totalAvailableCredits = collect($clientSiteIds)
            ->map(fn (string $siteId) => (int) ($creditWalletService->getSummary($siteId)['available'] ?? 0))
            ->sum();

        $workspaceIds = $organization
            ? $organization->workspaces()->pluck('workspaces.id')->all()
            : [];

        $activationWorkspace = $organization
            ? $organization->workspaces()->orderBy('created_at')->first()
            : null;
        $activeOpportunityStatuses = [
            OpportunityStatus::OPEN->value,
            OpportunityStatus::REVIEWING->value,
            OpportunityStatus::APPROVED->value,
            OpportunityStatus::PLANNED->value,
        ];

        $distributionSummary = [
            'campaigns' => empty($workspaceIds) ? 0 : Campaign::query()->whereIn('workspace_id', $workspaceIds)->count(),
            'variants_pending' => empty($workspaceIds) ? 0 : SocialPostVariant::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->whereIn('status', ['generation_requested', 'generating', 'pending_approval'])
                ->count(),
            'scheduled_posts' => empty($workspaceIds) ? 0 : SocialPublication::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->whereIn('status', ['scheduled', 'queued', 'rate_limited'])
                ->count(),
            'failed_posts' => empty($workspaceIds) ? 0 : SocialPublication::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->where('status', 'failed')
                ->count(),
        ];
        $publicationQueue = empty($workspaceIds)
            ? collect()
            : SocialPublication::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->whereIn('status', ['scheduled', 'queued', 'rate_limited', 'failed'])
                ->with(['campaign', 'socialAccount', 'variant.campaign'])
                ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'queued' THEN 1 WHEN 'rate_limited' THEN 2 WHEN 'scheduled' THEN 3 ELSE 4 END")
                ->orderByRaw('COALESCE(next_retry_at, scheduled_for, queued_at, created_at)')
                ->limit(5)
                ->get();

        $opportunityIntelligenceSummary = [
            'open' => empty($workspaceIds) ? 0 : Opportunity::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->whereIn('status', $activeOpportunityStatuses)
                ->count(),
            'high_priority' => empty($workspaceIds) ? 0 : Opportunity::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->whereIn('status', $activeOpportunityStatuses)
                ->where('priority_score', '>=', 75)
                ->count(),
        ];

        return view('app.dashboard', [
            'connectedSitesCount' => $connectedSitesCount,
            'briefCount' => $briefCount,
            'integrationsCount' => $integrationsCount,
            'totalAvailableCredits' => $totalAvailableCredits,
            'recentContentTree' => $recentContentTree,
            'recentContentInsights' => $recentContentInsights,
            'performanceSummary' => $performanceSummary,
            'distributionSummary' => $distributionSummary,
            'publicationQueue' => $publicationQueue,
            'publicationQueueTimezone' => 'Europe/Amsterdam',
            'opportunityIntelligenceSummary' => $opportunityIntelligenceSummary,
            'programmaticGrowthSummary' => $activationWorkspace ? $programmaticGrowthBetaSummary->forWorkspace($activationWorkspace) : [],
            'activation' => $activationWorkspace ? $activationService->forWorkspace($activationWorkspace) : null,
            'actionFirstDashboard' => $actionFirstService->forWorkspace($activationWorkspace),
            'recommendedActionsSummary' => $activationWorkspace
                ? $recommendedActionEngine->dashboardSummary($activationWorkspace)
                : ['count' => 0, 'high_priority_count' => 0, 'approval_required_count' => 0, 'items' => collect()],
            'assistantFeed' => $activationWorkspace
                ? $assistantFeedService->hydrateWorkspace($activationWorkspace, 5, false)->take(6)
                : collect(),
            'isEmptyDashboard' => $briefCount === 0
                && $connectedSitesCount === 0
                && (int) data_get($distributionSummary, 'scheduled_posts', 0) === 0
                && (int) data_get($opportunityIntelligenceSummary, 'open', 0) === 0,
        ]);
    }
}
