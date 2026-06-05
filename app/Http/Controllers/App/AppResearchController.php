<?php

namespace App\Http\Controllers\App;

use App\Actions\Research\CreateResearchProjectAction;
use App\Actions\Research\StartResearchProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\CreateResearchProjectRequest;
use App\Http\Requests\App\StartResearchProjectRequest;
use App\Http\Requests\App\UpdateResearchFindingSelectionRequest;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ResearchProject;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use App\Services\Research\ResearchSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class AppResearchController extends Controller
{
    public function index(Request $request, FeatureGate $featureGate): View
    {
        $workspace = $this->resolveWorkspace($request);
        $this->authorize('viewAny', ResearchProject::class);
        $this->assertResearchEnabled($featureGate, $workspace);

        $projects = ResearchProject::query()
            ->where('workspace_id', $workspace->id)
            ->with(['brief:id,title', 'clientSite:id,name'])
            ->withCount(['sources', 'findings'])
            ->latest('created_at')
            ->paginate(20);

        return view('app.research.index', [
            'workspace' => $workspace,
            'projects' => $projects,
            'canCreate' => $request->user()?->can('create', ResearchProject::class) ?? false,
        ]);
    }

    public function create(Request $request, FeatureGate $featureGate): View
    {
        $workspace = $this->resolveWorkspace($request);
        $this->authorize('create', ResearchProject::class);
        $this->assertResearchEnabled($featureGate, $workspace);

        $briefs = Brief::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'title', 'client_site_id', 'created_at']);

        $sites = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $maxSources = $this->resolveMaxSources($featureGate, $workspace);

        return view('app.research.create', [
            'workspace' => $workspace,
            'briefs' => $briefs,
            'sites' => $sites,
            'maxSources' => $maxSources,
        ]);
    }

    public function store(
        CreateResearchProjectRequest $request,
        CreateResearchProjectAction $createAction
    ): RedirectResponse {
        try {
            $project = $createAction->execute($request->user(), $request->validated());
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['research' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.research.show', $project)
            ->with('status', 'Research project created.');
    }

    public function show(Request $request, ResearchProject $project, FeatureGate $featureGate): View
    {
        $this->authorize('view', $project);

        $project->load([
            'workspace',
            'brief:id,title',
            'clientSite:id,name',
            'creator:id,name',
            'sources' => fn ($query) => $query->orderBy('created_at'),
            'findings' => fn ($query) => $query->orderBy('finding_type')->orderByDesc('confidence_score'),
        ]);

        $this->assertResearchEnabled($featureGate, $project->workspace);

        $groups = collect($project->findings)
            ->groupBy(fn ($finding): string => (string) ($finding->finding_type?->value ?? $finding->finding_type));

        $orderedGroups = [
            'insight' => $groups->get('insight', collect()),
            'statistic' => $groups->get('statistic', collect()),
            'quote' => $groups->get('quote', collect()),
            'entity' => $groups->get('entity', collect()),
            'question' => $groups->get('question', collect()),
        ];

        return view('app.research.show', [
            'project' => $project,
            'findingGroups' => $orderedGroups,
            'selectedFindings' => $project->findings->where('is_selected', true)->values(),
            'canRun' => $request->user()?->can('run', $project) ?? false,
        ]);
    }

    public function start(
        StartResearchProjectRequest $request,
        ResearchProject $project,
        StartResearchProjectAction $startAction
    ): RedirectResponse {
        $this->authorize('run', $project);

        try {
            $startAction->execute($project, $request->user(), (bool) $request->boolean('force'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['research' => $exception->getMessage()]);
        }

        return back()->with('status', $request->boolean('force')
            ? 'Research rerun queued.'
            : 'Research run queued.');
    }

    public function updateSelectedFindings(
        UpdateResearchFindingSelectionRequest $request,
        ResearchProject $project,
        ResearchSummaryService $summaryService
    ): RedirectResponse {
        $this->authorize('run', $project);

        $selected = collect((array) ($request->validated()['selected_finding_ids'] ?? []))
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        DB::transaction(function () use ($project, $selected): void {
            $project->findings()->update(['is_selected' => false]);

            if ($selected->isNotEmpty()) {
                $project->findings()
                    ->whereIn('id', $selected->all())
                    ->update(['is_selected' => true]);
            }
        });

        $summaryService->persistSummary($project->fresh());

        return back()->with('status', 'Selected findings updated.');
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = trim((string) $request->query('workspace_id', ''));

        $query = Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('created_at');

        if ($workspaceId !== '') {
            $workspace = (clone $query)->where('id', $workspaceId)->first();
            if ($workspace) {
                return $workspace;
            }
        }

        $workspace = $query->first();

        if (! $workspace) {
            abort(404);
        }

        return $workspace;
    }

    private function assertResearchEnabled(FeatureGate $featureGate, Workspace $workspace): void
    {
        if (! $this->toBool($featureGate->value($workspace, 'research_enabled', false), false)) {
            abort(403, 'Research is not enabled for this workspace.');
        }
    }

    private function resolveMaxSources(FeatureGate $featureGate, Workspace $workspace): int
    {
        $value = $featureGate->value($workspace, 'research_max_sources_per_project', 20);

        return is_numeric($value)
            ? max(1, min(200, (int) $value))
            : 20;
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
