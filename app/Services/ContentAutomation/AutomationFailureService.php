<?php

namespace App\Services\ContentAutomation;

use App\Exceptions\InsufficientCreditsException;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

class AutomationFailureService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function persistFailure(
        ContentAutomation $automation,
        ?ContentAutomationRun $run,
        Throwable $exception,
        array $context = []
    ): ?ContentAutomationRun {
        $run ??= ContentAutomationRun::query()
            ->where('automation_id', (string) $automation->id)
            ->latest('created_at')
            ->first();

        $diagnostics = $this->exceptionDiagnostics($exception);
        $message = $this->preferredFailureMessage($run, $exception, $diagnostics, $context);

        if ($run instanceof ContentAutomationRun) {
            $metadata = is_array($run->metadata) ? $run->metadata : [];
            $metadata['real_error'] = $diagnostics;
            $metadata['last_error_code'] = $context['error_code'] ?? ($metadata['last_error_code'] ?? $this->defaultErrorCode($exception));
            $metadata['last_error_message'] = $diagnostics['message'];
            $metadata['last_failure_stage'] = $context['failure_stage'] ?? ($metadata['last_failure_stage'] ?? 'job');
            $metadata['job_failure'] = array_filter([
                'job_id' => $context['job_id'] ?? null,
                'attempt' => $context['attempt'] ?? null,
                'max_tries' => $context['max_tries'] ?? null,
                'queue' => $context['queue'] ?? null,
                'locale' => $context['locale'] ?? null,
                'chain_size' => $context['chain_size'] ?? null,
                'workspace_id' => $context['workspace_id'] ?? null,
                'client_site_id' => $context['client_site_id'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'last_attempt_at' => now(),
                'attempt_count' => max((int) $run->attempt_count, (int) ($context['attempt'] ?? 0)),
                'error_message' => $message,
                'metadata' => $metadata,
            ])->save();
        }

        $automation->forceFill([
            'last_failure_message' => $message,
            'last_failure_code' => $context['error_code'] ?? $this->defaultErrorCode($exception),
            'last_failure_run_id' => $run?->id,
            'last_failure_at' => now(),
        ])->save();

        return $run?->fresh();
    }

    public function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ValidationException) {
            return false;
        }

        if ($exception instanceof AuthorizationException) {
            return false;
        }

        if ($exception instanceof InsufficientCreditsException) {
            return false;
        }

        if ($exception instanceof QueryException) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout') || str_contains($message, 'server has gone away')) {
                return true;
            }

            return false;
        }

        $message = strtolower($exception->getMessage());

        $permanentPatterns = [
            'validation',
            'insufficient credits',
            'duplicate content',
            'already exists',
            'no usable site context',
            'invalid locale',
            'data truncated',
            'string data, right truncated',
            'too long for column',
            'enum',
            'permission',
            'forbidden',
            'unauthorized',
            'not allowed',
            'integrity constraint',
        ];

        foreach ($permanentPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return false;
            }
        }

        $retryablePatterns = [
            'timeout',
            'timed out',
            'temporarily unavailable',
            'connection reset',
            'connection refused',
            'connection error',
            'rate limit',
            'too many requests',
            'bad gateway',
            'service unavailable',
            'gateway timeout',
            'openai',
            'anthropic',
            'provider unavailable',
            'network',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function exceptionDiagnostics(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())
                ->take(8)
                ->map(fn (array $frame): array => array_filter([
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'class' => $frame['class'] ?? null,
                    'function' => $frame['function'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''))
                ->values()
                ->all(),
        ];
    }

    private function preferredFailureMessage(
        ?ContentAutomationRun $run,
        Throwable $exception,
        array $diagnostics,
        array $context
    ): string {
        $genericQueueMessage = str_contains(strtolower($exception->getMessage()), 'attempted too many times');
        $realMessage = trim((string) ($diagnostics['message'] ?? ''));
        $storedMessage = trim((string) data_get($run?->metadata, 'real_error.message', data_get($run?->metadata, 'last_error_message', $run?->error_message)));

        if ($genericQueueMessage && $storedMessage !== '' && ! str_contains(strtolower($storedMessage), 'attempted too many times')) {
            return sprintf(
                'Job stopped after too many attempts. Original error: %s',
                $storedMessage
            );
        }

        if (($context['preserve_real_error'] ?? false) && $storedMessage !== '' && ! str_contains(strtolower($storedMessage), 'attempted too many times')) {
            return $storedMessage;
        }

        return $realMessage !== '' ? $realMessage : 'Automation execution failed.';
    }

    private function defaultErrorCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => 'validation_exception',
            $exception instanceof AuthorizationException => 'authorization_exception',
            $exception instanceof InsufficientCreditsException => 'insufficient_credits',
            $exception instanceof QueryException => 'query_exception',
            default => strtolower(class_basename($exception::class)),
        };
    }
}
