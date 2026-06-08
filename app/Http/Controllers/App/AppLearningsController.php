<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Services\Analytics\SiteAnalyticsService;
use App\Services\Stats\AiSeoScoreComposer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AppLearningsController extends Controller
{
    public function __construct(
        private SiteAnalyticsService $siteAnalyticsService,
        private AiSeoScoreComposer $aiSeoScoreComposer,
    ) {
    }

    public function index(Request $request, ClientSite $site): View
    {
        $user = $request->user();
        $user->loadMissing('organization');

        // Eager load relationships to avoid N+1 queries
        $site->loadMissing(['analyticsSite', 'workspace']);

        $this->authorizeSiteAccess($site, $user);

        $analyticsSite = $site->analyticsSite;

        if (! $analyticsSite || ! $analyticsSite->is_enabled || ! $analyticsSite->verified_at) {
            return view('app.sites.learnings.not-configured', [
                'site' => $site,
                'analyticsSite' => $analyticsSite,
            ]);
        }

        $days = (int) $request->query('days', 7);
        $scope = $this->siteAnalyticsService->normalizeScope(
            (string) $request->query('scope', SiteAnalyticsService::SCOPE_ARGUSLY_CONTENT)
        );
        $sort = $this->normalizeSort((string) $request->query('sort', 'views'));

        $overview = $this->siteAnalyticsService->getLearningsOverview($analyticsSite, $days, $scope);
        $trending = $overview['trending']->map(function (array $row): array {
            $lastSeen = $row['last_seen'];
            $row['last_seen_label'] = $this->getLastSeenLabel($lastSeen);

            return $row;
        });

        if ($sort === 'ai_seo_score') {
            $trending = $trending->sortByDesc(static fn (array $row): float => is_numeric($row['ai_seo_score'] ?? null)
                ? (float) $row['ai_seo_score']
                : -1.0)->values();
        } elseif ($sort === 'roi_score') {
            $trending = $trending->sortByDesc(static fn (array $row): float => is_numeric($row['roi_score'] ?? null)
                ? (float) $row['roi_score']
                : -1.0)->values();
        } else {
            $trending = $trending->sortByDesc('views')->values();
        }

        return view('app.sites.learnings.index', [
            'site' => $site,
            'analyticsSite' => $analyticsSite,
            'trending' => $trending,
            'summary' => $overview['summary'],
            'dailyTrend' => $overview['daily_trend'],
            'days' => $overview['days'],
            'scope' => $scope,
            'sort' => $sort,
            'dateLabels' => [
                'start' => $overview['start_date']->format('M j, Y'),
                'end' => $overview['end_date']->format('M j, Y'),
            ],
            'metricThresholds' => [
                'engaged_after_seconds' => (int) config('analytics.tracking.engaged_after_seconds', 10),
                'read_through_scroll_percent' => (int) config('analytics.tracking.read_through_scroll_percent', 75),
                'read_through_fallback_seconds' => (int) config('analytics.tracking.read_through_fallback_seconds', 20),
            ],
            'aiSeoScoreFormulaLabel' => $this->aiSeoScoreComposer->tooltipLabel(),
        ]);
    }

    private function normalizeSort(string $sort): string
    {
        $sort = strtolower(trim($sort));

        return in_array($sort, ['views', 'roi_score', 'ai_seo_score'], true)
            ? $sort
            : 'views';
    }

    private function authorizeSiteAccess(ClientSite $site, $user): void
    {
        $organization = $user->organization;

        if (! $organization) {
            abort(403, 'No organization');
        }

        $belongsToOrg = $site->workspace?->organization_id === $organization->id;

        if (! $belongsToOrg) {
            abort(403, 'Site does not belong to your organization');
        }
    }

    private function getLastSeenLabel(Carbon $date): string
    {
        $today = now();

        if ($date->isSameDay($today)) {
            return 'Today';
        }

        if ($date->isSameDay($today->copy()->subDay())) {
            return 'Yesterday';
        }

        $daysAgo = (int) $date->copy()->startOfDay()->diffInDays($today->copy()->startOfDay());

        if ($daysAgo <= 1) {
            return 'Yesterday';
        }

        if ($daysAgo === 1) {
            return '1 day ago';
        }

        return "{$daysAgo} days ago";
    }
}
