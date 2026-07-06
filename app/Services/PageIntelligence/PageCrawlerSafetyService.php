<?php

namespace App\Services\PageIntelligence;

use App\Models\MonitoredSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class PageCrawlerSafetyService
{
    public function __construct(private readonly PageUrlNormalizer $normalizer)
    {
    }

    public function normalizeAndValidate(string $url, ?MonitoredSource $source = null, bool $respectRobots = true): string
    {
        $normalized = $this->normalizer->normalize($url)->firstSeenUrl;
        $host = $this->host($normalized);

        $this->assertDomainAllowed($host, $source);
        $this->assertHostPublic($host);
        $this->resolvePublicHost($host);

        if ($respectRobots) {
            $this->assertRobotsAllowed($normalized, $source);
        }

        return $normalized;
    }

    public function validateRedirectTarget(string $url, ?MonitoredSource $source = null): string
    {
        return $this->normalizeAndValidate($url, $source, respectRobots: false);
    }

    /**
     * @param array<int,string>|null $allowedContentTypes
     */
    public function assertResponseAllowed(Response $response, string $url, ?array $allowedContentTypes = null, ?int $maxBytes = null, ?MonitoredSource $source = null): void
    {
        $this->validateRedirectTarget((string) ($response->effectiveUri() ?: $url), $source);
        $this->assertContentTypeAllowed((string) $response->header('Content-Type', ''), $allowedContentTypes);
        $this->assertResponseSizeAllowed((string) $response->body(), $maxBytes);
    }

    public function guardedHttpOptions(string $url, ?MonitoredSource $source = null): array
    {
        $normalized = $this->normalizeAndValidate($url, $source, respectRobots: false);
        $host = $this->host($normalized);
        $ips = $this->resolvePublicHost($host);
        $entries = $this->curlResolveEntries($normalized, $ips);

        return $entries === [] ? [] : [
            'curl' => [
                CURLOPT_RESOLVE => $entries,
            ],
        ];
    }

    public function applyGuardedHttpOptions(PendingRequest $request, string $url, ?MonitoredSource $source = null): PendingRequest
    {
        $options = $this->guardedHttpOptions($url, $source);

        return $options === [] ? $request : $request->withOptions($options);
    }

    /**
     * @param array<int,string>|null $allowedContentTypes
     */
    public function assertContentTypeAllowed(string $contentType, ?array $allowedContentTypes = null): void
    {
        $normalized = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        $allowed = $allowedContentTypes ?: (array) config('page_intelligence.safety.allowed_content_types', []);

        if ($normalized === '') {
            throw new InvalidArgumentException('The fetch returned no content type.');
        }

        foreach ($allowed as $allowedType) {
            if ($normalized === strtolower(trim((string) $allowedType))) {
                return;
            }
        }

        throw new InvalidArgumentException('The fetch returned an unsupported content type.');
    }

    public function assertResponseSizeAllowed(string $body, ?int $maxBytes = null): void
    {
        $limit = max(1, (int) ($maxBytes ?? config('page_intelligence.fetch.max_html_bytes', 3000000)));

        if (strlen($body) > $limit) {
            throw new InvalidArgumentException(sprintf('The fetch exceeded the configured response-size limit of %d bytes.', $limit));
        }
    }

    public function host(string $url): string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
        $host = trim($host, '[]');

        if ($host === '') {
            throw new InvalidArgumentException('Enter a valid public URL with a host.');
        }

        return $host;
    }

    public function rateLimitKeyForUrl(string $url): string
    {
        return 'page-intelligence-host:'.$this->host($url);
    }

    private function assertDomainAllowed(string $host, ?MonitoredSource $source): void
    {
        $denyDomains = array_merge(
            (array) config('page_intelligence.safety.deny_domains', []),
            (array) data_get($source?->crawl_policy_json, 'deny_domains', []),
        );

        foreach ($denyDomains as $domain) {
            if ($this->domainMatches($host, (string) $domain)) {
                throw new InvalidArgumentException('This domain is blocked by crawler policy.');
            }
        }

        $allowDomains = array_merge(
            (array) config('page_intelligence.safety.allow_domains', []),
            (array) data_get($source?->crawl_policy_json, 'allow_domains', []),
        );

        if ($allowDomains === []) {
            return;
        }

        foreach ($allowDomains as $domain) {
            if ($this->domainMatches($host, (string) $domain)) {
                return;
            }
        }

        throw new InvalidArgumentException('This domain is not allowed by crawler policy.');
    }

    private function assertHostPublic(string $host): void
    {
        if ($host === 'localhost' || $host === '0' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('This URL is not public and cannot be fetched.');
        }

        foreach ((array) config('page_intelligence.safety.blocked_host_suffixes', []) as $suffix) {
            $suffix = strtolower(trim((string) $suffix));
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                throw new InvalidArgumentException('This URL is not public and cannot be fetched.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && $this->isNonPublicIp($host)) {
            throw new InvalidArgumentException('This URL resolves to a non-public address.');
        }
    }

    public function resolvePublicHost(string $host): array
    {
        $ips = $this->resolveHost($host);

        if ($ips === []) {
            throw new InvalidArgumentException('This host could not be resolved publicly.');
        }

        foreach ($ips as $ip) {
            if ($this->isNonPublicIp($ip)) {
                throw new InvalidArgumentException('This host resolves to a private or reserved address.');
            }
        }

        return $ips;
    }

    /**
     * @return array<int,string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $overrides = (array) config('page_intelligence.safety.dns_overrides', []);
        if (array_key_exists($host, $overrides)) {
            return array_values((array) $overrides[$host]);
        }

        $addresses = gethostbynamel($host) ?: [];
        $records = dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            $ipv6 = (string) ($record['ipv6'] ?? '');
            if ($ipv6 !== '') {
                $addresses[] = $ipv6;
            }
        }

        return array_values(array_unique(array_filter($addresses)));
    }

    private function isNonPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false) {
                $unsigned = sprintf('%u', $long);
                if ($unsigned >= 3758096384 && $unsigned <= 4026531839) {
                    return true;
                }
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function assertRobotsAllowed(string $url, ?MonitoredSource $source): void
    {
        if (! $this->shouldRespectRobots($source)) {
            return;
        }

        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = $this->host($url);
        $robotsUrl = $scheme.'://'.$host.'/robots.txt';
        $path = (string) ($parts['path'] ?? '/');

        $rules = Cache::remember(
            'page-intelligence:robots:'.hash('sha256', $robotsUrl),
            max(60, (int) config('page_intelligence.safety.robots_cache_seconds', 86400)),
            fn (): array => $this->fetchRobotsRules($robotsUrl, $source),
        );

        if (! $this->robotsAllowsPath($rules, $path)) {
            throw new InvalidArgumentException('This URL is disallowed by robots.txt.');
        }
    }

    /**
     * @return array{allow:array<int,string>,disallow:array<int,string>}
     */
    private function fetchRobotsRules(string $robotsUrl, ?MonitoredSource $source): array
    {
        $robotsUrl = $this->normalizeAndValidate($robotsUrl, $source, respectRobots: false);

        try {
            $request = Http::timeout(5)
                ->connectTimeout(3)
                ->withUserAgent((string) config('page_intelligence.fetch.user_agent', 'ArguslyPageIntelligence/1.0 (+https://argusly.com)'))
                ->withOptions(array_replace_recursive($this->guardedHttpOptions($robotsUrl, $source), [
                    'allow_redirects' => [
                        'max' => max(0, (int) config('page_intelligence.fetch.redirect_limit', 5)),
                        'track_redirects' => true,
                        'on_redirect' => function ($request, $response, $uri) use ($source): void {
                            unset($request, $response);
                            $this->validateRedirectTarget((string) $uri, $source);
                        },
                    ],
                ]));

            $response = $request->get($robotsUrl);
        } catch (\Throwable) {
            return ['allow' => [], 'disallow' => []];
        }

        if (! $response->successful()) {
            return ['allow' => [], 'disallow' => []];
        }

        try {
            $this->assertResponseAllowed(
                response: $response,
                url: $robotsUrl,
                allowedContentTypes: ['text/plain', 'text/html', 'application/octet-stream'],
                maxBytes: (int) config('page_intelligence.safety.robots_max_bytes', 500000),
                source: $source,
            );
        } catch (InvalidArgumentException) {
            return ['allow' => [], 'disallow' => []];
        }

        $applies = false;
        $rules = ['allow' => [], 'disallow' => []];
        foreach (preg_split('/\R/', (string) $response->body()) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'user-agent') {
                $applies = $value === '*' || str_contains(strtolower($value), 'argusly');
            } elseif ($applies && in_array($key, ['allow', 'disallow'], true) && $value !== '') {
                $rules[$key][] = $value;
            }
        }

        return [
            'allow' => array_values(array_unique($rules['allow'])),
            'disallow' => array_values(array_unique($rules['disallow'])),
        ];
    }

    private function domainMatches(string $host, string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $domain = ltrim($domain, '.');

        return $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    private function shouldRespectRobots(?MonitoredSource $source): bool
    {
        $policy = (array) ($source?->crawl_policy_json ?? []);

        foreach (['respect_robots_txt', 'respect_robots'] as $key) {
            if (array_key_exists($key, $policy)) {
                return (bool) $policy[$key];
            }
        }

        return true;
    }

    /**
     * @param array{allow?:array<int,string>,disallow?:array<int,string>} $rules
     */
    private function robotsAllowsPath(array $rules, string $path): bool
    {
        $allowedMatch = $this->longestRobotsMatch((array) ($rules['allow'] ?? []), $path);
        $disallowedMatch = $this->longestRobotsMatch((array) ($rules['disallow'] ?? []), $path);

        if ($disallowedMatch === 0) {
            return true;
        }

        return $allowedMatch >= $disallowedMatch;
    }

    /**
     * @param array<int,string> $patterns
     */
    private function longestRobotsMatch(array $patterns, string $path): int
    {
        $longest = 0;

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';
            if (preg_match($regex, $path) === 1) {
                $longest = max($longest, strlen($pattern));
            }
        }

        return $longest;
    }

    /**
     * @param array<int,string> $ips
     * @return array<int,string>
     */
    private function curlResolveEntries(string $url, array $ips): array
    {
        if (! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $host = $this->host($url);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443));

        return collect($ips)
            ->map(fn (string $ip): string => $host.':'.$port.':'.$ip)
            ->values()
            ->all();
    }
}
