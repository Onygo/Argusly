<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Records delivery events for publication audit trail.
 *
 * Each delivery attempt, verification, or state change is recorded here
 * for debugging and compliance purposes.
 */
class ContentDeliveryEvent extends Model
{
    use HasFactory;
    use HasUuids;

    // Event types
    public const TYPE_VERIFY_REMOTE = 'verify_remote';
    public const TYPE_CREATE_REMOTE = 'create_remote';
    public const TYPE_UPDATE_REMOTE = 'update_remote';
    public const TYPE_RECREATE_REMOTE = 'recreate_remote';
    public const TYPE_FAIL_REMOTE = 'fail_remote';
    public const TYPE_RECONCILE = 'reconcile';

    // Event statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'content_publication_id',
        'event_type',
        'status',
        'message',
        'request_payload_json',
        'response_payload_json',
        'http_status',
        'correlation_id',
        'duration_ms',
    ];

    protected $casts = [
        'request_payload_json' => 'array',
        'response_payload_json' => 'array',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
    ];

    // Relationships

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ContentPublication::class, 'content_publication_id');
    }

    // Query scopes

    public function scopeForPublication($query, string $publicationId)
    {
        return $query->where('content_publication_id', $publicationId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    // Status helpers

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    // Factory methods

    /**
     * Record a successful create event.
     */
    public static function recordCreate(
        ContentPublication $publication,
        array $request = [],
        array $response = [],
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?int $durationMs = null
    ): self {
        return self::create([
            'content_publication_id' => $publication->id,
            'event_type' => self::TYPE_CREATE_REMOTE,
            'status' => self::STATUS_SUCCESS,
            'message' => 'Remote resource created successfully.',
            'request_payload_json' => self::truncatePayload($request),
            'response_payload_json' => self::truncatePayload($response),
            'http_status' => $httpStatus,
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Record a successful update event.
     */
    public static function recordUpdate(
        ContentPublication $publication,
        array $request = [],
        array $response = [],
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?int $durationMs = null
    ): self {
        return self::create([
            'content_publication_id' => $publication->id,
            'event_type' => self::TYPE_UPDATE_REMOTE,
            'status' => self::STATUS_SUCCESS,
            'message' => 'Remote resource updated successfully.',
            'request_payload_json' => self::truncatePayload($request),
            'response_payload_json' => self::truncatePayload($response),
            'http_status' => $httpStatus,
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Record a recreate event (remote was deleted, we created a new one).
     */
    public static function recordRecreate(
        ContentPublication $publication,
        string $previousRemoteId,
        array $request = [],
        array $response = [],
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?int $durationMs = null
    ): self {
        return self::create([
            'content_publication_id' => $publication->id,
            'event_type' => self::TYPE_RECREATE_REMOTE,
            'status' => self::STATUS_SUCCESS,
            'message' => "Remote resource recreated. Previous ID: {$previousRemoteId}",
            'request_payload_json' => self::truncatePayload($request),
            'response_payload_json' => self::truncatePayload($response),
            'http_status' => $httpStatus,
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Record a verification event.
     */
    public static function recordVerify(
        ContentPublication $publication,
        bool $exists,
        ?array $response = null,
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?int $durationMs = null
    ): self {
        return self::create([
            'content_publication_id' => $publication->id,
            'event_type' => self::TYPE_VERIFY_REMOTE,
            'status' => $exists ? self::STATUS_SUCCESS : self::STATUS_FAILED,
            'message' => $exists ? 'Remote resource verified to exist.' : 'Remote resource not found.',
            'response_payload_json' => $response ? self::truncatePayload($response) : null,
            'http_status' => $httpStatus,
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Record a failure event.
     */
    public static function recordFailure(
        ContentPublication $publication,
        string $errorMessage,
        ?string $errorCode = null,
        array $request = [],
        array $response = [],
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?int $durationMs = null
    ): self {
        $message = $errorCode
            ? "[{$errorCode}] {$errorMessage}"
            : $errorMessage;

        return self::create([
            'content_publication_id' => $publication->id,
            'event_type' => self::TYPE_FAIL_REMOTE,
            'status' => self::STATUS_FAILED,
            'message' => Str::limit($message, 2000, ''),
            'request_payload_json' => self::truncatePayload($request),
            'response_payload_json' => self::truncatePayload($response),
            'http_status' => $httpStatus,
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Record a successful reconciliation event.
     * Used when a failed/uncertain delivery is confirmed to have succeeded.
     */
    public static function recordReconcile(
        ContentPublication $publication,
        bool $success,
        array $response = [],
        ?string $correlationId = null
    ): self {
        $message = $success
            ? 'Delivery reconciled: post found on remote.'
            : 'Reconciliation attempted: post not found on remote.';

        return self::create([
            'content_publication_id' => $publication->id,
            'event_type' => self::TYPE_RECONCILE,
            'status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILED,
            'message' => $message,
            'request_payload_json' => [],
            'response_payload_json' => self::truncatePayload($response),
            'http_status' => null,
            'correlation_id' => $correlationId,
            'duration_ms' => null,
        ]);
    }

    /**
     * Truncate large payloads to prevent database bloat.
     * Keeps key fields, truncates large content fields.
     */
    private static function truncatePayload(array $payload, int $maxLength = 50000): array
    {
        $largeFields = ['content', 'content_html', 'body', 'html', 'response_body'];

        foreach ($largeFields as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && strlen($payload[$field]) > 1000) {
                $payload[$field] = Str::limit($payload[$field], 1000, '...[truncated]');
            }
        }

        $json = json_encode($payload);
        if ($json !== false && strlen($json) > $maxLength) {
            return [
                '_truncated' => true,
                '_original_size' => strlen($json),
                '_keys' => array_keys($payload),
            ];
        }

        return $payload;
    }
}
