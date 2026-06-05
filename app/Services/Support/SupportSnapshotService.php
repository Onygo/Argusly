<?php

namespace App\Services\Support;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportSnapshotService
{
    /**
     * @return array<string,mixed>
     */
    public function diagnostics(Organization $organization, User $targetUser): array
    {
        $workspaceIds = $organization->workspaces()->pluck('id')->all();
        $sites = ClientSite::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->orderBy('name')
            ->get(['id', 'name', 'workspace_id', 'type', 'is_active', 'site_url']);

        $siteIds = $sites->pluck('id')->all();

        return [
            'target' => [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'user_id' => $targetUser->id,
                'user_name' => $targetUser->name,
                'user_role' => $targetUser->role,
            ],
            'effective_permissions' => [
                'manage_organization' => in_array((string) $targetUser->role, ['owner', 'admin'], true),
                'is_admin_user' => (bool) $targetUser->is_admin,
                'active' => (bool) $targetUser->active,
            ],
            'workspaces' => $organization->workspaces()
                ->orderBy('created_at')
                ->get(['id', 'name', 'display_name'])
                ->map(fn ($ws) => [
                    'id' => (string) $ws->id,
                    'name' => (string) $ws->display_name,
                ])->values()->all(),
            'sites' => $sites->map(fn ($site) => [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'type' => (string) $site->type,
                'status' => $site->is_active ? 'active' : 'inactive',
                'host' => (string) parse_url((string) $site->site_url, PHP_URL_HOST),
            ])->values()->all(),
            'recent_briefs' => Brief::query()
                ->whereIn('client_site_id', $siteIds)
                ->latest()
                ->limit(20)
                ->get(['id', 'client_site_id', 'status', 'created_at'])
                ->map(fn ($brief) => [
                    'id' => (string) $brief->id,
                    'client_site_id' => (string) $brief->client_site_id,
                    'status' => (string) $brief->status,
                    'created_at' => optional($brief->created_at)->toDateTimeString(),
                ])->values()->all(),
            'recent_drafts' => Draft::query()
                ->whereIn('client_site_id', $siteIds)
                ->latest()
                ->limit(20)
                ->get(['id', 'client_site_id', 'status', 'delivery_status', 'created_at'])
                ->map(fn ($draft) => [
                    'id' => (string) $draft->id,
                    'client_site_id' => (string) $draft->client_site_id,
                    'status' => (string) $draft->status,
                    'delivery_status' => (string) ($draft->delivery_status ?? ''),
                    'created_at' => optional($draft->created_at)->toDateTimeString(),
                ])->values()->all(),
            'webhook_events' => WebhookEvent::query()
                ->latest()
                ->limit(20)
                ->get(['id', 'provider', 'event_type', 'handled_at', 'error', 'created_at'])
                ->map(fn ($event) => [
                    'id' => (string) $event->id,
                    'provider' => (string) ($event->provider ?? ''),
                    'event_type' => (string) ($event->event_type ?? ''),
                    'handled_at' => optional($event->handled_at)->toDateTimeString(),
                    'error' => $event->error ? Str::limit($this->redact((string) $event->error), 180) : '',
                    'created_at' => optional($event->created_at)->toDateTimeString(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array{path:string,filename:string,data:array<string,mixed>}
     */
    public function generateSnapshot(Organization $organization, User $targetUser): array
    {
        $diagnostics = $this->diagnostics($organization, $targetUser);

        $activeSubscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due'])
            ->latest('updated_at')
            ->with('plan')
            ->first();

        $recentFailedJobs = DB::table('failed_jobs')
            ->select(['uuid', 'queue', 'failed_at', 'exception'])
            ->latest('failed_at')
            ->limit(20)
            ->get()
            ->map(function ($job): array {
                return [
                    'uuid' => (string) $job->uuid,
                    'queue' => (string) $job->queue,
                    'failed_at' => (string) $job->failed_at,
                    'exception' => Str::limit($this->redact((string) ($job->exception ?? '')), 400),
                ];
            })->values()->all();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'target_company_id' => $organization->id,
            'target_user_id' => $targetUser->id,
            'plan' => [
                'subscription_status' => (string) ($activeSubscription?->status ?? 'none'),
                'plan_name' => (string) ($activeSubscription?->plan?->name ?? 'none'),
                'plan_key' => (string) ($activeSubscription?->plan?->key ?? 'none'),
            ],
            'diagnostics' => $diagnostics,
            'recent_failed_jobs' => $recentFailedJobs,
            'config_flags' => [
                'publishlayer_wp_require_timestamp_nonce' => (bool) config('publishlayer.wp_connector.require_timestamp_nonce', false),
                'publishlayer_wp_timestamp_ttl_seconds' => (int) config('publishlayer.wp_connector.timestamp_ttl_seconds', 0),
                'queue_default' => (string) config('queue.default', 'sync'),
            ],
        ];

        $filename = sprintf(
            'support-snapshot-org-%d-user-%d-%s.json',
            (int) $organization->id,
            (int) $targetUser->id,
            now()->format('Ymd_His')
        );
        $path = 'support/' . $filename;

        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'path' => $path,
            'filename' => $filename,
            'data' => $payload,
        ];
    }

    public function cleanupSnapshots(int $keepDays = 7): int
    {
        $files = Storage::disk('local')->files('support');
        $deleted = 0;
        $threshold = now()->subDays(max(1, $keepDays));

        foreach ($files as $path) {
            $timestamp = Storage::disk('local')->lastModified($path);
            if ($timestamp < $threshold->timestamp) {
                Storage::disk('local')->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function redact(string $value): string
    {
        $patterns = [
            '/(api[_-]?key|token|secret|password)\s*[:=]\s*([^\s"\']+)/i',
            '/(Authorization:\s*Bearer\s+)([A-Za-z0-9\-\._~\+\/]+=*)/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1 [REDACTED]', $value) ?? $value;
        }

        return $value;
    }
}
