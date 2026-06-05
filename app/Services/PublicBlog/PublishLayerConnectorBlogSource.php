<?php

namespace App\Services\PublicBlog;

use App\Contracts\PublicBlogSource;
use App\Exceptions\PublicBlogSourceUnavailableException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PublishLayerConnectorBlogSource implements PublicBlogSource
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly MarketingBlogSourceScope $sourceScope
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('publishlayer_connector.public_blog.use_connector', config('publishlayer.public_blog.use_connector', true));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchPublishedPosts(): array
    {
        if (! $this->isEnabled()) {
            throw new PublicBlogSourceUnavailableException('Argusly connector blog source is disabled.');
        }

        $scope = $this->sourceScope->resolve();
        // Root cause: connector requests without scope could return posts from other clients.
        // Fix: do not request global posts; require an explicit marketing blog source.
        if (! $scope) {
            return [];
        }

        try {
            $response = $this->request()->get($this->endpoint(), $this->queryParams($scope));

            if ($response->failed()) {
                throw new PublicBlogSourceUnavailableException(
                    sprintf('Argusly connector blog source failed with status %d.', $response->status())
                );
            }

            return $this->extractPosts($response);
        } catch (PublicBlogSourceUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PublicBlogSourceUnavailableException('Argusly connector blog source is currently unavailable.', previous: $e);
        }
    }

    private function endpoint(): string
    {
        return (string) config('publishlayer_connector.public_blog.connector_endpoint', config('publishlayer.public_blog.connector_endpoint', '/v1/public/blog/posts'));
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * @param array{mode:string,id:string} $scope
     * @return array<string,mixed>
     */
    private function queryParams(array $scope): array
    {
        $params = [
            'limit' => max(1, (int) config('publishlayer_connector.public_blog.max_posts', config('publishlayer.public_blog.max_posts', 300))),
        ];

        $scopeParam = $this->sourceScope->queryParamForMode($scope['mode']);
        if ($scopeParam) {
            $params[$scopeParam] = $scope['id'];
        }

        return $params;
    }

    private function request(): PendingRequest
    {
        $connection = (array) config('publishlayer_connector.connections.default', []);
        $apiConfig = (array) config('publishlayer_connector.api', []);
        $httpConfig = (array) config('publishlayer_connector.http', []);
        $baseUrl = (string) ($connection['base_url'] ?? ($apiConfig['base_url'] ?? config('publishlayer_connector.base_url', 'https://api.publishlayer.com')));

        $request = $this->http
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($httpConfig['timeout_seconds'] ?? 10))
            ->retry(
                (int) ($httpConfig['retries'] ?? 2),
                (int) ($httpConfig['retry_sleep_ms'] ?? 200),
                throw: false
            );

        $requestOptions = $this->requestOptionsFor($baseUrl);
        if ($requestOptions !== []) {
            $request = $request->withOptions($requestOptions);
        }

        $apiKey = trim((string) ($connection['api_key'] ?? ($apiConfig['api_key'] ?? config('publishlayer_connector.api_key', ''))));
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $workspaceId = trim((string) ($connection['workspace_id'] ?? ($apiConfig['workspace_id'] ?? config('publishlayer_connector.workspace_id', ''))));
        if ($workspaceId !== '') {
            $request = $request->withHeaders([
                'X-PublishLayer-Workspace' => $workspaceId,
            ]);
        }

        return $request;
    }

    /**
     * Local development workaround for self signed certs; do not enable in production.
     *
     * @return array<string,mixed>
     */
    private function requestOptionsFor(string $url): array
    {
        $flagEnabled = (bool) config('publishlayer_connector.http_insecure_local', false);
        if (! $flagEnabled) {
            return [];
        }

        if ($this->isProductionEnvironment()) {
            throw new RuntimeException('PUBLISHLAYER_HTTP_INSECURE_LOCAL cannot be enabled in production.');
        }

        if (! $this->isLocalDevelopmentEnvironment()) {
            return [];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! $this->isLocalHost($host)) {
            return [];
        }

        Log::debug('TLS verify disabled for local outbound call', [
            'host' => $host,
            'env' => (string) config('app.env'),
        ]);

        return ['verify' => false];
    }

    private function isLocalDevelopmentEnvironment(): bool
    {
        if (app()->environment(['local', 'development'])) {
            return true;
        }

        return in_array(strtolower((string) config('app.env', '')), ['local', 'development', 'dev'], true);
    }

    private function isProductionEnvironment(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        return strtolower((string) config('app.env', '')) === 'production';
    }

    private function isLocalHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractPosts(Response $response): array
    {
        /** @var mixed $json */
        $json = $response->json();

        if (is_array($json) && array_is_list($json)) {
            return $this->normalizePosts($json);
        }

        if (! is_array($json)) {
            return [];
        }

        $posts = Arr::get($json, 'posts');
        if (! is_array($posts) || ! array_is_list($posts)) {
            $posts = Arr::get($json, 'data');
        }
        if (! is_array($posts) || ! array_is_list($posts)) {
            return [];
        }

        return $this->normalizePosts($posts);
    }

    /**
     * @param array<int,mixed> $posts
     * @return array<int,array<string,mixed>>
     */
    private function normalizePosts(array $posts): array
    {
        return collect($posts)
            ->map(function ($post): array {
                if (! is_array($post)) {
                    return [];
                }

                return $post;
            })
            ->filter(fn (array $post): bool => $post !== [])
            ->values()
            ->all();
    }
}
