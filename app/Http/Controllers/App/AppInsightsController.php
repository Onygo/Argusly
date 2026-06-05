<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\SeoAudit;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppInsightsController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = (int) $request->user()->organization_id;

        $sites = ClientSite::query()
            ->with(['workspace', 'analyticsSite'])
            ->withCount([
                'llmTrackingQueries',
                'competitors',
                'seoAudits',
            ])
            ->whereHas('workspace', function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId);
            })
            ->orderBy('name')
            ->get();

        return view('app.insights.index', [
            'sites' => $sites,
        ]);
    }

    public function show(Request $request, ClientSite $site, FeatureGate $featureGate): View
    {
        $this->assertSiteInOrganization($request, $site);

        $site->loadMissing(['workspace', 'analyticsSite']);

        $analyticsSite = $site->analyticsSite;
        $latestAudit = $site->seoAudits()
            ->latest('started_at')
            ->first();

        return view('app.insights.show', [
            'site' => $site,
            'overviewCards' => [
                [
                    'title' => 'Share of AI Attention',
                    'description' => 'Track AI visibility, brand presence gaps, competitor pressure, and missed query opportunities.',
                    'url' => route('app.sites.llm-tracking.index', $site),
                    'cta' => 'Open AI Attention',
                    'status' => $featureGate->can($site->workspace, 'link_intelligence')
                        ? sprintf('%d queries configured', (int) $site->llmTrackingQueries()->count())
                        : 'Unavailable on the current workspace plan',
                    'available' => $featureGate->can($site->workspace, 'link_intelligence'),
                ],
                [
                    'title' => 'Audits',
                    'description' => 'Run SEO audits and review the latest crawl health, issue counts, and fix recommendations.',
                    'url' => route('app.sites.seo-audits.index', $site),
                    'cta' => 'Open Audits',
                    'status' => $latestAudit
                        ? sprintf('Latest audit: %s', (string) $latestAudit->status)
                        : 'No audit runs yet',
                    'available' => true,
                ],
                [
                    'title' => 'Competitors',
                    'description' => 'Manage competitor domains used for monitoring, comparisons, and generation context.',
                    'url' => route('app.sites.competitors.index', $site),
                    'cta' => 'Open Competitors',
                    'status' => $featureGate->can($site->workspace, 'link_intelligence')
                        ? sprintf('%d competitors configured', (int) $site->competitors()->count())
                        : 'Unavailable on the current workspace plan',
                    'available' => $featureGate->can($site->workspace, 'link_intelligence'),
                ],
                [
                    'title' => 'Analytics',
                    'description' => 'Configure tracking, verify the domain, and inspect quick performance metrics.',
                    'url' => route('app.sites.analytics.show', $site),
                    'cta' => 'Open Analytics',
                    'status' => match (true) {
                        ! $analyticsSite => 'Analytics not enabled',
                        ! $analyticsSite->is_enabled => 'Analytics disabled',
                        ! $analyticsSite->verified_at => 'Analytics awaiting domain verification',
                        default => 'Analytics enabled and verified',
                    },
                    'available' => true,
                ],
                [
                    'title' => 'Learnings',
                    'description' => 'Review content performance, engagement patterns, and AI SEO signals from tracked traffic.',
                    'url' => route('app.sites.learnings.index', $site),
                    'cta' => 'Open Learnings',
                    'status' => $analyticsSite && $analyticsSite->is_enabled && $analyticsSite->verified_at
                        ? 'Ready to review tracked learnings'
                        : 'Requires verified analytics to unlock learnings',
                    'available' => true,
                ],
            ],
        ]);
    }

    private function assertSiteInOrganization(Request $request, ClientSite $site): void
    {
        if ((int) $site->workspace?->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }
}
