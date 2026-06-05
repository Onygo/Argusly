<?php

namespace App\Actions\Research;

use App\Enums\ResearchProjectStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ResearchProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use App\Services\Research\SourceIngestionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateResearchProjectAction
{
    public function __construct(
        private readonly FeatureGate $featureGate,
        private readonly SourceIngestionService $ingestion,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function execute(User $user, array $payload): ResearchProject
    {
        $brief = $this->resolveBrief($payload['brief_id'] ?? null);
        $site = $this->resolveSite($payload['client_site_id'] ?? null);
        $requestedWorkspace = $this->resolveWorkspaceByInput($user, $payload['workspace_id'] ?? null);

        if ($site && (int) ($site->workspace?->organization_id ?? 0) !== (int) $user->organization_id) {
            throw new RuntimeException('Selected site does not belong to your organization.');
        }

        if ($brief && (int) ($brief->clientSite?->workspace?->organization_id ?? 0) !== (int) $user->organization_id) {
            throw new RuntimeException('Selected brief does not belong to your organization.');
        }

        if (! $site && $brief?->client_site_id) {
            $site = $brief->clientSite;
        }

        $workspace = $this->resolveWorkspace($user, $requestedWorkspace, $site, $brief);

        $this->assertResearchEnabled($workspace);

        if ($site && (string) $site->workspace_id !== (string) $workspace->id) {
            throw new RuntimeException('Selected site does not belong to the active workspace.');
        }

        if ($brief) {
            $briefWorkspaceId = (string) ($brief->clientSite?->workspace_id ?? '');
            if ($briefWorkspaceId === '' || $briefWorkspaceId !== (string) $workspace->id) {
                throw new RuntimeException('Selected brief does not belong to the active workspace.');
            }
        }

        $sourceUrls = $this->normalizeList($payload['source_urls'] ?? []);
        if ($sourceUrls === []) {
            throw new RuntimeException('At least one source URL is required.');
        }

        $maxSources = $this->resolveMaxSources($workspace);
        if (count($sourceUrls) > $maxSources) {
            throw new RuntimeException(sprintf('Maximum %d sources are allowed for this workspace.', $maxSources));
        }

        $keywords = $this->normalizeList($payload['target_keywords'] ?? []);

        $billingEnabled = $this->toBool(
            $this->featureGate->value(
                $workspace,
                'research_billing_enabled',
                (bool) config('research.billing.enabled_by_default', false)
            ),
            (bool) config('research.billing.enabled_by_default', false)
        );
        $creditsPerSource = max(
            0,
            (int) $this->featureGate->value(
                $workspace,
                'research_credits_per_source',
                (int) config('research.billing.credits_per_source', 1)
            )
        );

        if (! $site) {
            $billingEnabled = false;
            $creditsPerSource = 0;
        }

        return DB::transaction(function () use (
            $workspace,
            $user,
            $brief,
            $site,
            $payload,
            $keywords,
            $sourceUrls,
            $maxSources,
            $billingEnabled,
            $creditsPerSource,
        ): ResearchProject {
            $project = ResearchProject::query()->create([
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
                'brief_id' => $brief?->id,
                'client_site_id' => $site?->id,
                'name' => trim((string) ($payload['name'] ?? 'Research project')),
                'status' => ResearchProjectStatus::DRAFT,
                'target_keywords' => $keywords,
                'config' => [
                    'limits' => [
                        'max_sources' => $maxSources,
                    ],
                    'billing' => [
                        'enabled' => $billingEnabled,
                        'credits_per_source' => $creditsPerSource,
                    ],
                    'created_from' => 'app.research.create',
                    'workspace_id' => (string) $workspace->id,
                ],
            ]);

            $this->ingestion->syncSourcesFromUrls($project, $sourceUrls);

            return $project->fresh(['sources']);
        });
    }

    private function resolveWorkspace(User $user, ?Workspace $requestedWorkspace, ?ClientSite $site, ?Brief $brief): Workspace
    {
        if ($requestedWorkspace) {
            return $requestedWorkspace;
        }

        if ($site?->workspace) {
            return $site->workspace;
        }

        if ($brief?->clientSite?->workspace) {
            return $brief->clientSite->workspace;
        }

        $workspace = Workspace::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            throw new RuntimeException('No workspace available for this organization.');
        }

        return $workspace;
    }

    private function resolveWorkspaceByInput(User $user, mixed $workspaceId): ?Workspace
    {
        $id = trim((string) $workspaceId);
        if ($id === '') {
            return null;
        }

        $workspace = Workspace::query()
            ->where('id', $id)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $workspace) {
            throw new RuntimeException('Selected workspace is not available in your organization.');
        }

        return $workspace;
    }

    private function resolveBrief(mixed $briefId): ?Brief
    {
        $id = trim((string) $briefId);
        if ($id === '') {
            return null;
        }

        return Brief::query()->with('clientSite.workspace')->find($id);
    }

    private function resolveSite(mixed $siteId): ?ClientSite
    {
        $id = trim((string) $siteId);
        if ($id === '') {
            return null;
        }

        return ClientSite::query()->with('workspace')->find($id);
    }

    private function assertResearchEnabled(Workspace $workspace): void
    {
        if (! $this->toBool($this->featureGate->value($workspace, 'research_enabled', false), false)) {
            throw new AuthorizationException('Research is not enabled for this workspace.');
        }
    }

    private function resolveMaxSources(Workspace $workspace): int
    {
        $fallback = 20;
        $value = $this->featureGate->value($workspace, 'research_max_sources_per_project', $fallback);

        if (is_numeric($value)) {
            return max(1, min(200, (int) $value));
        }

        return $fallback;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\n,;]+/', $value) ?: [];
        }

        return collect((array) $value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
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

        $normalized = strtolower(trim((string) $value));

        return ! in_array($normalized, ['', '0', 'false', 'off', 'no'], true);
    }
}
