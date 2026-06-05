<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\RunContentOpportunityEngineRequest;
use App\Jobs\ContentOpportunityEngine\GenerateContentOpportunitiesJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\ContentOpportunityRun;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use App\Services\ContentOpportunityEngine\ContentOpportunityEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AppContentOpportunityController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = (int) $request->user()->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->with(['clientSites' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')
                ->select(['id', 'workspace_id', 'name'])])
            ->orderBy('created_at')
            ->get(['id', 'organization_id', 'name', 'display_name']);

        if ($workspaces->isEmpty()) {
            abort(404);
        }

        $workspace = $request->query('workspace_id')
            ? $workspaces->firstWhere('id', (string) $request->query('workspace_id'))
            : $workspaces->first();

        if (! $workspace) {
            abort(404);
        }

        $siteId = trim((string) $request->query('client_site_id', '')) ?: null;
        if ($siteId && ! $workspace->clientSites->contains('id', $siteId)) {
            abort(404);
        }

        $query = ContentOpportunity::query()
            ->with('site:id,name')
            ->where('workspace_id', $workspace->id)
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('funnel_stage'), fn ($query, $stage) => $query->where('funnel_stage', $stage))
            ->when($request->query('intent'), fn ($query, $intent) => $query->where('primary_search_intent', $intent))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status), fn ($query) => $query->where('status', ContentOpportunity::STATUS_OPEN));

        match ((string) $request->query('sort', 'priority')) {
            'urgency' => $query->orderByDesc('urgency_score'),
            'business_value' => $query->orderByDesc('business_value_score'),
            'newest' => $query->latest(),
            default => $query->orderByDesc('priority_score')->orderByDesc('last_seen_at'),
        };

        $opportunities = $query->paginate(25)->withQueryString();
        $executionSettings = AgenticMarketingExecutionSetting::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('brand_voice_id')
            ->first()
            ?: AgenticMarketingExecutionSetting::defaultsFor($workspace);
        $gate = app(AgenticApprovalGate::class);
        $opportunityPolicies = $opportunities->getCollection()
            ->mapWithKeys(function (ContentOpportunity $opportunity) use ($gate, $workspace): array {
                $siteId = (string) ($opportunity->client_site_id ?? '');
                if ($siteId === '' && $workspace->clientSites->count() === 1) {
                    $siteId = (string) $workspace->clientSites->first()->id;
                }
                $context = [
                    'site_id' => $siteId !== '' ? $siteId : null,
                    'priority_score' => (int) round((float) $opportunity->priority_score),
                    'estimated_credit_impact' => $this->estimatedOpportunityCredits($opportunity),
                    'is_external_publication' => true,
                    'content_complete' => true,
                ];

                return [
                    (string) $opportunity->id => [
                        'brief' => $gate->forAction(AgenticApprovalGate::ACTION_GENERATE_BRIEF, $workspace, $context),
                        'chained' => $gate->forAction(AgenticApprovalGate::ACTION_CREATE_CHAINED_PLAN, $workspace, $context),
                        'estimated_credits' => $context['estimated_credit_impact'],
                    ],
                ];
            });
        $runs = ContentOpportunityRun::query()
            ->where('workspace_id', $workspace->id)
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->latest()
            ->limit(10)
            ->get();

        return view('app.content-opportunities.index', [
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'siteId' => $siteId,
            'opportunities' => $opportunities,
            'runs' => $runs,
            'filters' => $request->only(['type', 'funnel_stage', 'intent', 'status', 'sort']),
            'executionSettings' => $executionSettings,
            'opportunityPolicies' => $opportunityPolicies,
            'summary' => [
                'total' => ContentOpportunity::query()->where('workspace_id', $workspace->id)->count(),
                'open' => ContentOpportunity::query()->where('workspace_id', $workspace->id)->where('status', ContentOpportunity::STATUS_OPEN)->count(),
                'strategic' => ContentOpportunity::query()->where('workspace_id', $workspace->id)->where('expected_impact', 'strategic')->count(),
                'avg_priority' => (float) ContentOpportunity::query()->where('workspace_id', $workspace->id)->avg('priority_score'),
            ],
        ]);
    }

    private function estimatedOpportunityCredits(ContentOpportunity $opportunity): int
    {
        return match ((string) $opportunity->type) {
            'campaign_cluster' => 18,
            'comparison_page', 'implementation_guide', 'bofu_page', 'use_case_page', 'industry_page', 'feature_page' => 12,
            'refresh_opportunity', 'answer_block_opportunity', 'faq_opportunity' => 6,
            default => 8,
        };
    }

    public function run(RunContentOpportunityEngineRequest $request, ContentOpportunityEngine $engine): RedirectResponse
    {
        $data = $request->validated();
        $workspace = Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail($data['workspace_id']);

        $siteId = $data['client_site_id'] ?? null;
        if ($siteId) {
            ClientSite::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail($siteId);
        }

        if ($request->boolean('run_inline')) {
            $engine->run($workspace, $siteId, ['source_type' => 'ui']);

            return back()->with('status', 'Content Opportunity Engine run completed.');
        }

        GenerateContentOpportunitiesJob::dispatch(
            workspaceId: (string) $workspace->id,
            clientSiteId: $siteId,
            options: ['source_type' => 'ui'],
        )->onQueue('intelligence')->afterCommit();

        return back()->with('status', 'Content Opportunity Engine run queued.');
    }

    public function createBrief(Request $request, ContentOpportunity $opportunity): RedirectResponse
    {
        abort_unless((int) $opportunity->organization_id === (int) $request->user()->organization_id, 404);

        $data = $request->validate([
            'mode' => ['required', 'in:single,chained'],
            'site_id' => ['nullable', 'uuid'],
        ]);

        $opportunity->loadMissing('workspace', 'site');
        $site = $opportunity->site;

        if (! $site && $request->filled('site_id')) {
            $site = ClientSite::query()
                ->where('workspace_id', $opportunity->workspace_id)
                ->where('is_active', true)
                ->findOrFail((string) $data['site_id']);
        } elseif (! $site && $opportunity->workspace) {
            $sites = ClientSite::query()
                ->where('workspace_id', $opportunity->workspace_id)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->limit(2)
                ->get();

            if ($sites->count() === 1) {
                $site = $sites->first();
            } elseif ($sites->count() > 1) {
                return back()->withErrors(['site_id' => 'Select the publishing site before creating a brief from this opportunity.']);
            }
        }

        if (! $site) {
            return back()->withErrors(['opportunity' => 'Connect a site before creating a brief from this opportunity.']);
        }

        $brief = Brief::query()->create([
            'client_site_id' => (string) $site->id,
            'created_by_user_id' => (int) $request->user()->id,
            'status' => 'draft',
            'source' => 'content_opportunity',
            'title' => $opportunity->title,
            'language' => (string) data_get($opportunity->localization_recommendation, 'priority_locales.0', 'en'),
            'content_type' => $this->briefContentType($opportunity),
            'output_type' => 'article',
            'primary_keyword' => $this->primaryKeyword($opportunity),
            'secondary_keywords' => $this->secondaryKeywords($opportunity),
            'audience' => $opportunity->target_audience,
            'target_audience' => $opportunity->target_audience,
            'funnel_stage' => $opportunity->funnel_stage,
            'search_intent' => $opportunity->primary_search_intent,
            'unique_angle' => $opportunity->angle,
            'key_points' => $this->keyPoints($opportunity),
            'call_to_action' => $opportunity->suggested_cta,
            'desired_length_min' => $data['mode'] === 'chained' ? 900 : 1000,
            'desired_length_max' => $data['mode'] === 'chained' ? 1300 : 1500,
            'notes' => $this->briefNotes($opportunity),
            'progress' => 0,
            'client_refs' => [
                'client_type' => 'content_opportunity',
                'site_url' => (string) ($site->site_url ?? ''),
                'content_opportunity' => $this->opportunityReference($opportunity),
                'source_briefing' => [
                    'chain_proposal' => $data['mode'] === 'chained' ? $this->chainProposal($opportunity) : null,
                    'generated_at' => now()->toIso8601String(),
                ],
            ],
            'wp_site_id' => (string) $site->id,
        ]);

        $opportunity->forceFill(['status' => ContentOpportunity::STATUS_PLANNED])->save();
        app(AgenticActionRunLogger::class)->recordStandalone(
            $opportunity->workspace,
            $data['mode'] === 'chained' ? AgenticApprovalGate::ACTION_CREATE_CHAINED_PLAN : AgenticApprovalGate::ACTION_GENERATE_BRIEF,
            AgenticActionRun::STATUS_COMPLETED,
            [
                'content_id' => $opportunity->content_id,
                'reason' => 'Customer created a brief from a content opportunity.',
                'input_snapshot' => [
                    'mode' => $data['mode'],
                    'site_id' => (string) $site->id,
                    'opportunity_id' => (string) $opportunity->id,
                ],
                'output_snapshot' => [
                    'brief_id' => (string) $brief->id,
                    'title' => $brief->title,
                ],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]
        );

        if ($data['mode'] === 'chained') {
            return redirect()
                ->route('app.content.series.create', ['source_brief' => $brief->id])
                ->with('status', 'Brief created from opportunity. Review the chained article plan.');
        }

        return redirect()
            ->route('app.content.workspace.show', $brief)
            ->with('status', 'Brief created from opportunity. Generate a single article draft when ready.');
    }

    private function briefContentType(ContentOpportunity $opportunity): string
    {
        return match ((string) $opportunity->type) {
            'faq_opportunity', 'answer_block_opportunity' => 'other',
            default => 'blog',
        };
    }

    private function primaryKeyword(ContentOpportunity $opportunity): ?string
    {
        return trim((string) data_get($opportunity->normalized_payload, 'candidate.topic'))
            ?: trim((string) $opportunity->title)
            ?: null;
    }

    private function secondaryKeywords(ContentOpportunity $opportunity): array
    {
        return collect((array) $opportunity->related_entities)
            ->merge(collect((array) $opportunity->recommended_internal_links)->pluck('anchor_text'))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    private function keyPoints(ContentOpportunity $opportunity): array
    {
        return collect([
            $opportunity->reasoning,
            $opportunity->why_this_matters,
            $opportunity->why_now,
            $opportunity->competitor_pressure,
            $opportunity->ai_visibility_opportunity,
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    private function briefNotes(ContentOpportunity $opportunity): string
    {
        $links = collect((array) $opportunity->recommended_internal_links)
            ->map(fn (array $link): string => trim((string) ($link['title'] ?? $link['url'] ?? $link['anchor_text'] ?? '')))
            ->filter()
            ->take(6)
            ->map(fn (string $value): string => '- '.$value)
            ->implode(PHP_EOL);

        return trim(implode(PHP_EOL.PHP_EOL, array_filter([
            'Generated from Content Opportunity Engine.',
            'Opportunity type: '.str_replace('_', ' ', (string) $opportunity->type),
            'Expected impact: '.(string) $opportunity->expected_impact,
            'Suggested schema: '.(string) $opportunity->suggested_schema,
            $links !== '' ? 'Recommended internal link context:'.PHP_EOL.$links : null,
        ])));
    }

    private function opportunityReference(ContentOpportunity $opportunity): array
    {
        return [
            'id' => (string) $opportunity->id,
            'type' => (string) $opportunity->type,
            'priority_score' => (float) $opportunity->priority_score,
            'expected_impact' => (string) $opportunity->expected_impact,
            'source_signals' => $opportunity->source_signals,
            'query_intent' => $opportunity->query_intent_payload,
            'recommended_internal_links' => $opportunity->recommended_internal_links,
        ];
    }

    private function chainProposal(ContentOpportunity $opportunity): array
    {
        $topic = $this->primaryKeyword($opportunity) ?: $opportunity->title;
        $entities = collect((array) $opportunity->related_entities)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->take(4)
            ->values();

        $fallbacks = collect([
            'What is '.$topic.'?',
            'How to implement '.$topic,
            $topic.' examples and use cases',
            $topic.' comparison and alternatives',
        ]);

        return [
            'pillar_topic' => Str::headline((string) $topic),
            'strategy' => 'Create a pillar article plus supporting chained articles based on the opportunity findings.',
            'supporting_subtopics' => $entities
                ->map(fn (string $entity): array => ['title' => Str::headline($entity), 'reason' => 'Related entity from the opportunity signals.'])
                ->merge($fallbacks->map(fn (string $title): array => ['title' => Str::headline($title), 'reason' => 'Derived from the opportunity topic.']))
                ->unique('title')
                ->take(6)
                ->values()
                ->all(),
        ];
    }
}
