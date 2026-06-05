<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;

class LogIntegrationApiRequest
{
    public function handle(Request $request, Closure $next)
    {
        $startedAt = microtime(true);
        $requestTime = now();

        $response = $next($request);

        $workspace = $request->attributes->get('workspace');
        if (! $workspace) {
            return $response;
        }

        $apiKey = $request->attributes->get('apiKey');

        ApiRequestLog::query()->create([
            'workspace_id' => $workspace->id,
            'api_key_id' => $apiKey?->id,
            'user_id' => optional($request->user())->id,
            'method' => strtoupper($request->method()),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip_address' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'response_status' => (int) $response->status(),
            'credits_reserved' => $request->attributes->get('credits_reserved'),
            'credits_used' => $request->attributes->get('credits_used'),
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'requested_at' => $requestTime,
        ]);

        return $response;
    }
}
