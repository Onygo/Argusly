<?php

namespace App\Services\Llm;

use App\Data\Llm\LlmRequest as LlmRequestData;
use App\Data\Llm\LlmResponse;
use App\Models\Account;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\LlmRequest;
use App\Models\User;
use App\Services\CreditService;
use App\Services\DomainEventService;
use Illuminate\Support\Facades\DB;
use Throwable;

class LlmRequestTracker
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly DomainEventService $events,
    ) {}

    public function start(LlmRequestData $request, string $method): LlmRequest
    {
        return LlmRequest::query()->create([
            'account_id' => $this->intMetadata($request, 'account_id'),
            'brand_id' => $this->intMetadata($request, 'brand_id'),
            'user_id' => $this->intMetadata($request, 'user_id'),
            'provider' => $request->provider,
            'model' => $request->model,
            'purpose' => $this->purpose($request),
            'status' => 'running',
            'metadata' => [
                ...($request->metadata ?? []),
                'method' => $method,
                'response_format' => $request->responseFormat,
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
            ],
        ]);
    }

    public function complete(LlmRequest $record, LlmResponse $response): LlmRequest
    {
        return DB::transaction(function () use ($record, $response): LlmRequest {
            $creditsCharged = $this->chargeCredits($record);
            $usage = $response->usage;

            $record->forceFill([
                'status' => 'completed',
                'prompt_tokens' => $usage?->inputTokens,
                'completion_tokens' => $usage?->outputTokens,
                'total_tokens' => $usage?->totalTokens,
                'estimated_cost' => $this->estimatedCost($record, $response),
                'credits_charged' => $creditsCharged,
                'latency_ms' => $response->latencyMs,
                'metadata' => [
                    ...($record->metadata ?? []),
                    'finish_reason' => $response->finishReason,
                    'response_provider' => $response->provider,
                    'response_model' => $response->model,
                ],
                'completed_at' => now(),
            ])->save();

            $this->recordCompletedEvent($record);

            if ($creditsCharged > 0) {
                $this->recordCreditsEvent($record, $creditsCharged);
            }

            return $record->refresh();
        });
    }

    public function fail(LlmRequest $record, Throwable|string $error): LlmRequest
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $record->forceFill([
            'status' => 'failed',
            'error_message' => str($message)->limit(1000)->toString(),
            'completed_at' => now(),
        ])->save();

        if ($record->account_id !== null) {
            $this->events->recordForSubject('LlmRequestFailed', $record, $record->user, [
                'provider' => $record->provider,
                'model' => $record->model,
                'purpose' => $record->purpose,
                'error_message' => $record->error_message,
            ], $record->completed_at, dispatch: false);
        }

        return $record->refresh();
    }

    public function fallbackRequest(LlmRequestData $request, string $provider, string $model, LlmRequest $failed): LlmRequestData
    {
        return new LlmRequestData(
            provider: $provider,
            model: $model,
            messages: $request->messages,
            systemPrompt: $request->systemPrompt,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            responseFormat: $request->responseFormat,
            metadata: [
                ...($request->metadata ?? []),
                'fallback_of_llm_request_id' => $failed->id,
                'fallback_of_llm_request_uuid' => $failed->uuid,
            ],
        );
    }

    private function chargeCredits(LlmRequest $record): int
    {
        if ($record->account_id === null || $record->credits_charged > 0) {
            return 0;
        }

        $costKey = config("llm.credit_cost_keys.{$record->purpose}");

        if (! is_string($costKey) || $costKey === '') {
            return 0;
        }

        $account = Account::query()->find($record->account_id);

        if (! $account) {
            return 0;
        }

        $metadata = [
            'llm_request_id' => $record->id,
            'llm_request_uuid' => $record->uuid,
            'provider' => $record->provider,
            'model' => $record->model,
            'purpose' => $record->purpose,
        ];
        $user = $record->user_id ? User::query()->find($record->user_id) : null;

        $transaction = $user
            ? $this->credits->consume($account, $user, $costKey, "LLM {$record->purpose} request.", $record, $metadata)
            : $this->credits->consumeForAccount($account, $costKey, "LLM {$record->purpose} request.", $record, $metadata);

        return abs($transaction->amount);
    }

    private function estimatedCost(LlmRequest $record, LlmResponse $response): ?float
    {
        $usage = $response->usage;

        if ($usage === null) {
            return null;
        }

        $provider = LlmProvider::query()->where('provider', $record->provider)->first();

        if (! $provider) {
            return null;
        }

        $model = LlmModel::query()
            ->where('provider_id', $provider->id)
            ->where('model', $record->model)
            ->first();

        if (! $model || ($model->input_cost_per_1k === null && $model->output_cost_per_1k === null)) {
            return null;
        }

        $inputCost = ((int) ($usage->inputTokens ?? 0) / 1000) * (float) ($model->input_cost_per_1k ?? 0);
        $outputCost = ((int) ($usage->outputTokens ?? 0) / 1000) * (float) ($model->output_cost_per_1k ?? 0);

        return round($inputCost + $outputCost, 6);
    }

    private function recordCompletedEvent(LlmRequest $record): void
    {
        if ($record->account_id === null) {
            return;
        }

        $this->events->recordForSubject('LlmRequestCompleted', $record, $record->user, [
            'provider' => $record->provider,
            'model' => $record->model,
            'purpose' => $record->purpose,
            'prompt_tokens' => $record->prompt_tokens,
            'completion_tokens' => $record->completion_tokens,
            'total_tokens' => $record->total_tokens,
            'estimated_cost' => $record->estimated_cost,
            'credits_charged' => $record->credits_charged,
            'latency_ms' => $record->latency_ms,
        ], $record->completed_at, dispatch: false);
    }

    private function recordCreditsEvent(LlmRequest $record, int $creditsCharged): void
    {
        if ($record->account_id === null) {
            return;
        }

        $this->events->recordForSubject('LlmCreditsConsumed', $record, $record->user, [
            'provider' => $record->provider,
            'model' => $record->model,
            'purpose' => $record->purpose,
            'credits_charged' => $creditsCharged,
        ], $record->completed_at, dispatch: false);
    }

    private function purpose(LlmRequestData $request): string
    {
        $purpose = $request->metadata['purpose'] ?? null;

        return is_string($purpose) && in_array($purpose, LlmRequest::PURPOSES, true)
            ? $purpose
            : 'agent_task';
    }

    private function intMetadata(LlmRequestData $request, string $key): ?int
    {
        $value = $request->metadata[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
