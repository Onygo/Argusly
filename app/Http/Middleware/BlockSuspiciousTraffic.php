<?php

namespace App\Http\Middleware;

use App\Support\SecurityResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BlockSuspiciousTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        $shouldBlock = (bool) config('security.toggles.block_suspicious_traffic', false);
        $shouldLog = (bool) config('security.toggles.log_suspicious_traffic', true);
        $logOnly = (bool) config('security.toggles.log_only_mode', false);

        if (! $shouldBlock && ! $shouldLog && ! $logOnly) {
            return $next($request);
        }

        $reason = $this->detectReason($request);

        if ($reason === null) {
            return $next($request);
        }

        if ($shouldLog || $logOnly) {
            $this->logEvent($request, $reason);
        }

        if (! $shouldBlock || $logOnly) {
            return $next($request);
        }

        return SecurityResponse::forbidden($request);
    }

    private function detectReason(Request $request): ?string
    {
        $path = '/'.ltrim(mb_strtolower(rawurldecode($request->getPathInfo() ?: '/')), '/');
        $queryString = mb_strtolower(rawurldecode((string) $request->server('QUERY_STRING', '')));
        $userAgent = mb_strtolower((string) ($request->userAgent() ?? ''));

        foreach ((array) config('security.suspicious_paths', []) as $suspiciousPath) {
            $candidate = '/'.ltrim(mb_strtolower((string) $suspiciousPath), '/');

            if ($path === $candidate || str_starts_with($path, $candidate.'/')) {
                return 'path';
            }
        }

        foreach ((array) config('security.suspicious_user_agents', []) as $needle) {
            $needle = mb_strtolower((string) $needle);

            if ($needle !== '' && str_contains($userAgent, $needle)) {
                return 'user_agent';
            }
        }

        if (mb_strlen($queryString) > (int) config('security.max_query_length', 2000)) {
            return 'query_length';
        }

        $haystacks = [$path];
        if ($queryString !== '') {
            $haystacks[] = $queryString;
        }

        foreach ((array) config('security.suspicious_patterns', []) as $pattern) {
            foreach ($haystacks as $haystack) {
                if (@preg_match((string) $pattern, $haystack) === 1) {
                    return 'pattern';
                }
            }
        }

        return null;
    }

    private function logEvent(Request $request, string $reason): void
    {
        Log::channel((string) config('security.logging.channel', 'security'))
            ->warning('suspicious_traffic', [
                'reason' => $reason,
                'ip' => (string) ($request->ip() ?? 'unknown'),
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->path(), '/'),
                'user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 255),
            ]);
    }
}
