<?php

namespace App\Support\Errors;

use Illuminate\Support\Str;
use Throwable;

/**
 * Maps technical automation errors to user-friendly error presentations.
 *
 * Regular users see only public-safe messages and error codes.
 * Admins can access additional technical details for debugging.
 */
class PublicAutomationErrorMapper
{
    /**
     * Standard error code patterns for automation failures.
     */
    public const CODE_SOURCE_TRUNCATION = 'PL-CNT-SRC-001';
    public const CODE_TITLE_TRUNCATION = 'PL-CNT-TTL-001';
    public const CODE_CONTENT_TOO_LONG = 'PL-CNT-LEN-001';
    public const CODE_JOB_TIMEOUT = 'PL-JOB-TMO-001';
    public const CODE_INSUFFICIENT_CREDITS = 'PL-CREDITS-INSUFFICIENT';
    public const CODE_GENERATION_FAILED = 'PL-GEN-ERR-001';
    public const CODE_PUBLISH_FAILED = 'PL-PUB-ERR-001';
    public const CODE_PERSISTENCE_FAILED = 'PL-PRS-ERR-001';
    public const CODE_SITE_RESOLUTION_FAILED = 'PL-STE-ERR-001';
    public const CODE_PROMPT_FAILED = 'PL-PRM-ERR-001';
    public const CODE_UNEXPECTED = 'PL-AUTO-UNX-001';

    /**
     * Error pattern definitions mapping technical indicators to public codes.
     *
     * @var array<string, array{code: string, title: string, message: string, support_hint: string}>
     */
    private const ERROR_PATTERNS = [
        // SQL truncation errors
        'source_column_truncation' => [
            'code' => self::CODE_SOURCE_TRUNCATION,
            'title' => 'Content source data exceeded storage limits',
            'message' => 'One of the source fields contained more data than expected. The automation could not complete.',
            'support_hint' => 'This may be caused by unusually long input data. Try simplifying the source content.',
        ],
        'title_column_truncation' => [
            'code' => self::CODE_TITLE_TRUNCATION,
            'title' => 'Content title exceeded storage limits',
            'message' => 'The generated title was too long to store. The automation could not complete.',
            'support_hint' => 'The title field has a character limit. Consider using shorter topic descriptions.',
        ],
        'data_too_long' => [
            'code' => self::CODE_CONTENT_TOO_LONG,
            'title' => 'Content data exceeded storage limits',
            'message' => 'Some content data was too large to store. The automation could not complete.',
            'support_hint' => 'This typically happens when input or generated content is unusually large.',
        ],
        'queue_timeout' => [
            'code' => self::CODE_JOB_TIMEOUT,
            'title' => 'Automation timed out',
            'message' => 'The automation took longer than expected to complete and was stopped.',
            'support_hint' => 'This can happen during high traffic. Try running the automation again.',
        ],
        'insufficient_credits' => [
            'code' => self::CODE_INSUFFICIENT_CREDITS,
            'title' => 'Insufficient credits',
            'message' => 'There were not enough credits to complete this automation run.',
            'support_hint' => 'Add more credits to your workspace to resume automation runs.',
        ],
        'generation_failed' => [
            'code' => self::CODE_GENERATION_FAILED,
            'title' => 'Content generation failed',
            'message' => 'The AI content generation process encountered an issue and could not complete.',
            'support_hint' => 'Try running the automation again. If the issue persists, contact support.',
        ],
        'publish_failed' => [
            'code' => self::CODE_PUBLISH_FAILED,
            'title' => 'Content publication failed',
            'message' => 'The content was generated but could not be published to the destination.',
            'support_hint' => 'Check your publication destination settings and credentials.',
        ],
        'persistence_failed' => [
            'code' => self::CODE_PERSISTENCE_FAILED,
            'title' => 'Content could not be saved',
            'message' => 'There was a problem saving the generated content.',
            'support_hint' => 'This is usually a temporary issue. Try running the automation again.',
        ],
        'site_resolution_failed' => [
            'code' => self::CODE_SITE_RESOLUTION_FAILED,
            'title' => 'Site configuration issue',
            'message' => 'The automation could not access the required site configuration.',
            'support_hint' => 'Check that the target site is properly configured in your workspace.',
        ],
        'prompt_failed' => [
            'code' => self::CODE_PROMPT_FAILED,
            'title' => 'Prompt generation issue',
            'message' => 'There was a problem preparing the content generation request.',
            'support_hint' => 'Check your automation settings and brand voice configuration.',
        ],
    ];

