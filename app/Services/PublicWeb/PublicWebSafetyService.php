<?php

namespace App\Services\PublicWeb;

use App\Services\PageIntelligence\PageCrawlerSafetyService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class PublicWebSafetyService
{
    public function __construct(private readonly PageCrawlerSafetyService $crawlerSafety)
    {
    }

    public function normalizeAndValidate(string $url): string
    {
        return $this->crawlerSafety->normalizeAndValidate($url, respectRobots: false);
    }

    public function validateRedirectTarget(string $url): string
    {
        return $this->crawlerSafety->validateRedirectTarget($url);
    }

    /**
     * @return array<int,string>
     */
    public function resolvePublicHost(string $host): array
    {
        return $this->crawlerSafety->resolvePublicHost($host);
    }

    public function applyGuardedHttpOptions(PendingRequest $request, string $url): PendingRequest
    {
        return $this->crawlerSafety->applyGuardedHttpOptions($request, $url);
    }

    /**
     * @param array<int,string>|null $allowedContentTypes
     */
    public function assertResponseAllowed(Response $response, string $url, ?array $allowedContentTypes = null, ?int $maxBytes = null): void
    {
        $this->crawlerSafety->assertResponseAllowed($response, $url, $allowedContentTypes, $maxBytes);
    }
}
