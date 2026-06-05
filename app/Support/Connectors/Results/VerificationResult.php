<?php

namespace App\Support\Connectors\Results;

/**
 * Result object for verification operations.
 *
 * Encapsulates the outcome of checking whether published content still
 * exists on the remote destination.
 *
 * ## Status Values
 *
 * - `exists`: Content exists and is accessible
 * - `missing`: Content no longer exists (404)
 * - `trashed`: Content exists but is in trash/deleted state
 * - `error`: Unable to verify (network error, auth failure)
 * - `unknown`: Verification not supported or inconclusive
 */
final class VerificationResult
{
    public const STATUS_EXISTS = 'exists';
    public const STATUS_MISSING = 'missing';
    public const STATUS_TRASHED = 'trashed';
    public const STATUS_ERROR = 'error';
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $remoteStatus = null,
        public readonly ?string $remoteUrl = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $httpStatus = null,
        public readonly ?\DateTimeInterface $lastModified = null,
        /** @var array<string, mixed> Additional metadata from remote */
        public readonly array $meta = [],
    ) {}

    /**
     * Create a successful verification (content exists).
     *
     * @param array<string, mixed> $meta
     */
    public static function exists(
        ?string $remoteStatus = 'published',
        ?string $remoteUrl = null,
        ?int $httpStatus = null,
        ?\DateTimeInterface $lastModified = null,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            status: self::STATUS_EXISTS,
            remoteStatus: $remoteStatus,
            remoteUrl: $remoteUrl,
            httpStatus: $httpStatus,
            lastModified: $lastModified,
            meta: $meta,
        );
    }

    /**
     * Create a missing verification (content not found).
     *
     * @param array<string, mixed> $meta
     */
    public static function missing(
        ?int $httpStatus = 404,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            status: self::STATUS_MISSING,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * Create a trashed verification (content in trash).
     *
     * @param array<string, mixed> $meta
     */
    public static function trashed(
        ?string $remoteUrl = null,
        ?int $httpStatus = null,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            status: self::STATUS_TRASHED,
            remoteStatus: 'trash',
            remoteUrl: $remoteUrl,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * Create an error verification (unable to check).
     *
     * @param array<string, mixed> $meta
     */
    public static function error(
        string $errorCode,
        string $errorMessage,
        ?int $httpStatus = null,
        array $meta = [],
    ): self {
        return new self(
            success: false,
            status: self::STATUS_ERROR,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * Create an unknown verification (verification not supported).
     *
     * @param array<string, mixed> $meta
     */
    public static function unknown(string $reason = 'Verification not supported', array $meta = []): self
    {
        return new self(
            success: true,
            status: self::STATUS_UNKNOWN,
            errorMessage: $reason,
            meta: $meta,
        );
    }

    /**
     * Check if verification was successful (no errors occurred).
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the content exists on remote.
     */
    public function doesExist(): bool
    {
        return $this->status === self::STATUS_EXISTS;
    }

    /**
     * Check if the content is missing from remote.
     */
    public function isMissing(): bool
    {
        return $this->status === self::STATUS_MISSING;
    }

    /**
     * Check if the content is trashed on remote.
     */
    public function isTrashed(): bool
    {
        return $this->status === self::STATUS_TRASHED;
    }

    /**
     * Check if verification encountered an error.
     */
    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Check if the remote resource is healthy (exists and not trashed).
     */
    public function isHealthy(): bool
    {
        return $this->doesExist() && $this->remoteStatus !== 'trash';
    }

    /**
     * Check if the remote resource is gone (missing or trashed).
     */
    public function isGone(): bool
    {
        return $this->isMissing() || $this->isTrashed();
    }

    /**
     * Convert to array for logging/storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'remote_status' => $this->remoteStatus,
            'remote_url' => $this->remoteUrl,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'http_status' => $this->httpStatus,
            'last_modified' => $this->lastModified?->format('Y-m-d\TH:i:s\Z'),
            'meta' => $this->meta,
        ];
    }
}
