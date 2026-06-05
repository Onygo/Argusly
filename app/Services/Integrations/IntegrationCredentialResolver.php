<?php

namespace App\Services\Integrations;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Http\Request;

class IntegrationCredentialResolver
{
    public function resolveWorkspaceApiKey(string $plainText): ?ApiKey
    {
        $hash = hash('sha256', trim($plainText));

        return ApiKey::query()
            ->with(['workspace.organization', 'contentDestination'])
            ->where('key_hash', $hash)
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * @return array{api_key:ApiKey,workspace:Workspace}|null
     */
    public function resolveLegacyOrganizationKey(string $plainText, Request $request): ?array
    {
        $hash = hash('sha256', trim($plainText));

        $organization = Organization::query()
            ->where('api_enabled', true)
            ->whereNotNull('api_key_hash')
            ->where('api_key_hash', $hash)
            ->first();

        if (! $organization || ! $organization->isActive()) {
            return null;
        }

        $workspace = $this->resolveWorkspaceForOrganization($organization, $request);
        if (! $workspace) {
            return null;
        }

        $virtualApiKey = new ApiKey([
            'workspace_id' => (string) $workspace->id,
            'name' => 'Legacy organization API key',
            'key_prefix' => $this->prefixForLegacyToken($plainText),
            'scopes' => ['*'],
            'is_legacy_import' => true,
            'managed_via' => ApiKey::MANAGED_VIA_LEGACY_ORGANIZATION,
            'origin_type' => ApiKey::ORIGIN_TYPE_ORGANIZATION,
            'origin_id' => (string) $organization->id,
            'origin_label' => (string) $organization->name,
            'notes' => 'Resolved from organizations.api_key_encrypted compatibility key.',
        ]);
        $virtualApiKey->setRelation('workspace', $workspace);

        return [
            'api_key' => $virtualApiKey,
            'workspace' => $workspace,
        ];
    }

    private function resolveWorkspaceForOrganization(Organization $organization, Request $request): ?Workspace
    {
        $hint = trim((string) ($request->header('X-PublishLayer-Workspace-Id')
            ?: $request->query('workspace_id', '')));

        if ($hint !== '') {
            $hinted = Workspace::query()
                ->where('organization_id', $organization->id)
                ->where('id', $hint)
                ->first();

            if ($hinted) {
                return $hinted;
            }
        }

        return Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->first();
    }

    private function prefixForLegacyToken(string $plainText): string
    {
        $token = trim($plainText);
        if ($token === '') {
            return 'legacy_org';
        }

        return substr($token, 0, 14);
    }
}

