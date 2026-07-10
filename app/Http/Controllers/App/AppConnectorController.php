<?php

namespace App\Http\Controllers\App;

use App\Data\Reporting\MonetaryAggregate;
use App\Http\Controllers\Controller;
use App\Jobs\Connectors\CheckConnectorHealthJob;
use App\Jobs\Connectors\DiscoverConnectorDatasetsJob;
use App\Jobs\Connectors\SyncConnectorDatasetJob;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\NormalizationRun;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorAuditLogger;
use App\Services\DataConnectors\ConnectorBackfillService;
use App\Services\DataConnectors\ConnectorDriverManager;
use App\Services\DataConnectors\ConnectorFieldMappingPreparationService;
use App\Services\DataConnectors\ConnectorProviderKeyResolver;
use App\Services\DataConnectors\ConnectorProviderManifestService;
use App\Services\DataConnectors\ConnectorRateLimitService;
use App\Services\DataConnectors\ConnectorSyncScheduler;
use App\Services\DataConnectors\DataConnectorRegistry;
use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class AppConnectorController extends Controller
{
    public function index(Request $request, DataConnectorRegistry $registry): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);

        $this->authorize('viewAny', ConnectorAccount::class);

        $providers = ConnectorProvider::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->keyBy('provider_key');

        $accounts = ConnectorAccount::query()
            ->with(['provider', 'datasets', 'scopes'])
            ->forWorkspace($workspace)
            ->orderBy('provider_key')
            ->orderBy('account_name')
            ->get();

        return view('app.connectors.index', [
            'workspace' => $workspace,
            'providerDefinitions' => collect($registry->all()),
            'providers' => $providers,
            'accounts' => $accounts,
        ]);
    }

    public function connect(
        Request $request,
        string $provider,
        ConnectorDriverManager $drivers,
        ConnectorProviderKeyResolver $keys,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);

        $this->authorize('create', ConnectorAccount::class);

        $providerKey = $keys->resolve($provider);
        $authorization = $drivers->driver($providerKey)->authorize($workspace, $request->user());

        return redirect()->away($authorization->url);
    }

    public function callback(
        Request $request,
        string $provider,
        ConnectorDriverManager $drivers,
        ConnectorProviderKeyResolver $keys,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);

        if ($request->filled('error')) {
            return redirect()
                ->route('app.connectors.index', $this->workspaceRouteParams($workspace))
                ->withErrors(['connector' => 'Connector access was not granted: '.$request->query('error_description', $request->query('error'))]);
        }

        $code = trim((string) $request->query('code', ''));
        $state = trim((string) $request->query('state', ''));

        if ($code === '' || $state === '') {
            return redirect()
                ->route('app.connectors.index', $this->workspaceRouteParams($workspace))
                ->withErrors(['connector' => 'Connector authorization response was missing code or state.']);
        }

        try {
            $providerKey = $keys->resolve($provider);
            $result = $drivers->driver($providerKey)->callback($state, $code, $request->user());
        } catch (\Throwable $exception) {
            return redirect()
                ->route('app.connectors.index', $this->workspaceRouteParams($workspace))
                ->withErrors(['connector' => 'Connector authorization failed: '.$exception->getMessage()]);
        }

        return redirect()
            ->route('app.connectors.show', $result['account'])
            ->with('status', 'Connector connected.');
    }

    public function show(Request $request, ConnectorAccount $connectorAccount): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('view', $connectorAccount);

        $connectorAccount->load([
            'provider',
            'clientSite',
            'scopes' => fn ($query) => $query->orderBy('scope_type')->orderBy('scope'),
            'datasets' => fn ($query) => $query->orderBy('display_name'),
            'syncRuns' => fn ($query) => $query->latest('created_at')->limit(25),
            'healthEvents' => fn ($query) => $query->latest('occurred_at')->limit(10),
            'quotaBudgets' => fn ($query) => $query->orderBy('budget_type')->orderBy('connector_account_id'),
            'asyncReportJobs' => fn ($query) => $query->latest('created_at')->limit(10),
            'backfillRanges' => fn ($query) => $query->latest('created_at')->limit(10),
            'fieldMappingPreparations' => fn ($query) => $query->orderBy('object_key'),
            'webhookRegistration',
            'normalizationRuns' => fn ($query) => $query->latest('created_at')->limit(10),
        ]);

        $manifest = app(ConnectorProviderManifestService::class)->manifest($connectorAccount);

        return view('app.connectors.show', [
            'workspace' => $workspace,
            'account' => $connectorAccount,
            'manifest' => $manifest,
            'diagnostics' => $this->diagnosticsFor($connectorAccount),
        ]);
    }

    public function diagnostics(Request $request, ConnectorAccount $connectorAccount): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('view', $connectorAccount);

        $connectorAccount->load([
            'provider',
            'scopes' => fn ($query) => $query->orderBy('scope_type')->orderBy('scope'),
            'datasets' => fn ($query) => $query->orderBy('display_name'),
            'syncRuns' => fn ($query) => $query->latest('created_at')->limit(50),
            'healthEvents' => fn ($query) => $query->latest('occurred_at')->limit(25),
            'quotaBudgets' => fn ($query) => $query->orderBy('budget_type')->orderBy('connector_account_id'),
            'asyncReportJobs' => fn ($query) => $query->latest('created_at')->limit(25),
            'normalizationRuns' => fn ($query) => $query->with(['items' => fn ($items) => $items->latest('created_at')->limit(25)])->latest('created_at')->limit(25),
            'webhookRegistration',
        ]);

        return view('app.connectors.diagnostics', [
            'workspace' => $workspace,
            'account' => $connectorAccount,
            'diagnostics' => $this->diagnosticsFor($connectorAccount),
        ]);
    }

    public function reconnect(
        Request $request,
        ConnectorAccount $connectorAccount,
        ConnectorDriverManager $drivers,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorAccount);

        $authorization = $drivers->driver($connectorAccount->provider_key)
            ->authorize($workspace, $request->user(), $connectorAccount);

        return redirect()->away($authorization->url);
    }

    public function disconnect(
        Request $request,
        ConnectorAccount $connectorAccount,
        ConnectorDriverManager $drivers,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('delete', $connectorAccount);

        $drivers->driver($connectorAccount->provider_key)->disconnect($connectorAccount, $request->user());

        return redirect()
            ->route('app.connectors.index', $this->workspaceRouteParams($workspace))
            ->with('status', 'Connector disconnected.');
    }

    public function discover(Request $request, ConnectorAccount $connectorAccount, ConnectorAuditLogger $audit): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorAccount);

        DiscoverConnectorDatasetsJob::dispatch((string) $connectorAccount->id);

        $audit->record($connectorAccount, 'connector.dataset_discovery_requested', null, [
            'workspace_id' => $connectorAccount->workspace_id,
            'provider_key' => $connectorAccount->provider_key,
        ], $request->user(), $request);

        return back()->with('status', 'Dataset discovery queued.');
    }

    public function sync(Request $request, ConnectorAccount $connectorAccount, ConnectorAuditLogger $audit): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorAccount);

        $datasetIds = $connectorAccount->datasets()
            ->where('status', ConnectorDataset::STATUS_ACTIVE)
            ->orderBy('display_name')
            ->pluck('id');

        if ($datasetIds->isEmpty()) {
            DiscoverConnectorDatasetsJob::dispatch((string) $connectorAccount->id);

            return back()->with('status', 'Dataset discovery queued before manual sync.');
        }

        $datasetIds->each(fn (string $datasetId) => SyncConnectorDatasetJob::dispatch($datasetId, ConnectorSyncRun::TYPE_MANUAL));

        $audit->record($connectorAccount, 'connector.manual_sync_requested', null, [
            'workspace_id' => $connectorAccount->workspace_id,
            'provider_key' => $connectorAccount->provider_key,
            'dataset_count' => $datasetIds->count(),
        ], $request->user(), $request);

        return back()->with('status', 'Manual sync queued.');
    }

    public function healthCheck(Request $request, ConnectorAccount $connectorAccount): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorAccount);

        CheckConnectorHealthJob::dispatch((string) $connectorAccount->id);

        return back()->with('status', 'Health check queued.');
    }

    public function normalize(
        Request $request,
        ConnectorAccount $connectorAccount,
        ConnectorNormalizationService $normalization,
        ConnectorAuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorAccount);

        $run = $normalization->enqueueForAccount($connectorAccount, 'manual');

        $audit->record($connectorAccount, 'connector.normalization_requested', null, [
            'workspace_id' => $connectorAccount->workspace_id,
            'provider_key' => $connectorAccount->provider_key,
            'normalization_run_id' => $run->id,
        ], $request->user(), $request);

        return back()->with('status', 'Normalization queued.');
    }

    public function retryNormalization(
        Request $request,
        NormalizationRun $normalizationRun,
        ConnectorNormalizationService $normalization,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $normalizationRun->workspace_id === (string) $workspace->id, 404);

        $normalizationRun->loadMissing('account');
        abort_unless($normalizationRun->account instanceof ConnectorAccount, 404);

        $this->authorize('update', $normalizationRun->account);
        abort_unless($normalizationRun->status === NormalizationRun::STATUS_FAILED, 404);

        $normalization->retry($normalizationRun);

        return back()->with('status', 'Normalization retry queued.');
    }

    public function backfill(
        Request $request,
        ConnectorDataset $connectorDataset,
        ConnectorBackfillService $backfills,
        ConnectorAuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorDataset->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorDataset);

        $maxChunkDays = max(1, (int) config('data_connectors.backfills.max_chunk_days', 30));

        $validated = $request->validate([
            'range_start' => ['required', 'date'],
            'range_end' => ['required', 'date', 'after_or_equal:range_start'],
            'chunk_days' => ['nullable', 'integer', 'min:1', 'max:'.$maxChunkDays],
        ]);

        try {
            $ranges = $backfills->request(
                dataset: $connectorDataset,
                start: $validated['range_start'],
                end: $validated['range_end'],
                requestedBy: $request->user(),
                chunkDays: isset($validated['chunk_days']) ? (int) $validated['chunk_days'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'range_end' => $exception->getMessage(),
            ]);
        }

        $audit->record($connectorDataset, 'connector.backfill_requested', null, [
            'workspace_id' => $connectorDataset->workspace_id,
            'provider_key' => $connectorDataset->provider_key,
            'range_start' => $validated['range_start'],
            'range_end' => $validated['range_end'],
            'range_count' => $ranges->count(),
        ], $request->user(), $request);

        return back()->with('status', 'Backfill queued for '.$ranges->count().' range(s).');
    }

    public function retryBackfills(
        Request $request,
        ConnectorDataset $connectorDataset,
        ConnectorBackfillService $backfills,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorDataset->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorDataset);

        $ranges = $backfills->retryFailed($connectorDataset);

        return back()->with('status', 'Retry queued for '.$ranges->count().' failed backfill range(s).');
    }

    public function fieldMapping(Request $request, ConnectorAccount $connectorAccount): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('view', $connectorAccount);

        $connectorAccount->load([
            'provider',
            'datasets' => fn ($query) => $query->orderBy('display_name'),
            'fieldMappingPreparations' => fn ($query) => $query->orderBy('object_key'),
        ]);

        return view('app.connectors.field-mapping', [
            'workspace' => $workspace,
            'account' => $connectorAccount,
        ]);
    }

    public function prepareFieldMapping(
        Request $request,
        ConnectorAccount $connectorAccount,
        ConnectorFieldMappingPreparationService $fieldMappings,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorAccount);

        $count = $fieldMappings->prepare($connectorAccount);

        return back()->with('status', 'Field mapping prep refreshed for '.$count.' object(s).');
    }

    public function enableDataset(
        Request $request,
        ConnectorDataset $connectorDataset,
        ConnectorSyncScheduler $scheduler,
        ConnectorAuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorDataset->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorDataset);

        $before = $connectorDataset->attributesToArray();
        $frequency = $request->validate([
            'sync_frequency' => ['nullable', 'in:hourly,daily,weekly,manual'],
        ])['sync_frequency'] ?? $connectorDataset->sync_frequency ?? 'daily';

        $connectorDataset->forceFill([
            'status' => ConnectorDataset::STATUS_ACTIVE,
            'sync_frequency' => $frequency,
            'deactivated_at' => null,
        ])->save();

        if ($frequency !== 'manual') {
            $scheduler->scheduleNext($connectorDataset->fresh(), now());
        }

        $audit->record($connectorDataset, 'connector.dataset_enabled', $before, $connectorDataset->fresh()->attributesToArray(), $request->user(), $request);

        return back()->with('status', 'Dataset enabled.');
    }

    public function disableDataset(
        Request $request,
        ConnectorDataset $connectorDataset,
        ConnectorAuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorDataset->workspace_id === (string) $workspace->id, 404);

        $this->authorize('update', $connectorDataset);

        $before = $connectorDataset->attributesToArray();
        $connectorDataset->forceFill([
            'status' => ConnectorDataset::STATUS_DISABLED,
            'next_sync_at' => null,
            'deactivated_at' => now(),
        ])->save();

        $audit->record($connectorDataset, 'connector.dataset_disabled', $before, $connectorDataset->fresh()->attributesToArray(), $request->user(), $request);

        return back()->with('status', 'Dataset disabled.');
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $impersonatedWorkspaceId = (string) $request->session()->get('impersonated_workspace_id', '');
        if ($impersonatedWorkspaceId !== '') {
            return Workspace::query()
                ->whereKey($impersonatedWorkspaceId)
                ->where('organization_id', $request->user()->organization_id)
                ->first();
        }

        $workspaceId = (string) $request->query('workspace_id', '');
        if ($workspaceId !== '') {
            return Workspace::query()
                ->whereKey($workspaceId)
                ->where('organization_id', $request->user()->organization_id)
                ->first();
        }

        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticsFor(ConnectorAccount $account): array
    {
        $token = $account->tokens()
            ->latest('created_at')
            ->first();

        $lastRun = $account->syncRuns()
            ->latest('created_at')
            ->first();

        return [
            'oauth_status' => $account->status,
            'token_valid' => $token !== null && $token->revoked_at === null && ($token->expires_at === null || $token->expires_at->isFuture()),
            'token_expires_at' => $token?->expires_at,
            'has_refresh_token' => $token !== null && trim((string) $token->refresh_token) !== '',
            'scopes' => $account->scopes->pluck('scope')->unique()->values()->all(),
            'rate_limit' => $account->rate_limit_json ?? [],
            'quota' => app(ConnectorRateLimitService::class)->snapshot($account),
            'last_api_call_at' => $account->last_api_call_at,
            'last_error' => $account->last_error ?: $lastRun?->error_message,
            'last_sync_duration_ms' => $lastRun?->duration_ms,
            'health_score' => $account->health_score,
            'workspace_reporting_timezone' => $this->workspaceReportingTimezone($account),
            'raw_records' => DB::table('connector_raw_records')->where('connector_account_id', $account->id)->count(),
            'observations' => DB::table('marketing_observations')->where('connector_account_id', $account->id)->count(),
            'async_report_jobs' => DB::table('connector_async_report_jobs')->where('connector_account_id', $account->id)->count(),
            'backfill_ranges' => DB::table('connector_backfill_ranges')->where('connector_account_id', $account->id)->count(),
            'webhook_status' => $account->webhookRegistration?->status,
            'normalization' => $this->normalizationDiagnosticsFor($account),
            'currency' => $this->currencyDiagnosticsFor($account),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizationDiagnosticsFor(ConnectorAccount $account): array
    {
        $lastRun = $account->normalizationRuns()
            ->latest('created_at')
            ->first();

        $runIds = $account->normalizationRuns()->pluck('id');

        $normalizedCounts = [
            'marketing_accounts' => DB::table('connector_normalized_marketing_accounts')->where('connector_account_id', $account->id)->count(),
            'campaigns' => DB::table('connector_normalized_campaigns')->where('connector_account_id', $account->id)->count(),
            'ad_groups' => DB::table('connector_normalized_ad_groups')->where('connector_account_id', $account->id)->count(),
            'ads' => DB::table('connector_normalized_ads')->where('connector_account_id', $account->id)->count(),
            'daily_performance' => DB::table('connector_normalized_daily_performances')->where('connector_account_id', $account->id)->count(),
            'crm_companies' => DB::table('connector_normalized_crm_companies')->where('connector_account_id', $account->id)->count(),
            'crm_contacts' => DB::table('connector_normalized_crm_contacts')->where('connector_account_id', $account->id)->count(),
            'crm_deals' => DB::table('connector_normalized_crm_deals')->where('connector_account_id', $account->id)->count(),
            'crm_activities' => DB::table('connector_normalized_crm_activities')->where('connector_account_id', $account->id)->count(),
        ];

        $failedItems = $runIds->isEmpty()
            ? 0
            : DB::table('connector_normalization_run_items')
                ->whereIn('connector_normalization_run_id', $runIds)
                ->where('status', 'failed')
                ->count();

        $skippedItems = $runIds->isEmpty()
            ? 0
            : DB::table('connector_normalization_run_items')
                ->whereIn('connector_normalization_run_id', $runIds)
                ->where('status', 'skipped')
                ->count();

        $datasetCoverage = $account->datasets()
            ->orderBy('display_name')
            ->get()
            ->map(fn (ConnectorDataset $dataset): array => [
                'dataset_key' => $dataset->dataset_key,
                'display_name' => $dataset->display_name,
                'raw_records' => DB::table('connector_raw_records')->where('connector_dataset_id', $dataset->id)->count(),
                'normalization_runs' => DB::table('connector_normalization_runs')->where('connector_dataset_id', $dataset->id)->count(),
                'last_normalized_at' => DB::table('connector_normalization_runs')
                    ->where('connector_dataset_id', $dataset->id)
                    ->whereIn('status', [NormalizationRun::STATUS_COMPLETED, NormalizationRun::STATUS_FAILED, NormalizationRun::STATUS_SKIPPED])
                    ->latest('finished_at')
                    ->value('finished_at'),
            ])
            ->values()
            ->all();

        return [
            'last_run' => $lastRun,
            'last_normalization_at' => $lastRun?->finished_at,
            'records_processed' => (int) ($lastRun?->records_processed ?? 0),
            'records_written' => (int) ($lastRun?->records_written ?? 0),
            'records_failed' => (int) ($lastRun?->records_failed ?? 0),
            'latest_error' => $lastRun?->latest_error,
            'normalized_counts' => $normalizedCounts,
            'failed_mapper_items' => $failedItems,
            'skipped_items' => $skippedItems,
            'provider_dataset_coverage' => $datasetCoverage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currencyDiagnosticsFor(ConnectorAccount $account): array
    {
        $rows = DB::table('connector_normalized_daily_performances')
            ->where('connector_account_id', $account->id)
            ->get([
                'cost',
                'original_cost',
                'original_currency',
                'reporting_cost',
                'reporting_currency',
            ])
            ->map(fn (object $row): array => [
                'amount' => is_numeric($row->original_cost) ? (float) $row->original_cost : (is_numeric($row->cost) ? (float) $row->cost : null),
                'currency' => $row->original_currency,
                'reporting_amount' => is_numeric($row->reporting_cost) ? (float) $row->reporting_cost : null,
                'reporting_currency' => $row->reporting_currency,
            ])
            ->all();

        $spend = MonetaryAggregate::fromRows($rows);

        return [
            'status' => $spend->status,
            'currencies_represented' => $spend->currenciesRepresented(),
            'conversion_coverage' => $spend->conversionCoverage,
            'spend' => $spend->toArray(),
            'warnings' => $spend->warnings,
        ];
    }

    private function workspaceReportingTimezone(ConnectorAccount $account): string
    {
        $workspace = $account->relationLoaded('workspace')
            ? $account->workspace
            : Workspace::query()->find((string) $account->workspace_id);

        return $workspace?->reportingTimezone() ?? Workspace::defaultReportingTimezone();
    }

    /**
     * @return array<string, string>
     */
    private function workspaceRouteParams(Workspace $workspace): array
    {
        return ['workspace_id' => (string) $workspace->id];
    }
}
