<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\RunContentNetworkAnalysisRequest;
use App\Jobs\ContentNetwork\AnalyzeContentNetworkJob;
use App\Models\Content;
use App\Models\ContentCluster;
use App\Models\LinkOpportunity;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use App\Support\FeatureFlags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppContentNetworkController extends Controller
{
    public function index(Request $request, FeatureGate $featureGate, FeatureFlags $featureFlags): View
    {
        if (! $featureFlags->isEnabled('content_network_analysis')) {
            abort(404);
        }

        $organizationId = (int) $request->user()->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->get(['id', 'name', 'display_name', 'organization_id', 'visual_settings']);

        if ($workspaces->isEmpty()) {
            abort(404);
        }

        $workspaceId = trim((string) $request->query('workspace_id', ''));
        $workspace = $workspaceId !== ''
            ? $workspaces->firstWhere('id', $workspaceId)
            : $workspaces->first();

        if (! $workspace) {
            abort(404);
        }

        $this->authorize('viewContentNetwork', $workspace);
        $this->assertFeatureEnabled($featureGate, $workspace);

        $clusters = ContentCluster::query()
            ->where('workspace_id', $workspace->id)
            ->with(['pillarContent:id,title,published_url'])
            ->orderByDesc('cluster_score')
            ->orderBy('name')
            ->get();

        $opportunities = LinkOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'sourceContent:id,title,published_url',
                'targetContent:id,title,published_url',
            ])
            ->orderByDesc('relevance_score')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $summary = is_array(data_get($workspace->visual_settings, 'content_network'))
            ? (array) data_get($workspace->visual_settings, 'content_network')
            : [];

        $gaps = is_array($summary['gaps'] ?? null) ? $summary['gaps'] : [];
        $orphanIds = collect((array) ($summary['orphan_content_ids'] ?? []))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values()
            ->all();
        $weakIds = collect((array) ($summary['weakly_connected_content_ids'] ?? []))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values()
            ->all();

        $orphanContent = Content::query()
            ->whereIn('id', $orphanIds)
            ->orderBy('title')
            ->get(['id', 'title', 'published_url']);

        $weakContent = Content::query()
            ->whereIn('id', $weakIds)
            ->orderBy('title')
            ->get(['id', 'title', 'published_url']);

        return view('app.content-network.index', [
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'clusters' => $clusters,
            'opportunities' => $opportunities,
            'summary' => $summary,
            'gaps' => $gaps,
            'orphanContent' => $orphanContent,
            'weakContent' => $weakContent,
            'canRun' => $request->user()?->can('runContentNetworkAnalysis', $workspace) ?? false,
        ]);
    }

    public function run(
        RunContentNetworkAnalysisRequest $request,
        Workspace $workspace,
        FeatureGate $featureGate,
        FeatureFlags $featureFlags
    ): RedirectResponse {
        if (! $featureFlags->isEnabled('content_network_analysis')) {
            abort(404);
        }

        $this->authorize('runContentNetworkAnalysis', $workspace);
        $this->assertWorkspaceInUserOrganization($workspace, (int) $request->user()->organization_id);
        $this->assertFeatureEnabled($featureGate, $workspace);

        AnalyzeContentNetworkJob::dispatch(
            workspaceId: (string) $workspace->id,
            force: (bool) $request->boolean('force'),
            requestedBy: (int) $request->user()->id,
        )
            ->onQueue((string) config('content_network.queue', 'content-network'))
            ->afterCommit();

        return redirect()
            ->route('app.content-network.index', ['workspace_id' => (string) $workspace->id])
            ->with('status', $request->boolean('force')
                ? 'Content network analysis rerun queued.'
                : 'Content network analysis queued.');
    }

    private function assertWorkspaceInUserOrganization(Workspace $workspace, int $organizationId): void
    {
        if ((int) $workspace->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function assertFeatureEnabled(FeatureGate $featureGate, Workspace $workspace): void
    {
        if (! $this->toBool($featureGate->value($workspace, 'content_network_analysis_enabled', false), false)) {
            abort(403, 'Content network analysis is not enabled for this workspace.');
        }
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
