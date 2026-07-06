<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\PageIntelligence\GeneratePageIntelligenceReportArtifactJob;
use App\Models\MarketPackInstallation;
use App\Models\PageIntelligenceReport;
use App\Models\Workspace;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppPageIntelligenceReportController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        $reports = PageIntelligenceReport::query()
            ->where('workspace_id', $workspace->id)
            ->with('marketPack:id,key,name')
            ->latest('generated_at')
            ->paginate(15)
            ->withQueryString();

        return view('app.page-intelligence.reports.index', [
            'workspace' => $workspace,
            'workspaces' => $this->availableWorkspaces($request),
            'reports' => $reports,
            'reportTypes' => ReportBuilder::reportTypes(),
            'marketPacks' => $this->marketPacks($workspace),
        ]);
    }

    public function store(Request $request, ReportBuilder $builder): RedirectResponse
    {
        abort_unless($request->user()?->can('create', PageIntelligenceReport::class), 403);

        $workspace = $this->resolveWorkspace($request, $request->input('workspace'));
        $data = $request->validate([
            'workspace' => ['nullable', 'string'],
            'report_type' => ['required', 'string', Rule::in(array_keys(ReportBuilder::reportTypes()))],
            'market_pack_key' => ['nullable', 'string', 'max:120'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        try {
            $report = $builder->generate($workspace, (string) $data['report_type'], [
                'market_pack_key' => $data['market_pack_key'] ?? null,
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'idempotency_key' => $request->headers->get('Idempotency-Key') ?: $request->input('idempotency_key'),
            ], $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->withErrors(['market_pack_key' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.page-intelligence.reports.show', $report)
            ->with('status', 'Report generated.');
    }

    public function show(Request $request, PageIntelligenceReport $report): View
    {
        abort_unless($request->user()?->can('view', $report), 403);
        $report->load(['deliveries.recipientUser:id,name,email']);

        return view('app.page-intelligence.reports.show', [
            'report' => $report,
            'payload' => $report->payload_json ?? [],
            'export' => $request->boolean('export'),
        ]);
    }

    public function export(Request $request, PageIntelligenceReport $report): View
    {
        abort_unless($request->user()?->can('view', $report), 403);

        return view('app.page-intelligence.reports.export', [
            'report' => $report,
            'payload' => $report->payload_json ?? [],
        ]);
    }

    public function generateArtifact(Request $request, PageIntelligenceReport $report): RedirectResponse
    {
        abort_unless($request->user()?->can('generateArtifact', $report), 403);

        $queued = PageIntelligenceReport::query()
            ->whereKey($report->id)
            ->whereIn('artifact_status', [
                PageIntelligenceReport::ARTIFACT_STATUS_PENDING,
                PageIntelligenceReport::ARTIFACT_STATUS_FAILED,
            ])
            ->update([
                'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_GENERATING,
                'artifact_failed_at' => null,
                'artifact_error' => null,
                'updated_at' => now(),
            ]);

        if ($queued === 1) {
            GeneratePageIntelligenceReportArtifactJob::dispatch((string) $report->id);

            return back()->with('status', 'PDF artifact generation queued.');
        }

        return back()->with('status', 'PDF artifact generation is already queued or ready.');
    }

    public function downloadArtifact(Request $request, PageIntelligenceReport $report): BinaryFileResponse
    {
        abort_unless($request->user()?->can('download', $report), 403);
        abort_unless($report->artifact_status === PageIntelligenceReport::ARTIFACT_STATUS_READY, 404);
        abort_unless($report->artifact_storage_path && Storage::disk('local')->exists($report->artifact_storage_path), 404);

        return response()->download(
            Storage::disk('local')->path($report->artifact_storage_path),
            str($report->title)->slug()->append('-snapshot-'.$report->snapshot_version.'.pdf')->toString(),
            ['Content-Type' => 'application/pdf']
        );
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        $organizationId = $request->user()?->organization_id;
        $query = Workspace::query()->where('organization_id', $organizationId)->orderBy('created_at');
        $workspaceId = $preferredWorkspaceId ?: $request->query('workspace');

        if ($workspaceId) {
            $workspace = (clone $query)->whereKey($workspaceId)->first();
            if (! $workspace) {
                throw new AuthorizationException('Workspace is not available for this user.');
            }

            return $workspace;
        }

        return $query->firstOrFail();
    }

    private function availableWorkspaces(Request $request): Collection
    {
        return Workspace::query()
            ->where('organization_id', $request->user()?->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);
    }

    private function marketPacks(Workspace $workspace): Collection
    {
        return MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->with('marketPack:id,key,name')
            ->get()
            ->pluck('marketPack')
            ->filter()
            ->unique('key')
            ->values();
    }
}
