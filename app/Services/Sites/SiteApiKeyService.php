<?php

namespace App\Services\Sites;

use App\Models\ClientSite;
use App\Models\SiteToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class SiteApiKeyService
{
    public function createForSite(ClientSite $site, array $scopes, ?string $name = null): array
    {
        $plain = 'pl_site_' . bin2hex(random_bytes(32));

        $token = SiteToken::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $site->id,
            'workspace_id' => $site->workspace_id,
            'name' => $name ?: 'Argusly WordPress key',
            'token_hash' => hash('sha256', $plain),
            'token_encrypted' => Crypt::encryptString($plain),
            'key_prefix' => substr($plain, 0, 14),
            'scopes' => array_values(array_unique($scopes)),
            'abilities' => array_values(array_unique($scopes)),
            'revoked' => false,
            'revoked_at' => null,
        ]);

        return [$token, $plain];
    }

    public function regenerateForSite(ClientSite $site, array $scopes, ?string $name = null): array
    {
        SiteToken::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->where('revoked', false)
            ->update([
                'revoked' => true,
                'revoked_at' => now(),
            ]);

        return $this->createForSite($site, $scopes, $name);
    }

    public function defaultScopes(): array
    {
        return [
            'briefs:read',
            'briefs:write',
            'drafts:read',
            'drafts:write',
            'content:push',
            'heartbeat:write',
        ];
    }
}
