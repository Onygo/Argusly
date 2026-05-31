<?php

namespace App\Http\Middleware;

use App\Models\ConnectorInstallation;
use App\Models\ConnectorLog;
use App\Models\ConnectorToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateConnector
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $plainToken = $this->token($request);

        if (! $plainToken) {
            return $this->error('missing_connector_token', 'Connector token is required.', 401);
        }

        $token = ConnectorToken::query()
            ->where('token_hash', ConnectorToken::hashToken($plainToken))
            ->with(['account', 'brand', 'installation.account', 'installation.brand', 'installation.channel', 'installation.manifest', 'installation.version.capabilities'])
            ->first();

        if (! $token) {
            return $this->error('invalid_connector_token', 'Connector token is invalid.', 401);
        }

        if (! $token->isUsable()) {
            if ($token->installation) {
                $this->logRequest($request, $token->installation, $token, new JsonResponse(status: 403), 'connector_token_inactive');
            }

            return $this->error('connector_token_inactive', 'Connector token is expired or revoked.', 403);
        }

        foreach ($abilities as $ability) {
            if (! $token->can($ability)) {
                if ($token->installation) {
                    $this->logRequest($request, $token->installation, $token, new JsonResponse(status: 403), 'missing ability '.$ability);
                }

                return $this->error('connector_token_forbidden', "Connector token is missing the [{$ability}] ability.", 403);
            }
        }

        $installation = $token->installation;

        if (! $installation) {
            return $this->error('connector_scope_incomplete', 'Connector token must resolve a connector installation.', 409);
        }

        if (! in_array($installation->status, ['pending', 'active', 'unhealthy'], true) || $installation->revoked_at !== null) {
            return $this->error('connector_inactive', 'Connector installation is not active.', 403);
        }

        if (! $installation->account || ! $installation->brand || ! $installation->channel) {
            return $this->error('connector_scope_incomplete', 'Connector installation must resolve account, brand and channel.', 409);
        }

        if ($token->account_id !== $installation->account_id || $token->brand_id !== $installation->brand_id) {
            return $this->error('connector_scope_mismatch', 'Connector token scope does not match the installation scope.', 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('connector_token', $token);
        $request->attributes->set('connector_installation', $installation);
        $request->attributes->set('connector_account', $installation->account);
        $request->attributes->set('connector_brand', $installation->brand);
        $request->attributes->set('connector_channel', $installation->channel);

        $response = $next($request);

        $this->logRequest($request, $installation, $token, $response);

        return $response;
    }

    private function token(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer) {
            return $bearer;
        }

        $header = $request->header('X-Connector-Token');

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    private function logRequest(Request $request, ConnectorInstallation $installation, ConnectorToken $token, Response $response, ?string $message = null): void
    {
        ConnectorLog::query()->create([
            'connector_installation_id' => $installation->id,
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'level' => $response->isSuccessful() ? 'info' : 'warning',
            'event' => 'connector.api_request',
            'status' => (string) $response->getStatusCode(),
            'message' => $message ?? $request->method().' '.$request->path(),
            'context' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->uri(),
                'status_code' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'connector_token_id' => $token->id,
                'connector_token_name' => $token->name,
            ],
            'occurred_at' => now(),
        ]);
    }
}
