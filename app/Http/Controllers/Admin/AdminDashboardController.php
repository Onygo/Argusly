<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePluginReleaseRequest;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\OnboardingState;
use App\Models\PluginRelease;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Performance\PerformanceCacheService;
use App\Services\PluginUpdates\PluginArchiveUploadService;
use App\Services\PluginUpdates\PluginReleaseService;
use App\Services\Seo\SeoDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDashboardController extends Controller
{
    public function index(
        PluginReleaseService $pluginReleaseService,
        PerformanceCacheService $performanceCache,
        SeoDashboardService $seoDashboard
    ): View
    {
        $dashboardCounters = $performanceCache->rememberAdmin(
            'admin-dashboard-counters',
            [],
            now()->addSeconds(120),
            fn (): array => [
                'pending_organizations' => (int) Organization::query()->where('status', 'pending')->count(),
                'pending_users' => (int) User::query()->whereNull('approved_at')->where('is_admin', false)->count(),
                'orgs_on_hold' => (int) Organization::query()->where('status', 'on_hold')->count(),
            ]
        );

        $pendingOrganizations = (int) ($dashboardCounters['pending_organizations'] ?? 0);
        $pendingUsers = (int) ($dashboardCounters['pending_users'] ?? 0);
        $orgsOnHold = (int) ($dashboardCounters['orgs_on_hold'] ?? 0);

        $clientActivity = $this->resolveClientActivityMetrics();
        $activitySummaryCards = $this->buildMetricCards($clientActivity['summary']);
        $clientActivityCards = collect($clientActivity['clients'])
            ->map(function (array $client): array {
                $metrics = [
                    'briefs_count_30d' => (int) ($client['briefs_count_30d'] ?? 0),
                    'drafts_count_30d' => (int) ($client['drafts_count_30d'] ?? 0),
                    'delivery_failures_30d' => (int) ($client['delivery_failures_30d'] ?? 0),
                    'connector_status' => (string) ($client['connector_status'] ?? 'unknown'),
                ];
                $client['metric_cards'] = $this->buildMetricCards($metrics);

                return $client;
            })
            ->values()
            ->all();

        $latestWpPluginRelease = $pluginReleaseService->latestRelease();
        $pluginReleases = PluginRelease::query()
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $wordpressSites = ClientSite::query()
            ->with('workspace.organization')
            ->where(function ($query): void {
                $query
                    ->where('type', ClientSite::TYPE_WORDPRESS)
                    ->orWhere('connector_platform', 'wp');
            })
            ->orderByDesc('last_heartbeat_at')
            ->limit(40)
            ->get();

        $wpSiteVersions = $wordpressSites->map(function (ClientSite $site) use ($latestWpPluginRelease): array {
            $installedVersion = trim((string) ($site->connector_version ?: $site->plugin_version));
            $status = $this->versionStatus(
                $installedVersion,
                $latestWpPluginRelease?->version
            );

            return [
                'site' => $site,
                'installed_version' => $installedVersion !== '' ? $installedVersion : null,
                'status' => $status,
            ];
        });

        $onboarding = $performanceCache->rememberAdmin(
            'admin-dashboard-onboarding',
            [],
            now()->addSeconds(180),
            function (): array {
                $default = [
                    'new_registrations_7d' => 0,
                    'activated_7d' => 0,
                    'avg_minutes_to_first_value' => null,
                    'phase_rows' => collect(),
                ];

                if (! Schema::hasTable('onboarding_states')) {
                    return $default;
                }

                $summary = OnboardingState::query()
                    ->join('users', 'users.id', '=', 'onboarding_states.user_id')
                    ->where('users.is_admin', false)
                    ->selectRaw('SUM(CASE WHEN registered_at >= ? THEN 1 ELSE 0 END) as new_registrations_7d', [now()->subDays(7)])
                    ->selectRaw('SUM(CASE WHEN first_value_at IS NOT NULL AND first_value_at >= ? THEN 1 ELSE 0 END) as activated_7d', [now()->subDays(7)])
                    ->first();

                $rowsWithFirstValue = OnboardingState::query()
                    ->whereHas('user', fn ($q) => $q->where('is_admin', false))
                    ->whereNotNull('first_value_at')
                    ->whereNotNull('registered_at')
                    ->limit(500)
                    ->get(['registered_at', 'first_value_at']);

                return [
                    'new_registrations_7d' => (int) ($summary->new_registrations_7d ?? 0),
                    'activated_7d' => (int) ($summary->activated_7d ?? 0),
                    'avg_minutes_to_first_value' => $rowsWithFirstValue->isNotEmpty()
                        ? (int) round($rowsWithFirstValue
                            ->map(fn (OnboardingState $row) => $row->registered_at?->diffInMinutes($row->first_value_at) ?? 0)
                            ->avg())
                        : null,
                    'phase_rows' => OnboardingState::query()
                        ->with('user:id,name,email')
                        ->whereHas('user', fn ($q) => $q->where('is_admin', false))
                        ->whereIn('phase', ['registered', 'verified', 'first_login', 'cold'])
                        ->latest('updated_at')
                        ->limit(50)
                        ->get(),
                ];
            }
        );

        return view('admin.dashboard', [
            'pendingOrganizations' => $pendingOrganizations,
            'pendingUsers' => $pendingUsers,
            'orgsOnHold' => $orgsOnHold,
            'activitySummary' => $clientActivity['summary'],
            'activitySummaryCards' => $activitySummaryCards,
            'clientActivityCards' => $clientActivityCards,
            'activityLabels' => [
                'last_activity_at' => $this->resolveMetricLabel('last_activity_at'),
            ],
            'onboarding' => $onboarding,
            'latestWpPluginRelease' => $latestWpPluginRelease,
            'pluginReleases' => $pluginReleases,
            'wpSiteVersions' => $wpSiteVersions,
            'seoDashboard' => $seoDashboard->summary(),
        ]);
    }

    public function storePluginRelease(
        StorePluginReleaseRequest $request,
        PluginArchiveUploadService $uploadService
    ): RedirectResponse {
        $data = $request->validated();
        $file = $request->file('archive');

        Log::info('PluginReleaseUpload: Starting upload process', [
            'version' => $data['version'],
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'user_id' => $request->user()?->id,
        ]);

        // Additional ZIP validation beyond basic file rules
        $archiveValidation = $uploadService->validateArchive($file);
        if (! $archiveValidation['valid']) {
            Log::warning('PluginReleaseUpload: Archive validation failed', [
                'error' => $archiveValidation['error'],
                'file_info' => $archiveValidation['file_info'],
            ]);

            return redirect()
                ->route('admin.dashboard')
                ->withErrors(['archive' => $archiveValidation['error']])
                ->withInput($request->except('archive'));
        }

        Log::info('PluginReleaseUpload: Archive validated', [
            'file_info' => $archiveValidation['file_info'],
        ]);

        // Store the archive and create the release
        $storeResult = $uploadService->storeArchive($file, $data);

        if (! $storeResult['success']) {
            return redirect()
                ->route('admin.dashboard')
                ->withErrors(['dashboard' => $storeResult['error']])
                ->withInput($request->except('archive'));
        }

        Log::info('PluginReleaseUpload: Upload completed successfully', [
            'release_id' => $storeResult['release']?->id,
            'version' => $storeResult['release']?->version,
            'storage_path' => $storeResult['storage_path'],
        ]);

        return redirect()
            ->route('admin.dashboard')
            ->with('status', 'WordPress plugin release uploaded.');
    }

    public function downloadPluginRelease(PluginRelease $release): StreamedResponse|RedirectResponse
    {
        Gate::authorize('admin-area-view-sites');

        $disk = (string) config('argusly.plugin_updates.disk', 'local');
        $path = trim((string) $release->zip_storage_path);

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return redirect()
                ->route('admin.dashboard')
                ->withErrors(['dashboard' => 'Plugin archive for this release is missing.']);
        }

        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return redirect()
                ->route('admin.dashboard')
                ->withErrors(['dashboard' => 'Unable to read plugin archive.']);
        }

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            'argusly-wordpress-plugin-' . $release->version . '.zip',
            ['Content-Type' => 'application/zip']
        );
    }

    public function destroyPluginRelease(
        PluginRelease $release,
        PluginReleaseService $pluginReleaseService
    ): RedirectResponse {
        Gate::authorize('admin-area-superadmin');

        $latestRelease = $pluginReleaseService->latestRelease();
        if ($latestRelease && (string) $latestRelease->id === (string) $release->id) {
            return redirect()
                ->route('admin.dashboard')
                ->withErrors(['dashboard' => 'The latest WordPress plugin release cannot be deleted. Upload or keep a newer release first.']);
        }

        $disk = (string) config('argusly.plugin_updates.disk', 'local');
        $path = trim((string) $release->zip_storage_path);
        $version = (string) $release->version;

        $release->delete();

        if (
            $path !== ''
            && ! PluginRelease::query()->where('zip_storage_path', $path)->exists()
            && Storage::disk($disk)->exists($path)
        ) {
            Storage::disk($disk)->delete($path);
        }

        Log::info('PluginRelease: deleted', [
            'version' => $version,
            'storage_path' => $path,
            'disk' => $disk,
            'user_id' => request()->user()?->id,
        ]);

        return redirect()
            ->route('admin.dashboard')
            ->with('status', 'WordPress plugin release deleted.');
    }

    /**
     * Diagnostic endpoint to check server upload configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadDiagnostics(PluginArchiveUploadService $uploadService): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('admin-area-superadmin');

        $serverLimits = $uploadService->getServerLimits();
        $disk = (string) config('argusly.plugin_updates.disk', 'local');

        // Check storage directory is writable
        $storageWritable = false;
        try {
            $testPath = 'plugin-releases/.upload-test-' . now()->timestamp;
            Storage::disk($disk)->put($testPath, 'test');
            Storage::disk($disk)->delete($testPath);
            $storageWritable = true;
        } catch (\Throwable) {
            $storageWritable = false;
        }

        return response()->json([
            'status' => 'ok',
            'server_limits' => $serverLimits,
            'storage' => [
                'disk' => $disk,
                'writable' => $storageWritable,
            ],
            'recommendations' => $this->getUploadRecommendations($serverLimits),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param array{upload_max_filesize: string, post_max_size: string, memory_limit: string, max_input_time: string} $limits
     * @return array<string, string>
     */
    private function getUploadRecommendations(array $limits): array
    {
        $recommendations = [];

        $uploadMax = $this->parsePhpSize($limits['upload_max_filesize']);
        $postMax = $this->parsePhpSize($limits['post_max_size']);
        $targetSize = 64 * 1024 * 1024; // 64MB recommended

        if ($uploadMax < $targetSize) {
            $recommendations['upload_max_filesize'] = 'Increase to at least 64M in php.ini';
        }

        if ($postMax < $targetSize) {
            $recommendations['post_max_size'] = 'Increase to at least 64M in php.ini';
        }

        $recommendations['nginx_client_max_body_size'] = 'Ensure nginx client_max_body_size is at least 64M';

        return $recommendations;
    }

    private function parsePhpSize(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size,
        };
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, int|string> $metrics
     * @return array<int, array{key:string,label:string,value:int|string}>
     */
    private function buildMetricCards(array $metrics): array
    {
        return collect($metrics)
            ->map(fn (int|string $value, string $key): array => [
                'key' => $key,
                'label' => $this->resolveMetricLabel($key),
                'value' => $value,
            ])
            ->values()
            ->all();
    }

    private function resolveMetricLabel(string $key): string
    {
        $configured = config('admin_dashboard.metric_labels.' . $key);
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return Str::headline($key);
    }

    /**
     * @return array{
     *     summary: array{
     *         total_briefs_7d:int,
     *         total_briefs_30d:int,
     *         total_drafts_7d:int,
     *         total_drafts_30d:int,
     *         active_clients_30d:int
     *     },
     *     clients: array<int,array{
     *         workspace_id:string,
     *         workspace_name:string,
     *         organization_id:?int,
     *         organization_name:string,
     *         briefs_count_30d:int,
     *         drafts_count_30d:int,
     *         delivery_failures_30d:int,
     *         connector_status:string,
     *         last_activity_at:?string
     *     }>
     * }
     */
    private function resolveClientActivityMetrics(): array
    {
        return app(PerformanceCacheService::class)->rememberAdmin('admin.dashboard.client-metrics.v2', [], now()->addSeconds(300), function (): array {
            $cutoff7 = now()->subDays(7);
            $cutoff30 = now()->subDays(30);

            $briefTotals = DB::table('briefs')
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as total_briefs_7d', [$cutoff7])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as total_briefs_30d', [$cutoff30])
                ->first();

            $draftTotals = DB::table('drafts')
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as total_drafts_7d', [$cutoff7])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as total_drafts_30d', [$cutoff30])
                ->first();

            $briefsByWorkspace = DB::table('briefs as briefs')
                ->join('client_sites as sites', 'sites.id', '=', 'briefs.client_site_id')
                ->whereNotNull('sites.workspace_id')
                ->where('briefs.created_at', '>=', $cutoff30)
                ->groupBy('sites.workspace_id')
                ->select('sites.workspace_id')
                ->selectRaw('COUNT(*) as briefs_count_30d')
                ->selectRaw('MAX(briefs.created_at) as briefs_last_activity_at')
                ->get()
                ->keyBy('workspace_id');

            $draftsByWorkspace = DB::table('drafts as drafts')
                ->join('client_sites as sites', 'sites.id', '=', 'drafts.client_site_id')
                ->whereNotNull('sites.workspace_id')
                ->where('drafts.created_at', '>=', $cutoff30)
                ->groupBy('sites.workspace_id')
                ->select('sites.workspace_id')
                ->selectRaw('COUNT(*) as drafts_count_30d')
                ->selectRaw("SUM(CASE WHEN drafts.delivery_status = 'failed' THEN 1 ELSE 0 END) as delivery_failures_30d")
                ->selectRaw('MAX(drafts.created_at) as drafts_last_activity_at')
                ->get()
                ->keyBy('workspace_id');

            $workspaceIds = collect($briefsByWorkspace->keys()->all())
                ->merge($draftsByWorkspace->keys()->all())
                ->unique()
                ->values();

            $summary = [
                'total_briefs_7d' => (int) ($briefTotals->total_briefs_7d ?? 0),
                'total_briefs_30d' => (int) ($briefTotals->total_briefs_30d ?? 0),
                'total_drafts_7d' => (int) ($draftTotals->total_drafts_7d ?? 0),
                'total_drafts_30d' => (int) ($draftTotals->total_drafts_30d ?? 0),
                'active_clients_30d' => $workspaceIds->count(),
            ];

            if ($workspaceIds->isEmpty()) {
                return [
                    'summary' => $summary,
                    'clients' => [],
                ];
            }

            $workspaces = Workspace::query()
                ->with('organization:id,name')
                ->whereIn('id', $workspaceIds)
                ->get(['id', 'organization_id', 'name', 'display_name'])
                ->keyBy('id');

            $connectorByWorkspace = DB::table('client_sites')
                ->whereIn('workspace_id', $workspaceIds)
                ->whereNull('deleted_at')
                ->groupBy('workspace_id')
                ->select('workspace_id')
                ->selectRaw("MAX(CASE WHEN status = 'connected' THEN 1 ELSE 0 END) as has_connected")
                ->selectRaw("MAX(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as has_pending")
                ->selectRaw("MAX(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) as has_disabled")
                ->get()
                ->keyBy('workspace_id');

            $clients = $workspaceIds
                ->map(function (string $workspaceId) use ($workspaces, $briefsByWorkspace, $draftsByWorkspace, $connectorByWorkspace): ?array {
                    /** @var Workspace|null $workspace */
                    $workspace = $workspaces->get($workspaceId);
                    if (! $workspace) {
                        return null;
                    }

                    $briefRow = $briefsByWorkspace->get($workspaceId);
                    $draftRow = $draftsByWorkspace->get($workspaceId);
                    $connectorRow = $connectorByWorkspace->get($workspaceId);

                    $briefLast = $briefRow?->briefs_last_activity_at;
                    $draftLast = $draftRow?->drafts_last_activity_at;
                    $lastActivityRaw = max((string) ($briefLast ?? ''), (string) ($draftLast ?? ''));

                    return [
                        'workspace_id' => (string) $workspace->id,
                        'workspace_name' => (string) $workspace->display_name,
                        'organization_id' => $workspace->organization_id,
                        'organization_name' => (string) ($workspace->organization?->name ?? 'n/a'),
                        'briefs_count_30d' => (int) ($briefRow?->briefs_count_30d ?? 0),
                        'drafts_count_30d' => (int) ($draftRow?->drafts_count_30d ?? 0),
                        'delivery_failures_30d' => (int) ($draftRow?->delivery_failures_30d ?? 0),
                        'connector_status' => $this->resolveConnectorStatus($connectorRow),
                        'last_activity_at' => $lastActivityRaw !== '' ? substr($lastActivityRaw, 0, 10) : null,
                        '_last_activity_sort' => $lastActivityRaw,
                    ];
                })
                ->filter()
                ->sortByDesc('_last_activity_sort')
                ->map(function (array $row): array {
                    unset($row['_last_activity_sort']);

                    return $row;
                })
                ->values()
                ->all();

            return [
                'summary' => $summary,
                'clients' => $clients,
            ];
        });
    }

    private function resolveConnectorStatus(object|null $connectorRow): string
    {
        if (! $connectorRow) {
            return 'unknown';
        }

        if ((int) ($connectorRow->has_connected ?? 0) === 1) {
            return 'connected';
        }

        if ((int) ($connectorRow->has_pending ?? 0) === 1) {
            return 'pending';
        }

        if ((int) ($connectorRow->has_disabled ?? 0) === 1) {
            return 'disabled';
        }

        return 'unknown';
    }

    private function versionStatus(?string $installedVersion, ?string $latestVersion): string
    {
        $installedVersion = trim((string) $installedVersion);
        $latestVersion = trim((string) $latestVersion);

        if ($installedVersion === '') {
            return 'unknown';
        }

        if ($latestVersion === '') {
            return 'tracked';
        }

        if (version_compare($installedVersion, $latestVersion, '<')) {
            return 'outdated';
        }

        if (version_compare($installedVersion, $latestVersion, '>')) {
            return 'ahead';
        }

        return 'up_to_date';
    }
}
