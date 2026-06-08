<?php

namespace App\Services\WordPress;

use App\Services\WordPress\Data\WordPressPost;
use App\Services\WordPress\Data\WordPressPostLookupResult;
use App\Services\WordPress\Exceptions\ForbiddenException;
use App\Services\WordPress\Exceptions\MalformedResponseException;
use App\Services\WordPress\Exceptions\NotFoundException;
use App\Services\WordPress\Exceptions\TransportException;
use App\Services\WordPress\Exceptions\UnauthorizedException;
use App\Services\WordPress\Exceptions\ValidationException;
use App\Services\WordPress\Exceptions\WordPressConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WordPressConnector
{
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $token = '',
    ) {
    }

    public function forSite(string $baseUrl, string $token): self
    {
        return new self(
            baseUrl: rtrim(trim($baseUrl), '/'),
            token: trim($token),
        );
    }

    /**
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws TransportException
     * @throws MalformedResponseException
     */
    public function getPost(string $id): WordPressPost
    {
        $id = trim($id);
        if ($id === '' || $this->baseUrl === '' || $this->token === '') {
            throw new TransportException('WordPress connector is not configured for post retrieval.');
        }

        $encodedId = rawurlencode($id);
        $endpoints = [
            $this->baseUrl . '/wp-json/argusly/v1/posts/' . $encodedId,
            $this->baseUrl . '/?rest_route=/argusly/v1/posts/' . $encodedId,
            $this->baseUrl . '/wp-json/wp/v2/posts/' . $encodedId . '?context=edit',
            $this->baseUrl . '/wp-json/wp/v2/posts/' . $encodedId,
        ];

        $lastTransport = null;
        $lastNotFoundStatus = null;

        foreach ($endpoints as $endpoint) {
            try {
                $response = $this->request('GET', $endpoint);
            } catch (TransportException $exception) {
                $lastTransport = $exception;
                continue;
            }

            if ($response->successful()) {
                return $this->normalizePostResponse($response, $id);
            }

            if (in_array($response->status(), [404, 405], true)) {
                $lastNotFoundStatus = $response->status();
                continue;
            }

            throw $this->mapHttpException($response, $endpoint, 'WordPress rejected the post lookup request.');
        }

        if ($lastTransport instanceof TransportException && $lastNotFoundStatus === null) {
            throw $lastTransport;
        }

        throw new NotFoundException(
            'WordPress post was not found.',
            $lastNotFoundStatus ?? 404,
        );
    }

    /**
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws TransportException
     * @throws MalformedResponseException
     */
    public function postExists(string $id): WordPressPostLookupResult
    {
        try {
            $post = $this->getPost($id);

            return new WordPressPostLookupResult(
                exists: true,
                post: $post,
                httpStatus: $post->httpStatus,
            );
        } catch (NotFoundException $exception) {
            return new WordPressPostLookupResult(
                exists: false,
                post: null,
                httpStatus: $exception->statusCode() ?? 404,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws TransportException
     * @throws MalformedResponseException
     */
    public function createPost(array $payload): WordPressPost
    {
        $lastTransport = null;
        $lastNotFoundStatus = null;

        foreach ($this->createEndpoints() as $endpoint) {
            try {
                $response = $this->request('POST', $endpoint, payload: $payload);
            } catch (TransportException $exception) {
                $lastTransport = $exception;
                continue;
            }

            if ($response->successful()) {
                return $this->normalizePostResponse($response);
            }

            if (in_array($response->status(), [404, 405], true)) {
                $lastNotFoundStatus = $response->status();
                continue;
            }

            throw $this->mapHttpException($response, $endpoint, 'WordPress rejected the create request.');
        }

        if ($lastTransport instanceof TransportException && $lastNotFoundStatus === null) {
            throw $lastTransport;
        }

        throw new NotFoundException(
            'WordPress connector create endpoint was not found.',
            $lastNotFoundStatus ?? 404,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws TransportException
     * @throws MalformedResponseException
     */
    public function updatePost(string $id, array $payload): WordPressPost
    {
        $id = trim($id);
        if ($id === '') {
            throw new ValidationException('WordPress update requires a post identifier.');
        }

        $lastTransport = null;
        $lastNotFoundStatus = null;

        foreach ($this->updateEndpoints($id) as $endpoint) {
            try {
                $response = $this->request('POST', $endpoint, payload: $payload);
            } catch (TransportException $exception) {
                $lastTransport = $exception;
                continue;
            }

            if ($response->successful()) {
                return $this->normalizePostResponse($response, $id);
            }

            if (in_array($response->status(), [404, 405], true)) {
                $lastNotFoundStatus = $response->status();
                continue;
            }

            throw $this->mapHttpException($response, $endpoint, 'WordPress rejected the update request.');
        }

        if ($lastTransport instanceof TransportException && $lastNotFoundStatus === null) {
            throw $lastTransport;
        }

        throw new NotFoundException(
            'WordPress connector update endpoint was not found.',
            $lastNotFoundStatus ?? 404,
        );
    }

    /**
     * @param  array<string, scalar|null>  $criteria
     *
     * @throws UnauthorizedException
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws TransportException
     * @throws MalformedResponseException
     */
    public function findPostByMeta(array $criteria): ?WordPressPost
    {
        $criteria = array_filter(
            $criteria,
            static fn ($value) => $value !== null && trim((string) $value) !== ''
        );

        if ($criteria === [] || $this->baseUrl === '' || $this->token === '') {
            return null;
        }

        $lastTransport = null;

        foreach ($this->lookupEndpoints() as $endpoint) {
            try {
                $response = $this->request('GET', $endpoint, query: $criteria);
            } catch (TransportException $exception) {
                $lastTransport = $exception;
                continue;
            }

            if ($response->successful()) {
                return $this->normalizeLookupResponse($response);
            }

            if (in_array($response->status(), [404, 405], true)) {
                continue;
            }

            throw $this->mapHttpException($response, $endpoint, 'WordPress rejected the lookup request.');
        }

        if ($lastTransport instanceof TransportException) {
            throw $lastTransport;
        }

        return null;
    }

    private function newRequest(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withOptions([
                'verify' => app()->environment('local') ? false : true,
            ])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $payload
     *
     * @throws TransportException
     */
    private function request(string $method, string $endpoint, array $query = [], ?array $payload = null): Response
    {
        try {
            return match (strtoupper($method)) {
                'GET' => $this->newRequest()->get($endpoint, $query),
                'POST' => $this->newRequest()->post($endpoint, $payload ?? []),
                default => throw new TransportException('Unsupported WordPress connector HTTP method.'),
            };
        } catch (ConnectionException $exception) {
            throw new TransportException(
                'WordPress transport failed before a response was received.',
                null,
                $endpoint,
                null,
                $exception,
            );
        } catch (WordPressConnectorException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new TransportException(
                'WordPress transport failed before a response was received.',
                null,
                $endpoint,
                null,
                $exception,
            );
        }
    }

    /**
     * @throws MalformedResponseException
     */
    private function normalizePostResponse(Response $response, ?string $fallbackId = null): WordPressPost
    {
        $body = (string) $response->body();
        $decoded = $response->json();

        if (! is_array($decoded)) {
            $reason = $this->diagnoseMalformedResponse($body, $decoded);

            throw new MalformedResponseException(
                'WordPress returned a malformed post response. ' . $reason,
                $response->status(),
                (string) $response->effectiveUri(),
                $body,
            );
        }

        // Check for explicit "exists: false" response from the plugin (not found)
        if (isset($decoded['exists']) && $decoded['exists'] === false) {
            throw new NotFoundException(
                $decoded['error'] ?? 'WordPress post not found.',
                $response->status(),
                (string) $response->effectiveUri(),
                $decoded,
            );
        }

        return WordPressPost::fromPayload($decoded, $fallbackId, $response->status());
    }

    /**
     * @throws MalformedResponseException
     */
    private function normalizeLookupResponse(Response $response): ?WordPressPost
    {
        $body = (string) $response->body();
        $decoded = $response->json();

        if (! is_array($decoded)) {
            $reason = $this->diagnoseMalformedResponse($body, $decoded);

            throw new MalformedResponseException(
                'WordPress returned a malformed lookup response. ' . $reason,
                $response->status(),
                (string) $response->effectiveUri(),
                $body,
            );
        }

        // Handle explicit "exists: false" response from WordPress plugin
        if (isset($decoded['exists']) && $decoded['exists'] === false) {
            return null;
        }

        try {
            return WordPressPost::fromPayload($decoded, httpStatus: $response->status());
        } catch (MalformedResponseException) {
            foreach ([$decoded, data_get($decoded, 'data'), data_get($decoded, 'items'), data_get($decoded, 'posts')] as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                if (array_is_list($candidate)) {
                    $first = $candidate[0] ?? null;
                    if (! is_array($first)) {
                        return null;
                    }

                    return WordPressPost::fromPayload($first, httpStatus: $response->status());
                }
            }
        }

        return null;
    }

    private function mapHttpException(Response $response, string $endpoint, string $fallbackMessage): WordPressConnectorException
    {
        $body = $response->json();
        $decodedBody = is_array($body) ? $body : (string) $response->body();
        $message = $this->extractRemoteErrorMessage($response, $fallbackMessage);

        return match ($response->status()) {
            401 => new UnauthorizedException($message, 401, $endpoint, $decodedBody),
            403 => new ForbiddenException($message, 403, $endpoint, $decodedBody),
            404 => new NotFoundException($message, 404, $endpoint, $decodedBody),
            400, 409, 422 => new ValidationException($message, $response->status(), $endpoint, $decodedBody),
            default => new WordPressConnectorException($message, $response->status(), $endpoint, $decodedBody),
        };
    }

    private function extractRemoteErrorMessage(Response $response, string $fallback): string
    {
        $decoded = $response->json();
        if (is_array($decoded)) {
            foreach (['message', 'error', 'data.message', 'data.error'] as $path) {
                $value = trim((string) data_get($decoded, $path, ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $body = trim((string) $response->body());

        return $body !== '' ? $body : $fallback;
    }

    /**
     * Diagnose why a response is considered malformed.
     */
    private function diagnoseMalformedResponse(string $body, mixed $decoded): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return 'Response body was empty.';
        }

        if (str_starts_with($trimmed, '<')) {
            // Likely HTML response
            if (stripos($trimmed, '<!DOCTYPE') !== false || stripos($trimmed, '<html') !== false) {
                return 'Response was HTML instead of JSON (possibly a frontend page or error page).';
            }

            return 'Response started with < suggesting HTML or XML content.';
        }

        if ($decoded === null) {
            return 'Response was not valid JSON (json_decode returned null).';
        }

        if (is_bool($decoded)) {
            return sprintf('Response decoded to boolean %s instead of an object.', $decoded ? 'true' : 'false');
        }

        if (is_string($decoded)) {
            return sprintf('Response decoded to string "%s" instead of an object.', mb_substr($decoded, 0, 100));
        }

        if (is_numeric($decoded)) {
            return sprintf('Response decoded to number %s instead of an object.', $decoded);
        }

        return 'Response was not a JSON object or array.';
    }

    /**
     * @return array<int, string>
     */
    private function createEndpoints(): array
    {
        return [
            $this->baseUrl . '/wp-json/argusly/v1/posts',
            $this->baseUrl . '/?rest_route=/argusly/v1/posts',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function updateEndpoints(string $id): array
    {
        $encodedId = rawurlencode($id);

        return [
            $this->baseUrl . '/wp-json/argusly/v1/posts/' . $encodedId,
            $this->baseUrl . '/?rest_route=/argusly/v1/posts/' . $encodedId,
            ...$this->createEndpoints(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function lookupEndpoints(): array
    {
        return [
            $this->baseUrl . '/wp-json/argusly/v1/posts/lookup',
            $this->baseUrl . '/?rest_route=/argusly/v1/posts/lookup',
            ...$this->createEndpoints(),
        ];
    }
}
