<?php

namespace App\Jobs\ContentNetwork;

use App\Models\Workspace;
use App\Services\ContentChain\ChainedContentOpportunityService;
use App\Services\ContentNetwork\ContentGapDetector;
use App\Services\ContentNetwork\LinkGraphAnalyzer;
use App\Services\ContentNetwork\TopicClusterService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeContentNetworkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 240;

    public function __construct(
        public readonly string $workspaceId,
        public readonly bool $force = false,
        public readonly ?int $requestedBy = null,
        public readonly ?string $runKey = null,
    ) {
        $this->onQueue((string) config('content_network.queue', 'content-network'));
    }

    public function uniqueId(): string
    {
        return 'content-network:' . $this->workspaceId;
    }

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(
        TopicClusterService $topicClusterService,
        LinkGraphAnalyzer $linkGraphAnalyzer,
        ContentGapDetector $contentGapDetector,
        ChainedContentOpportunityService $chainedContentOpportunityService,
        FeatureGate $featureGate,
    ): void {
        $workspace = Workspace::query()->find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $runKey = $this->runKey ?: (string) Str::uuid();

        if (! $this->toBool($featureGate->value($workspace, 'content_network_analysis_enabled', false), false)) {
            $this->persistSummary($workspace, [
                'status' => 'skipped',
                'run_key' => $runKey,
                'reason' => 'Feature entitlement disabled',
                'updated_at' => now()->toIso8601String(),
            ]);

            return;
        }

        $this->persistSummary($workspace, [
            'status' => 'running',
            'run_key' => $runKey,
            'started_at' => now()->toIso8601String(),
            'requested_by' => $this->requestedBy,
            'force' => $this->force,
        ]);

        try {
            $clusterResult = $topicClusterService->buildAndPersist($workspace);
            $graphResult = $linkGraphAnalyzer->analyzeAndPersist($workspace, (array) ($clusterResult['content_signals'] ?? []));
            $gapResult = $contentGapDetector->detectAndPersist($workspace, $clusterResult, $graphResult);
            $suggestionCount = $chainedContentOpportunityService->refreshForWorkspace($workspace);

            $this->persistSummary($workspace, [
                'status' => 'completed',
                'run_key' => $runKey,
                'completed_at' => now()->toIso8601String(),
                'cluster_summary' => $clusterResult['summary'] ?? [],
                'weak_areas' => $clusterResult['weak_areas'] ?? [],
                'orphan_content_ids' => $graphResult['orphan_content_ids'] ?? [],
                'weakly_connected_content_ids' => $graphResult['weakly_connected_content_ids'] ?? [],
                'opportunities_count' => (int) ($graphResult['opportunities_count'] ?? 0),
                'chain_suggestions_refreshed' => $suggestionCount,
                'gaps' => $gapResult,
                'usage' => [
                    'mode' => 'zero-credit',
                    'metered' => false,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('Content network analysis failed', [
                'workspace_id' => $workspace->id,
                'run_key' => $runKey,
                'error' => $exception->getMessage(),
            ]);

            $this->persistSummary($workspace, [
                'status' => 'failed',
                'run_key' => $runKey,
                'failed_at' => now()->toIso8601String(),
                'failure_reason' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            throw $exception;
        }
    }

    private function persistSummary(Workspace $workspace, array $payload): void
    {
        $settings = is_array($workspace->visual_settings) ? $workspace->visual_settings : [];
        $existing = is_array($settings['content_network'] ?? null) ? $settings['content_network'] : [];

        $settings['content_network'] = array_replace_recursive($existing, $payload, [
            'updated_at' => now()->toIso8601String(),
        ]);

        $workspace->update([
            'visual_settings' => $settings,
        ]);
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
