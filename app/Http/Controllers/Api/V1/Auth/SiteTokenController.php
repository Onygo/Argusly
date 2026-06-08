<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\SiteToken;
use App\Models\Workspace;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SiteTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => [
                'required',
                'uuid',
                Rule::exists('workspaces', 'id')->whereNotNull('organization_id'),
            ],
            'site.type' => ['required','string'],
            'site.site_url' => ['required','string'],
            'site.name' => ['required','string'],
            'site.allowed_domains' => ['required','array','min:1'],
            'site.allowed_domains.*' => ['string'],
            'scopes' => ['required','array','min:1'],
            'scopes.*' => ['string'],
        ]);

        $siteUrl = SiteUrl::normalizeBaseUrl((string) $data['site']['site_url']);
        $allowedDomains = $data['site']['allowed_domains'];
        $workspace = Workspace::query()->findOrFail($data['workspace_id']);

        if (! $workspace->organization_id) {
            return response()->json([
                'error' => 'Workspace is not linked to an organization.',
            ], 422);
        }

        $clientSite = ClientSite::create([
            'workspace_id' => $workspace->id,
            'type' => $data['site']['type'],
            'name' => $data['site']['name'],
            'site_url' => $siteUrl,
            'base_url' => $siteUrl,
            'allowed_domains' => $allowedDomains,
            'is_active' => true,
            'status' => 'pending',
        ]);

        $plain = 'arg_site_' . bin2hex(random_bytes(32));
        $token = SiteToken::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite->id,
            'name' => 'WordPress plugin key',
            'token_hash' => hash('sha256', $plain),
            'token_encrypted' => Crypt::encryptString($plain),
            'key_prefix' => substr($plain, 0, 14),
            'scopes' => $data['scopes'],
            'abilities' => $data['scopes'],
            'revoked' => false,
            'revoked_at' => null,
        ]);

        return response()->json([
            'id' => $token->id,
            'client_site_id' => $clientSite->id,
            'token' => $plain,
            'scopes' => $token->scopes,
            'created_at' => $token->created_at?->toIso8601String(),
        ], 201);
    }

    public function revoke(string $id)
    {
        $token = SiteToken::findOrFail($id);
        $token->revoked = true;
        $token->revoked_at = now();
        $token->save();

        return response()->json(['ok' => true]);
    }

    public function rotate(string $id)
    {
        $token = SiteToken::findOrFail($id);

        $plain = 'arg_site_' . bin2hex(random_bytes(32));
        $token->token_hash = hash('sha256', $plain);
        $token->token_encrypted = Crypt::encryptString($plain);
        $token->key_prefix = substr($plain, 0, 14);
        $token->revoked = false;
        $token->revoked_at = null;
        $token->save();

        return response()->json([
            'id' => $token->id,
            'token' => $plain,
            'rotated_at' => now()->toIso8601String(),
        ]);
    }
}
