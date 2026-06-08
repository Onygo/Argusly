<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Workspace;
use App\Services\Integrations\IntegrationCredentialResolver;
use App\Support\Connectors\ConnectorHeaders;
use Closure;
use Illuminate\Http\Request;

class IntegrationTokenMiddleware
{
    public function __construct(
        private readonly IntegrationCredentialResolver $credentialResolver,
        private readonly SiteTokenMiddleware $siteTokenMiddleware,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $auth = (string) $request->header('Authorization');
        if (! str_starts_with($auth, 'Bearer ')) {
            $headerToken = ConnectorHeaders::apiKey($request);
            if ($headerToken === '') {
                return response()->json([
                    'message' => 'Missing bearer token',
                    'code' => 'AUTH_MISSING_TOKEN',
                ], 401);
            }

            $auth = 'Bearer '.$headerToken;
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return response()->json([
                'message' => 'Missing bearer token',
                'code' => 'AUTH_MISSING_TOKEN',
            ], 401);
        }

        $apiKey = $this->credentialResolver->resolveWorkspaceApiKey($token);

        if ($apiKey) {
            return $this->attachWorkspaceApiKeyContext($request, $apiKey, $next);
        }

        $legacy = $this->credentialResolver->resolveLegacyOrganizationKey($token, $request);
        if ($legacy) {
            $workspace = $legacy['workspace'];
            if (! $workspace instanceof Workspace) {
                return response()->json([
                    'message' => 'Invalid API key workspace',
                    'code' => 'AUTH_INVALID_WORKSPACE',
                ], 401);
            }

            $destinationId = ConnectorHeaders::destinationId($request);
            $destination = $destinationId !== ''
                ? $workspace->contentDestinations()->where('id', $destinationId)->first()
                : null;

            $request->attributes->set('apiKey', $legacy['api_key']);
            $request->attributes->set('workspace', $workspace);
            $request->attributes->set('contentDestination', $destination);
            // Keep auth mode as api_key to preserve existing domain middleware behavior.
            $request->attributes->set('integration_auth_mode', 'api_key');
            $request->attributes->set('legacy_credential_origin', 'organization_api_key');

            return $next($request);
        }

        return $this->siteTokenMiddleware->handle($request, function (Request $request) use ($next) {
            $request->attributes->set('integration_auth_mode', 'site_token');

            return $next($request);
        });
    }

    private function attachWorkspaceApiKeyContext(Request $request, ApiKey $apiKey, Closure $next)
    {
        $workspace = $apiKey->workspace;
        $organization = $workspace?->organization;

        if (! $workspace || ! $organization || ! $organization->isActive()) {
            return response()->json([
                'message' => 'Invalid API key workspace',
                'code' => 'AUTH_INVALID_WORKSPACE',
            ], 401);
        }

        $apiKey->last_used_at = now();
        $apiKey->save();

        $destination = $apiKey->contentDestination;
        if (! $destination) {
            $destinationId = ConnectorHeaders::destinationId($request);
            if ($destinationId !== '') {
                $destination = $workspace->contentDestinations()->where('id', $destinationId)->first();
            }
        }

        $request->attributes->set('apiKey', $apiKey);
        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('contentDestination', $destination);
        $request->attributes->set('integration_auth_mode', 'api_key');

        return $next($request);
    }
}
