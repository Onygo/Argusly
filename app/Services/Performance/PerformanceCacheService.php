<?php

namespace App\Services\Performance;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentTranslation;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

class PerformanceCacheService
{
    private const ORG_CONTENT_VERSION_PREFIX = 'perf:org:content:v1';
    private const ADMIN_DASHBOARD_VERSION_KEY = 'perf:admin:dashboard:v1';

    /**
     * @param  array<string,mixed>  $context
     */
    public function rememberOrganization(string $prefix, int $organizationId, array $context, mixed $ttl, callable $callback): mixed
    {
        $key = sprintf(
            '%s:%d:v%d:%s',
            $prefix,
            $organizationId,
            $this->organizationContentVersion($organizationId),
            md5((string) json_encode($context))
        );

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function rememberAdmin(string $prefix, array $context, mixed $ttl, callable $callback): mixed
    {
        $key = sprintf(
            '%s:v%d:%s',
            $prefix,
            $this->adminDashboardVersion(),
            md5((string) json_encode($context))
        );

        return Cache::remember($key, $ttl, $callback);
    }

    public function bustOrganizationContent(?int $organizationId): void
    {
        if (! $organizationId) {
            return;
        }

        $key = $this->organizationVersionKey($organizationId);
        Cache::forever($key, $this->organizationContentVersion($organizationId) + 1);
    }

    public function bustAdminDashboard(): void
    {
        Cache::forever(self::ADMIN_DASHBOARD_VERSION_KEY, $this->adminDashboardVersion() + 1);
    }

    public function bustForContent(Content $content): void
    {
        $organizationId = $this->organizationIdForContent($content);

        $this->bustOrganizationContent($organizationId);
        $this->bustAdminDashboard();
    }

    public function bustForPublication(ContentPublication $publication): void
    {
        $content = $publication->relationLoaded('content')
            ? $publication->content
            : $publication->content()->with('workspace:id,organization_id')->first();

        if ($content instanceof Content) {
            $this->bustForContent($content);

            return;
        }

        $site = $publication->relationLoaded('clientSite')
            ? $publication->clientSite
            : $publication->clientSite()->first(['id', 'workspace_id']);

        $this->bustOrganizationContent($this->organizationIdForSite($site));
        $this->bustAdminDashboard();
    }

    public function bustForTranslation(ContentTranslation $translation): void
    {
        $content = $translation->relationLoaded('content')
            ? $translation->content
            : $translation->content()->with('workspace:id,organization_id')->first();

        if ($content instanceof Content) {
            $this->bustForContent($content);
        }
    }

    private function organizationContentVersion(int $organizationId): int
    {
        return max(1, (int) Cache::get($this->organizationVersionKey($organizationId), 1));
    }

    private function adminDashboardVersion(): int
    {
        return max(1, (int) Cache::get(self::ADMIN_DASHBOARD_VERSION_KEY, 1));
    }

    private function organizationVersionKey(int $organizationId): string
    {
        return self::ORG_CONTENT_VERSION_PREFIX.':'.$organizationId;
    }

    private function organizationIdForContent(Content $content): ?int
    {
        if ($content->relationLoaded('workspace') && $content->workspace instanceof Workspace) {
            return (int) $content->workspace->organization_id;
        }

        if ($content->workspace_id) {
            return Workspace::query()
                ->whereKey((string) $content->workspace_id)
                ->value('organization_id');
        }

        if ($content->client_site_id) {
            return $this->organizationIdForSite(
                ClientSite::query()->whereKey((string) $content->client_site_id)->first(['id', 'workspace_id'])
            );
        }

        return null;
    }

    private function organizationIdForSite(?ClientSite $site): ?int
    {
        if (! $site?->workspace_id) {
            return null;
        }

        return Workspace::query()
            ->whereKey((string) $site->workspace_id)
            ->value('organization_id');
    }
}
