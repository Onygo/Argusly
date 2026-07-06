<?php

namespace App\Jobs\Middleware;

use Illuminate\Support\Facades\RateLimiter;

class PageIntelligenceHostRateLimit
{
    public function handle(object $job, callable $next): void
    {
        $key = method_exists($job, 'pageIntelligenceRateLimitKey')
            ? (string) $job->pageIntelligenceRateLimitKey()
            : '';

        if ($key === '') {
            $next($job);

            return;
        }

        $maxAttempts = max(1, (int) config('page_intelligence.queue.host_rate_limit_per_minute', 12));
        $releaseAfter = max(1, (int) config('page_intelligence.queue.host_rate_limit_release_seconds', 30));

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $job->release($releaseAfter);

            return;
        }

        RateLimiter::hit($key, 60);
        $next($job);
    }
}
