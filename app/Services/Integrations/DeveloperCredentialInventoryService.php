<?php

namespace App\Services\Integrations;

use App\Models\ApiKey;
use App\Models\SiteToken;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class DeveloperCredentialInventoryService
{
    /**
     * @return array{
     *   workspace_api_keys:Collection<int,ApiKey>,
     *   linked_credentials:Collection<int,array<string,mixed>>,
     *   summary:array<string,int>
     * }
     */
    public function buildForWorkspace(Workspace $workspace): array
    {
        $workspaceApiKeys = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->orderByDesc('created_at')
            ->get();

        $linkedCredentials = collect()
            ->merge($this->siteCredentialRows($workspace))
            ->merge($this->legacyOrganizationCredentialRows($workspace))
            ->sortByDesc(fn (array $row): int => (int) data_get($row, 'created_sort_ts', 0))
            ->values();

        return [
            'workspace_api_keys' => $workspaceApiKeys,
            'linked_credentials' => $linkedCredentials,
            'summary' => [
                'active_workspace_api_keys' => (int) $workspaceApiKeys->whereNull('revoked_at')->count(),
                'active_linked_credentials' => (int) $linkedCredentials->where('status', 'active')->count(),
                'linked_credentials_total' => (int) $linkedCredentials->count(),
            ],
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function siteCredentialRows(Workspace $workspace): Collection
    {
        $importedByOrigin = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_legacy_import', true)
            ->where('origin_type', ApiKey::ORIGIN_TYPE_SITE_TOKEN)
            ->get(['id', 'origin_id'])
            ->keyBy(fn (ApiKey $item): string => (string) $item->origin_id);

        return SiteToken::query()
            ->with(['clientSite:id,name,type,status,is_active'])
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SiteToken $token) use ($importedByOrigin): array {
                $site = $token->clientSite;
                $siteName = trim((string) ($site?->name ?? 'Unknown site'));
                $siteType = strtolower(trim((string) ($site?->type ?? 'wordpress')));
                $typeLabel = $siteType === 'laravel'
                    ? 'Laravel connector key'
                    : 'WordPress integration key';

                $isRevoked = (bool) $token->revoked || $token->revoked_at !== null;
                $isDisabled = $site
                    ? (! (bool) $site->is_active || (string) $site->status === 'disabled')
                    : false;

                $status = $isRevoked ? 'revoked' : ($isDisabled ? 'disabled' : 'active');

                $imported = $importedByOrigin->get((string) $token->id);

                return [
                    'id' => 'site_token:' . (string) $token->id,
                    'source_id' => (string) $token->id,
                    'name' => (string) ($token->name ?: $typeLabel),
                    'type_label' => $typeLabel,
                    'origin_label' => 'Site: ' . $siteName,
                    'scope_label' => 'Site-scoped credential',
                    'status' => $status,
                    'status_label' => strtoupper($status),
                    'managed_via' => 'site_integration',
                    'legacy_badge' => true,
                    'legacy_label' => 'Legacy site key',
                    'identifier' => $this->maskedIdentifier((string) ($token->key_prefix ?? ''), (string) ($token->token_hash ?? '')),
                    'scopes' => is_array($token->abilities) && $token->abilities !== []
                        ? array_values($token->abilities)
                        : (is_array($token->scopes) ? array_values($token->scopes) : []),
                    'can_manage_here' => false,
                    'manage_hint' => 'Manage rotation/revocation from Site setup to avoid breaking live connector traffic.',
                    'manage_url' => $site ? route('app.sites.show', $site) : null,
                    'is_imported_reference' => $imported !== null,
                    'imported_reference_id' => $imported ? (string) $imported->id : null,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                    'created_sort_ts' => $token->created_at?->getTimestamp() ?? 0,
                ];
            });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function legacyOrganizationCredentialRows(Workspace $workspace): Collection
    {
        $organization = $workspace->organization;
        if (! $organization || ! is_string($organization->api_key_encrypted) || trim($organization->api_key_encrypted) === '') {
            return collect();
        }

        $plain = trim((string) ($organization->api_key ?? ''));
        $prefix = $plain !== '' ? substr($plain, 0, 14) : 'legacy_org';
        $status = (bool) $organization->api_enabled ? 'active' : 'disabled';

        return collect([[
            'id' => 'legacy_org:' . (string) $organization->id,
            'source_id' => (string) $organization->id,
            'name' => 'Legacy organization API key',
            'type_label' => 'Organization compatibility key',
            'origin_label' => 'Organization settings (legacy)',
            'scope_label' => 'Organization-scoped legacy credential',
            'status' => $status,
            'status_label' => strtoupper($status),
            'managed_via' => 'legacy_organization',
            'legacy_badge' => true,
            'legacy_label' => 'Legacy org key',
            'identifier' => $this->maskedIdentifier($prefix, hash('sha256', $plain)),
            'scopes' => ['*'],
            'can_manage_here' => false,
            'manage_hint' => 'This key is legacy-only. Use workspace API keys for new integrations.',
            'manage_url' => null,
            'is_imported_reference' => false,
            'imported_reference_id' => null,
            'created_at' => $organization->created_at,
            'last_used_at' => null,
            'created_sort_ts' => $organization->created_at?->getTimestamp() ?? 0,
        ]]);
    }

    private function maskedIdentifier(string $prefix, string $hashFallback): string
    {
        $normalizedPrefix = trim($prefix);
        if ($normalizedPrefix === '') {
            $normalizedPrefix = substr(trim($hashFallback), 0, 10);
        }

        if ($normalizedPrefix === '') {
            return 'hidden';
        }

        return $normalizedPrefix . '...';
    }
}

