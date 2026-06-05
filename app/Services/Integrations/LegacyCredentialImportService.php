<?php

namespace App\Services\Integrations;

use App\Models\ApiKey;
use App\Models\SiteToken;
use App\Models\Workspace;

class LegacyCredentialImportService
{
    /**
     * @return array{
     *   created:int,
     *   updated:int,
     *   skipped:int,
     *   conflicts:int,
     *   details:array<int,array<string,mixed>>
     * }
     */
    public function importWorkspace(Workspace $workspace, bool $dryRun = false): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'details' => [],
        ];

        $tokens = SiteToken::query()
            ->with('clientSite:id,name,type')
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->get();

        foreach ($tokens as $token) {
            $result = $this->upsertSiteTokenImport($workspace, $token, $dryRun);
            $stats[$result['status']]++;
            $stats['details'][] = $result;
        }

        $organizationResult = $this->upsertOrganizationKeyImport($workspace, $dryRun);
        if ($organizationResult !== null) {
            $stats[$organizationResult['status']]++;
            $stats['details'][] = $organizationResult;
        }

        return $stats;
    }

    /**
     * @return array{status:string,message:string,origin_type:string,origin_id:string}
     */
    private function upsertSiteTokenImport(Workspace $workspace, SiteToken $token, bool $dryRun): array
    {
        $tokenHash = trim((string) ($token->token_hash ?? ''));
        if ($tokenHash === '') {
            return [
                'status' => 'skipped',
                'message' => 'site token has no token_hash',
                'origin_type' => ApiKey::ORIGIN_TYPE_SITE_TOKEN,
                'origin_id' => (string) $token->id,
            ];
        }

        $existing = ApiKey::query()->where('key_hash', $tokenHash)->first();
        if ($existing && ! (bool) $existing->is_legacy_import) {
            return [
                'status' => 'conflicts',
                'message' => 'hash already belongs to non-legacy api key',
                'origin_type' => ApiKey::ORIGIN_TYPE_SITE_TOKEN,
                'origin_id' => (string) $token->id,
            ];
        }

        $site = $token->clientSite;
        $siteName = trim((string) ($site?->name ?: 'Unknown site'));
        $siteType = strtolower(trim((string) ($site?->type ?? 'wordpress')));
        $typeLabel = $siteType === 'laravel' ? 'Laravel connector' : 'WordPress plugin';

        $scopes = is_array($token->abilities) && $token->abilities !== []
            ? array_values($token->abilities)
            : (is_array($token->scopes) ? array_values($token->scopes) : []);

        $payload = [
            'workspace_id' => (string) $workspace->id,
            'content_destination_id' => null,
            'origin_type' => ApiKey::ORIGIN_TYPE_SITE_TOKEN,
            'origin_id' => (string) $token->id,
            'origin_label' => 'Site: ' . $siteName,
            'is_legacy_import' => true,
            'managed_via' => ApiKey::MANAGED_VIA_SITE_INTEGRATION,
            'notes' => 'Imported from site_tokens for Developer visibility. Source credential remains site-scoped.',
            'name' => (string) ($token->name ?: ($typeLabel . ' key')),
            'key_prefix' => trim((string) ($token->key_prefix ?: substr($tokenHash, 0, 14))),
            'key_hash' => $tokenHash,
            'scopes' => $scopes,
            'last_used_at' => $token->last_used_at,
            'expires_at' => null,
            'revoked_at' => ((bool) $token->revoked || $token->revoked_at !== null)
                ? ($token->revoked_at ?: $token->updated_at ?: now())
                : null,
            'created_by' => null,
        ];

        if ($dryRun) {
            return [
                'status' => $existing ? 'updated' : 'created',
                'message' => $existing ? 'would update import metadata' : 'would create import metadata',
                'origin_type' => ApiKey::ORIGIN_TYPE_SITE_TOKEN,
                'origin_id' => (string) $token->id,
            ];
        }

        if ($existing) {
            $existing->update($payload);

            return [
                'status' => 'updated',
                'message' => 'updated import metadata',
                'origin_type' => ApiKey::ORIGIN_TYPE_SITE_TOKEN,
                'origin_id' => (string) $token->id,
            ];
        }

        $created = ApiKey::query()->create($payload);
        if ($token->created_at) {
            $created->created_at = $token->created_at;
            $created->updated_at = $token->updated_at ?: $token->created_at;
            $created->save();
        }

        return [
            'status' => 'created',
            'message' => 'created import metadata',
            'origin_type' => ApiKey::ORIGIN_TYPE_SITE_TOKEN,
            'origin_id' => (string) $token->id,
        ];
    }

    /**
     * @return array{status:string,message:string,origin_type:string,origin_id:string}|null
     */
    private function upsertOrganizationKeyImport(Workspace $workspace, bool $dryRun): ?array
    {
        $organization = $workspace->organization;
        if (! $organization) {
            return null;
        }

        $primaryWorkspaceId = $organization->workspaces()
            ->orderBy('created_at')
            ->value('id');

        if ((string) $primaryWorkspaceId !== (string) $workspace->id) {
            return [
                'status' => 'skipped',
                'message' => 'organization key import only runs on the organization primary workspace',
                'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
                'origin_id' => (string) $organization->id,
            ];
        }

        $tokenHash = trim((string) ($organization->api_key_hash ?? ''));
        if ($tokenHash === '') {
            $plain = trim((string) ($organization->api_key ?? ''));
            if ($plain !== '') {
                $tokenHash = hash('sha256', $plain);
            }
        }

        if ($tokenHash === '') {
            return null;
        }

        $existing = ApiKey::query()->where('key_hash', $tokenHash)->first();
        if ($existing && ! (bool) $existing->is_legacy_import) {
            return [
                'status' => 'conflicts',
                'message' => 'organization key hash already belongs to non-legacy api key',
                'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
                'origin_id' => (string) $organization->id,
            ];
        }

        $plain = trim((string) ($organization->api_key ?? ''));
        $keyPrefix = $plain !== '' ? substr($plain, 0, 14) : substr($tokenHash, 0, 14);

        $payload = [
            'workspace_id' => (string) $workspace->id,
            'content_destination_id' => null,
            'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
            'origin_id' => (string) $organization->id,
            'origin_label' => (string) ($organization->name ?? 'Organization'),
            'is_legacy_import' => true,
            'managed_via' => ApiKey::MANAGED_VIA_LEGACY_ORGANIZATION,
            'notes' => 'Imported from organizations.api_key_encrypted for compatibility visibility.',
            'name' => 'Legacy organization API key',
            'key_prefix' => $keyPrefix,
            'key_hash' => $tokenHash,
            'scopes' => ['*'],
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => (bool) $organization->api_enabled ? null : now(),
            'created_by' => null,
        ];

        if ($dryRun) {
            return [
                'status' => $existing ? 'updated' : 'created',
                'message' => $existing ? 'would update organization legacy import metadata' : 'would create organization legacy import metadata',
                'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
                'origin_id' => (string) $organization->id,
            ];
        }

        if ($existing) {
            $existing->update($payload);

            return [
                'status' => 'updated',
                'message' => 'updated organization legacy import metadata',
                'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
                'origin_id' => (string) $organization->id,
            ];
        }

        $created = ApiKey::query()->create($payload);
        if ($organization->created_at) {
            $created->created_at = $organization->created_at;
            $created->updated_at = $organization->updated_at ?: $organization->created_at;
            $created->save();
        }

        return [
            'status' => 'created',
            'message' => 'created organization legacy import metadata',
            'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
            'origin_id' => (string) $organization->id,
        ];
    }
}
