<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\LearningOptimization\RunLearningOptimizationJob;
use App\Models\CampaignLearningProfile;
use App\Models\ContentLearningProfile;
use App\Models\LearningRecommendation;
use App\Models\Workspace;
use App\Services\LearningOptimization\LearningOptimizationEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppLearningOptimizationController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        $contentProfiles = ContentLearningProfile::query()
            ->where('workspace_id', $workspace->id)
            ->with('content:id,title,workspace_id,primary_keyword,content_health_score,ai_visibility_score')
            ->orderByDesc('performance_score')
            ->limit(20)
            ->get();

        $campaignProfiles = CampaignLearningProfile::query()
            ->where('workspace_id', $workspace->id)
            ->with('campaign:id,name,workspace_id,status,approval_status')
            ->orderByDesc('performance_score')
            ->limit(12)
            ->get();

        $recommendations = LearningRecommendation::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'proposed')
            ->with(['content:id,title,workspace_id', 'campaign:id,name,workspace_id'])
            ->orderByDesc('priority_score')
            ->limit(30)
            ->get();

        $aiVisibility = ContentLearningProfile::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('ai_visibility_score')
            ->limit(12)
            ->get(['content_id', 'primary_topic', 'ai_visibility_score', 'ai_visibility_trend']);

        $topicPerformance = ContentLearningProfile::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('primary_topic')
            ->get(['primary_topic', 'performance_score', 'topic_score', 'linkedin_score', 'ai_visibility_score'])
            ->groupBy('primary_topic')
            ->map(fn ($profiles, string $topic): array => [
                'topic' => $topic,
                'avg_performance' => round((float) $profiles->avg('performance_score'), 1),
                'avg_topic' => round((float) $profiles->avg('topic_score'), 1),
                'avg_linkedin' => round((float) $profiles->avg('linkedin_score'), 1),
                'avg_ai_visibility' => round((float) $profiles->avg('ai_visibility_score'), 1),
                'count' => $profiles->count(),
            ])
            ->sortByDesc('avg_performance')
            ->take(10)
            ->values();

        return view('app.learning-optimization.index', [
            'workspace' => $workspace,
            'contentProfiles' => $contentProfiles,
            'campaignProfiles' => $campaignProfiles,
            'recommendations' => $recommendations,
            'aiVisibility' => $aiVisibility,
            'topicPerformance' => $topicPerformance,
            'summary' => [
                'avg_content_score' => round((float) ContentLearningProfile::query()->where('workspace_id', $workspace->id)->avg('performance_score'), 1),
                'avg_campaign_score' => round((float) CampaignLearningProfile::query()->where('workspace_id', $workspace->id)->avg('performance_score'), 1),
                'recommendations' => LearningRecommendation::query()->where('workspace_id', $workspace->id)->where('status', 'proposed')->count(),
                'ai_visibility_avg' => round((float) ContentLearningProfile::query()->where('workspace_id', $workspace->id)->avg('ai_visibility_score'), 1),
            ],
        ]);
    }

    public function run(Request $request, LearningOptimizationEngine $engine): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);

        if ($request->boolean('run_inline')) {
            $result = $engine->run($workspace);

            return back()->with('status', sprintf(
                'Learning optimization refreshed: %d content profiles, %d campaign profiles, %d recommendation checks.',
                $result['content_profiles'],
                $result['campaign_profiles'],
                $result['recommendations']
            ));
        }

        RunLearningOptimizationJob::dispatch((string) $workspace->id)->afterCommit();

        return back()->with('status', 'Learning optimization refresh queued.');
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