    /**
     * Map a technical error to a public-safe error presentation.
     *
     * @param  Throwable|string|null  $error  Exception or stored error message
     * @param  string|null  $errorCode  Existing error code (e.g., from run item)
     * @param  string|null  $failureStage  Failure stage (e.g., 'persistence', 'generation')
     * @param  array<string, mixed>|null  $metadata  Additional context from run/item metadata
     * @return array{
     *     public_error_code: string,
     *     public_error_title: string,
     *     public_error_message: string,
     *     support_hint: string,
     *     admin_summary: string,
     *     technical_details: string,
     *     is_sensitive: bool
     * }
     */
    public function map(
        Throwable|string|null $error,
        ?string $errorCode = null,
        ?string $failureStage = null,
        ?array $metadata = null,
    ): array {
        $technicalMessage = $this->extractTechnicalMessage($error);
        $exceptionClass = $error instanceof Throwable ? $error::class : null;

        // Determine the error pattern based on technical indicators
        $pattern = $this->detectPattern($technicalMessage, $errorCode, $failureStage, $exceptionClass, $metadata);
        $mapped = self::ERROR_PATTERNS[$pattern] ?? null;
        $publicMessage = $this->buildPublicMessage($pattern, $mapped['message'] ?? null, $metadata);

        // Build admin summary (non-sensitive but more detailed)
        $adminSummary = $this->buildAdminSummary($pattern, $errorCode, $failureStage, $exceptionClass, $metadata);
        $technicalDetails = $this->buildTechnicalDetails($technicalMessage, $metadata);

        // Check if technical message contains sensitive information
        $isSensitive = $this->containsSensitiveInfo($technicalDetails);

        return [
            'public_error_code' => $mapped['code'] ?? self::CODE_UNEXPECTED,
            'public_error_title' => $mapped['title'] ?? 'Automation encountered an error',
            'public_error_message' => $publicMessage ?? 'The automation could not complete due to an unexpected issue.',
            'support_hint' => $mapped['support_hint'] ?? 'Please try again. If the issue persists, contact support with the error code shown.',
            'admin_summary' => $adminSummary,
            'technical_details' => $technicalDetails,
            'is_sensitive' => $isSensitive,
        ];
    }

    /**
     * Map from stored run item data (the most common use case in views).
     *
     * @return array{
     *     public_error_code: string,
     *     public_error_title: string,
     *     public_error_message: string,
     *     support_hint: string,
     *     admin_summary: string,
     *     technical_details: string,
     *     is_sensitive: bool
     * }
     */
    public function mapFromRunItem(
        ?string $lastErrorCode,
        ?string $lastErrorMessage,
        ?string $failureStage = null,
        ?array $metadata = null,
    ): array {
        return $this->map(
            error: $lastErrorMessage,
            errorCode: $lastErrorCode,
            failureStage: $failureStage,
            metadata: $metadata,
        );
    }

    /**
     * Map from a run-level error (error_message field on ContentAutomationRun).
     *
     * @return array{
     *     public_error_code: string,
     *     public_error_title: string,
     *     public_error_message: string,
     *     support_hint: string,
     *     admin_summary: string,
     *     technical_details: string,
     *     is_sensitive: bool
     * }
     */
    public function mapFromRunError(
        ?string $errorMessage,
        ?array $metadata = null,
    ): array {
        $lastErrorCode = data_get($metadata, 'last_error_code');
        $failureStage = data_get($metadata, 'last_failure_stage');

        return $this->map(
            error: $errorMessage,
            errorCode: $lastErrorCode,
            failureStage: $failureStage,
            metadata: $metadata,
        );
    }

    /**
     * Detect which error pattern matches the given indicators.
     */
    private function detectPattern(
        string $message,
        ?string $errorCode,
        ?string $failureStage,
        ?string $exceptionClass,
        ?array $metadata = null,
    ): string {
        $messageLower = strtolower($message);

        if ((string) data_get($metadata, 'failure_pattern') === 'insufficient_credits') {
            return 'insufficient_credits';
        }

        // Check for specific SQL truncation patterns (SQLSTATE 1265)
        if (
            str_contains($messageLower, 'sqlstate') &&
            (str_contains($messageLower, '1265') || str_contains($messageLower, 'data truncated'))
        ) {
            // Try to identify which column
            if (str_contains($messageLower, 'source') || str_contains($messageLower, 'ai_payload')) {
                return 'source_column_truncation';
            }
            if (str_contains($messageLower, 'title')) {
                return 'title_column_truncation';
            }

            return 'data_too_long';
        }

        // Check for "Data too long" MySQL errors (SQLSTATE 22001)
        if (
            str_contains($messageLower, 'data too long') ||
            (str_contains($messageLower, 'sqlstate') && str_contains($messageLower, '22001'))
        ) {
            if (str_contains($messageLower, 'title')) {
                return 'title_column_truncation';
            }
            if (str_contains($messageLower, 'source') || str_contains($messageLower, 'ai_payload')) {
                return 'source_column_truncation';
            }

            return 'data_too_long';
        }

        // Check error code for known patterns
        if ($errorCode !== null) {
            $codeLower = strtolower($errorCode);

            if ($codeLower === 'insufficient_credits') {
                return 'insufficient_credits';
            }

            if (str_contains($codeLower, 'timeout')) {
                return 'queue_timeout';
            }
        }

        // Check exception class
        if ($exceptionClass !== null) {
            $classBasename = class_basename($exceptionClass);

            if (str_contains($classBasename, 'Timeout') || str_contains($classBasename, 'TimeoutException')) {
                return 'queue_timeout';
            }

            if (str_contains($classBasename, 'InsufficientCredits')) {
                return 'insufficient_credits';
            }
        }

        // Check failure stage for general categorization
        if ($failureStage !== null) {
            return match ($failureStage) {
                'persistence' => 'persistence_failed',
                'generation' => 'generation_failed',
                'publish' => 'publish_failed',
                'site_resolution' => 'site_resolution_failed',
                'prompt' => 'prompt_failed',
                default => 'generation_failed',
            };
        }

        // Check message for keywords
        if (str_contains($messageLower, 'timeout') || str_contains($messageLower, 'timed out')) {
            return 'queue_timeout';
        }

        if (str_contains($messageLower, 'credit')) {
            return 'insufficient_credits';
        }

        if (str_contains($messageLower, 'publish') || str_contains($messageLower, 'delivery')) {
            return 'publish_failed';
        }

        if (str_contains($messageLower, 'save') || str_contains($messageLower, 'persist') || str_contains($messageLower, 'database')) {
            return 'persistence_failed';
        }

        // Default to generic generation failure
        return 'generation_failed';
    }

