<?php

namespace App\Support\Errors;

use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Value object for presenting automation errors in views.
 *
 * Encapsulates error display logic and access-level checks.
 *
 * @implements Arrayable<string, mixed>
 */
class AutomationErrorPresenter implements Arrayable
{
    private readonly string $publicErrorCode;

    private readonly string $publicErrorTitle;

    private readonly string $publicErrorMessage;

    private readonly string $supportHint;

    private readonly string $adminSummary;

    private readonly string $technicalDetails;

    private readonly bool $isSensitive;

    private readonly string $supportCode;

    private readonly ?string $failedAt;

    public function __construct(
        private readonly PublicAutomationErrorMapper $mapper,
        ?string $lastErrorCode,
        ?string $lastErrorMessage,
        ?string $failureStage = null,
        ?array $metadata = null,
        ?string $runId = null,
        ?string $itemId = null,
        ?string $failedAt = null,
    ) {
        $mapped = $this->mapper->mapFromRunItem(
            $lastErrorCode,
            $lastErrorMessage,
            $failureStage,
            $metadata,
        );

        $this->publicErrorCode = $mapped['public_error_code'];
        $this->publicErrorTitle = $mapped['public_error_title'];
        $this->publicErrorMessage = $mapped['public_error_message'];
        $this->supportHint = $mapped['support_hint'];
        $this->adminSummary = $mapped['admin_summary'];
        $this->technicalDetails = $mapped['technical_details'];
        $this->isSensitive = $mapped['is_sensitive'];
        $this->supportCode = $this->mapper->generateSupportCode($this->publicErrorCode, $runId, $itemId);
        $this->failedAt = $failedAt;
    }

    /**
     * Create a presenter from a ContentAutomationRunItem model.
     */
    public static function fromRunItem(ContentAutomationRunItem $item): self
    {
        return new self(
            mapper: app(PublicAutomationErrorMapper::class),
            lastErrorCode: $item->last_error_code,
            lastErrorMessage: $item->last_error_message,
            failureStage: $item->failure_stage,
            metadata: is_array($item->metadata) ? $item->metadata : null,
            runId: $item->automation_run_id,
            itemId: (string) $item->id,
            failedAt: $item->finished_at?->toDateTimeString(),
        );
    }

    /**
     * Create a presenter from a ContentAutomationRun model.
     */
    public static function fromRun(ContentAutomationRun $run): self
    {
        return new self(
            mapper: app(PublicAutomationErrorMapper::class),
            lastErrorCode: data_get($run->metadata, 'last_error_code'),
            lastErrorMessage: $run->error_message,
            failureStage: data_get($run->metadata, 'last_failure_stage'),
            metadata: is_array($run->metadata) ? $run->metadata : null,
            runId: (string) $run->id,
            itemId: null,
            failedAt: $run->finished_at?->toDateTimeString(),
        );
    }

    /**
     * Create a presenter from raw error data (useful for item arrays from metadata).
     *
     * @param  array{last_error_code?: string|null, last_error_message?: string|null, failure_stage?: string|null, error_code?: string|null, error?: string|null, stage?: string|null}  $data
     */
    public static function fromArray(array $data, ?string $runId = null, ?string $itemId = null): self
    {
        return new self(
            mapper: app(PublicAutomationErrorMapper::class),
            lastErrorCode: $data['last_error_code'] ?? $data['error_code'] ?? null,
            lastErrorMessage: $data['last_error_message'] ?? $data['error'] ?? null,
            failureStage: $data['failure_stage'] ?? $data['stage'] ?? null,
            metadata: $data,
            runId: $runId,
            itemId: $itemId,
            failedAt: $data['finished_at'] ?? null,
        );
    }

    public function publicErrorCode(): string
    {
        return $this->publicErrorCode;
    }

    public function publicErrorTitle(): string
    {
        return $this->publicErrorTitle;
    }

    public function publicErrorMessage(): string
    {
        return $this->publicErrorMessage;
    }

    public function supportHint(): string
    {
        return $this->supportHint;
    }

    public function supportCode(): string
    {
        return $this->supportCode;
    }

    public function failedAt(): ?string
    {
        return $this->failedAt;
    }

    /**
     * Get admin-only summary (more detailed but still safe).
     */
    public function adminSummary(): string
    {
        return $this->adminSummary;
    }

    /**
     * Get full technical details (may contain sensitive info).
     */
    public function technicalDetails(): string
    {
        return $this->technicalDetails;
    }

    /**
     * Check if technical details contain sensitive information.
     */
    public function isSensitive(): bool
    {
        return $this->isSensitive;
    }

    /**
     * Check if there is any error to display.
     */
    public function hasError(): bool
    {
        return $this->technicalDetails !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'public_error_code' => $this->publicErrorCode,
            'public_error_title' => $this->publicErrorTitle,
            'public_error_message' => $this->publicErrorMessage,
            'support_hint' => $this->supportHint,
            'support_code' => $this->supportCode,
            'failed_at' => $this->failedAt,
            'admin_summary' => $this->adminSummary,
            'technical_details' => $this->technicalDetails,
            'is_sensitive' => $this->isSensitive,
        ];
    }
}
