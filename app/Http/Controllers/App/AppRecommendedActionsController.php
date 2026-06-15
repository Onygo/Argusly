<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\RecommendedAction;
use App\Services\RecommendedActions\RecommendedActionEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppRecommendedActionsController extends Controller
{
    public function index(Request $request, RecommendedActionEngine $engine): View
    {
        $workspace = $request->user()?->organization?->workspaces()->orderBy('created_at')->firstOrFail();
        $engine->hydrateWorkspace($workspace, 6);

        $sourceGroup = $request->query('source_group');
        $priority = $request->query('priority');

        $actions = RecommendedAction::query()
            ->forWorkspace($workspace)
            ->visible()
            ->when($sourceGroup, fn ($query) => $query->where('source_group', $sourceGroup))
            ->when($priority, fn ($query) => $query->where('priority_label', $priority))
            ->whereIn('status', [RecommendedAction::STATUS_OPEN, RecommendedAction::STATUS_APPROVED, RecommendedAction::STATUS_IN_PROGRESS])
            ->orderByDesc('priority_score')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $summary = [
            'total' => RecommendedAction::query()->forWorkspace($workspace)->visible()->open()->count(),
            'critical' => RecommendedAction::query()->forWorkspace($workspace)->visible()->open()->where('priority_label', 'critical')->count(),
            'high' => RecommendedAction::query()->forWorkspace($workspace)->visible()->open()->where('priority_label', 'high')->count(),
            'approval_required' => RecommendedAction::query()
                ->forWorkspace($workspace)
                ->visible()
                ->open()
                ->where('what_requires_approval', '!=', '')
                ->count(),
        ];

        return view('app.recommended-actions.index', [
            'actions' => $actions,
            'summary' => $summary,
            'sourceGroup' => $sourceGroup,
            'priority' => $priority,
            'sourceGroups' => [
                RecommendedAction::SOURCE_OPPORTUNITY => 'Opportunities',
                RecommendedAction::SOURCE_LEARNING => 'Learning',
                RecommendedAction::SOURCE_AI_VISIBILITY => 'AI Visibility',
                RecommendedAction::SOURCE_AGENTIC_MARKETING => 'Agentic Marketing',
                RecommendedAction::SOURCE_CAMPAIGN_PLANNING => 'Campaign Planning',
                RecommendedAction::SOURCE_CONTENT_INTELLIGENCE => 'Content Intelligence',
                RecommendedAction::SOURCE_DISTRIBUTION => 'Distribution',
            ],
        ]);
    }

    public function dismiss(Request $request, RecommendedAction $action): RedirectResponse
    {
        $this->authorizeWorkspace($request, $action);

        $action->forceFill([
            'status' => RecommendedAction::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ])->save();

        return back()->with('status', 'Recommended action dismissed.');
    }

    private function authorizeWorkspace(Request $request, RecommendedAction $action): void
    {
        $workspaceIds = $request->user()?->organization?->workspaces()->pluck('workspaces.id')->all() ?? [];

        abort_unless(in_array($action->workspace_id, $workspaceIds, true), 404);
    }
}
