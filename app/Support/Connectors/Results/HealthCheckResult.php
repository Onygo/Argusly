<?php

namespace App\Support\Connectors\Results;

/**
 * Result object for health check operations.
 *
 * Encapsulates the outcome of checking destination connectivity,
 * authentication, and basic functionality.
 *
 * ## Status Values
 *
 * - `healthy`: Destination is fully functional
 * - `degraded`: Destination is functional but with issues
 * - `unhealthy`: Destination is not functional
 * - `unknown`: Unable to determine health status
 */
final class HealthCheckResult
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNHEALTHY = 'unhealthy';
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?int $httpStatus = null,
        public readonly ?float $latencyMs = null,
        /** @var array<string, mixed> Additional diagnostics */
        public readonly array $diagnostics = [],
    ) {}

    /**
     * Create a healthy result.
     *
     * @param array<string, mixed> $diagnostics
     */
    public static function healthy(
        string $message = 'Connection healthy',
        ?int $httpStatus = 200,
        ?float $latencyMs = null,
        array $diagnostics = [],
    ): self {
        return new self(
            ok: true,
            status: self::STATUS_HEALTHY,
            message: $message,
            httpStatus: $httpStatus,
            latencyMs: $latencyMs,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Create a degraded result.
     *
     * @param array<string, mixed> $diagnostics
     */
    public static function degraded(
        string $message,
        ?int $httpStatus = null,
        ?float $latencyMs = null,
        array $diagnostics = [],
    ): self {
        return new self(
            ok: true,
            status: self::STATUS_DEGRADED,
            message: $message,
            httpStatus: $httpStatus,
            latencyMs: $latencyMs,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Create an unhealthy result.
     *
     * @param array<string, mixed> $diagnostics
     */
    public static function unhealthy(
        string $message,
        ?int $httpStatus = null,
        ?float $latencyMs = null,
        array $diagnostics = [],
    ): self {
        return new self(
            ok: false,
            status: self::STATUS_UNHEALTHY,
            message: $message,
            httpStatus: $httpStatus,
            latencyMs: $latencyMs,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Create an unknown result.
     *
     * @param array<string, mixed> $diagnostics
     */
    public static function unknown(
        string $message = 'Unable to determine health status',
        array $diagnostics = [],
    ): self {
        return new self(
            ok: false,
            status: self::STATUS_UNKNOWN,
            message: $message,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Create from Laravel HTTP client response.
     *
     * @param array<string, mixed> $responseBody
     * @param array<string, mixed> $diagnostics
     */
    public static function fromHttpResponse(
        bool $successful,
        int $httpStatus,
        array $responseBody = [],
        ?float $latencyMs = null,
        array $diagnostics = [],
    ): self {
        $ok = $successful && (data_get($responseBody, 'ok', true) !== false);
        $message = trim((string) data_get($responseBody, 'message', ''));

        if ($message === '') {
            $message = $ok
                ? 'Health check succeeded'
                : 'Health check failed';
        }

        $status = match (true) {
            $ok && $httpStatus >= 200 && $httpStatus < 300 => self::STATUS_HEALTHY,
            $ok => self::STATUS_DEGRADED,
            $httpStatus >= 500 => self::STATUS_UNHEALTHY,
            $httpStatus === 401 || $httpStatus === 403 => self::STATUS_UNHEALTHY,
            default => self::STATUS_UNKNOWN,
        };

        return new self(
            ok: $ok,
            status: $status,
            message: $message,
            httpStatus: $httpStatus,
            latencyMs: $latencyMs,
            diagnostics: array_merge($diagnostics, [
                'response_body' => $responseBody,
            ]),
        );
    }

    /**
     * Create from an exception.
     *
     * @param array<string, mixed> $diagnostics
     */
    public static function fromException(\Throwable $exception, array $diagnostics = []): self
    {
        return self::unhealthy(
            message: $exception->getMessage(),
            diagnostics: array_merge($diagnostics, [
                'exception_class' => get_class($exception),
                'exception_code' => $exception->getCode(),
            ]),
        );
    }

    /**
     * Check if the destination is healthy.
     */
    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    /**
     * Check if the destination is degraded.
     */
    public function isDegraded(): bool
    {
        return $this->status === self::STATUS_DEGRADED;
    }

    /**
     * Check if the destination is unhealthy.
     */
    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }

    /**
     * Check if the destination is operational (healthy or degraded).
     */
    public function isOperational(): bool
    {
        return $this->ok;
    }

    /**
     * Get the status label.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_HEALTHY => 'Healthy',
            self::STATUS_DEGRADED => 'Degraded',
            self::STATUS_UNHEALTHY => 'Unhealthy',
            self::STATUS_UNKNOWN => 'Unknown',
            default => ucfirst($this->status),
        };
    }

    /**
     * Convert to array for logging/storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'status' => $this->status,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
            'latency_ms' => $this->latencyMs,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
