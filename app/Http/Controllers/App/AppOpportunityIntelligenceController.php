<?php

namespace App\Http\Controllers\App;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Http\Controllers\Controller;
use App\Jobs\OpportunityIntelligence\RunOpportunityIntelligenceJob;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Workspace;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppOpportunityIntelligenceController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        $opportunityQuery = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->with(['campaign', 'content', 'contentCluster'])
            ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status), fn ($query) => $query->where('status', 'open'));

        $opportunities = $opportunityQuery
            ->orderByDesc('priority_score')
            ->latest('last_seen_at')
            ->paginate(20)
            ->withQueryString();

        $signals = OpportunitySignal::query()
            ->where('workspace_id', $workspace->id)
            ->latest('observed_at')
            ->limit(40)
            ->get();

        $timeline = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->latest('last_seen_at')
            ->limit(30)
            ->get()
            ->groupBy(fn (Opportunity $opportunity): string => $opportunity->last_seen_at?->format('Y-m-d') ?? $opportunity->created_at?->format('Y-m-d') ?? 'Unseen');

        return view('app.opportunity-intelligence.index', [
            'workspace' => $workspace,
            'opportunities' => $opportunities,
            'signals' => $signals,
            'timeline' => $timeline,
            'categories' => OpportunityCategory::values(),
            'sources' => OpportunitySignalSource::values(),
            'filters' => $request->only(['category', 'status']),
            'summary' => [
                'open' => Opportunity::query()->where('workspace_id', $workspace->id)->where('status', 'open')->count(),
                'avg_priority' => (float) Opportunity::query()->where('workspace_id', $workspace->id)->avg('priority_score'),
                'signals' => OpportunitySignal::query()->where('workspace_id', $workspace->id)->count(),
                'high_confidence' => Opportunity::query()->where('workspace_id', $workspace->id)->where('confidence_score', '>=', 75)->count(),
            ],
        ]);
    }

    public function run(Request $request, OpportunityIntelligenceEngine $engine): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if ($request->boolean('run_inline')) {
            $result = $engine->run($workspace);

            return back()->with('status', sprintf('Opportunity intelligence refreshed: %d created, %d updated.', $result['created'], $result['updated']));
        }

        RunOpportunityIntelligenceJob::dispatch((string) $workspace->id)->afterCommit();

        return back()->with('status', 'Opportunity intelligence refresh queued.');
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace_id'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }
}