    /**
     * Extract the technical error message from various input types.
     */
    private function extractTechnicalMessage(Throwable|string|null $error): string
    {
        if ($error === null) {
            return '';
        }

        if ($error instanceof Throwable) {
            return $error->getMessage();
        }

        return $error;
    }

    /**
     * Build an admin-level summary that provides useful context without full stack traces.
     */
    private function buildAdminSummary(
        string $pattern,
        ?string $errorCode,
        ?string $failureStage,
        ?string $exceptionClass,
        ?array $metadata,
    ): string {
        $parts = [];

        if ($exceptionClass !== null) {
            $parts[] = 'Exception: ' . class_basename($exceptionClass);
        }

        if ($failureStage !== null) {
            $parts[] = 'Stage: ' . $failureStage;
        }

        if ($errorCode !== null && $errorCode !== '') {
            $parts[] = 'Code: ' . $errorCode;
        }

        $parts[] = 'Pattern: ' . $pattern;

        // Add relevant metadata hints
        if ($metadata !== null) {
            if ($pattern === 'insufficient_credits') {
                if (($exception = data_get($metadata, 'failure_details.exception_class')) !== null) {
                    $parts[] = 'Exception: ' . $exception;
                }
                if (($required = data_get($metadata, 'failure_details.required_credits')) !== null) {
                    $parts[] = 'Required: ' . $required;
                }
                if (($available = data_get($metadata, 'failure_details.available_credits')) !== null) {
                    $parts[] = 'Available: ' . $available;
                }
                if (($job = data_get($metadata, 'failure_details.job')) !== null) {
                    $parts[] = 'Job: ' . $job;
                }
                if (($runId = data_get($metadata, 'failure_details.run_id')) !== null) {
                    $parts[] = 'Run ID: ' . $runId;
                }
                if (($automationId = data_get($metadata, 'failure_details.automation_id')) !== null) {
                    $parts[] = 'Automation ID: ' . $automationId;
                }
            }

            if (($jobId = data_get($metadata, 'job_id')) !== null) {
                $parts[] = 'Job: ' . Str::limit((string) $jobId, 16);
            }
        }

        return implode(' | ', $parts);
    }

    private function buildPublicMessage(string $pattern, ?string $defaultMessage, ?array $metadata): ?string
    {
        if ($pattern !== 'insufficient_credits') {
            return $defaultMessage;
        }

        return data_get($metadata, 'failure_details.user_safe_message', $defaultMessage);
    }

    private function buildTechnicalDetails(string $technicalMessage, ?array $metadata): string
    {
        $adminMessage = trim((string) data_get($metadata, 'failure_details.admin_message', ''));
        if ($adminMessage !== '') {
            return $adminMessage;
        }

        return $technicalMessage;
    }

    /**
     * Check if the technical message contains information that should not be shown to regular users.
     */
    private function containsSensitiveInfo(string $message): bool
    {
        $messageLower = strtolower($message);

        $sensitivePatterns = [
            'sqlstate',
            'mysql',
            'pgsql',
            'postgresql',
            'sql error',
            'query exception',
            '/var/www',
            '/home/',
            '/app/',
            'stack trace',
            'pdo exception',
            'connection refused',
            'access denied',
            'authentication failed',
            'password',
            'secret',
            'api_key',
            'apikey',
            'bearer',
            'token',
            '.php:',
            '->',
            '::',
            'vendor/',
            'artisan',
            'laravel',
            'illuminate',
            'table_name',
            'column_name',
            'deadlock',
            'lock wait',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($messageLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a copyable support reference code for a given error.
     */
    public function generateSupportCode(string $publicErrorCode, ?string $runId = null, ?string $itemId = null): string
    {
        $timestamp = now()->format('ymdHi');
        $runSuffix = $runId ? '-' . substr((string) $runId, 0, 8) : '';
        $itemSuffix = $itemId ? '-' . substr((string) $itemId, 0, 4) : '';

        return strtoupper($publicErrorCode . $runSuffix . $itemSuffix . '-' . $timestamp);
    }
}
