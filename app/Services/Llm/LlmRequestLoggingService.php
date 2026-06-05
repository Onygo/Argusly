<?php

namespace App\Services\Llm;

use App\Models\LlmRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LlmRequestLoggingService
{
    public function __construct(private readonly LlmCostEstimator $costEstimator)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function log(array $payload): void
    {
        try {
            $provider = (string) ($payload['provider'] ?? 'unknown');
            $model = $this->nullableString($payload['model'] ?? null);
            $inputTokens = max(0, (int) ($payload['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($payload['output_tokens'] ?? 0));
            $cost = $this->costEstimator->estimate($provider, $model, $inputTokens, $outputTokens);
            $metadata = $this->sanitizeMetadata((array) ($payload['metadata'] ?? []));
            $metadata['cost'] = $cost;

            LlmRequest::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $this->nullableString($payload['workspace_id'] ?? null),
                'site_id' => $this->nullableString($payload['site_id'] ?? null),
                'user_id' => $payload['user_id'] ?? null,
                'feature' => (string) ($payload['feature'] ?? 'unknown'),
                'modality' => (string) ($payload['modality'] ?? 'text'),
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => max(0, (int) ($payload['total_tokens'] ?? 0)),
                'credits_consumed' => round((float) ($payload['credits_consumed'] ?? 0), 4),
                'input_cost_eur' => round((float) $cost['input_cost'], 8),
                'output_cost_eur' => round((float) $cost['output_cost'], 8),
                'total_cost_eur' => round((float) $cost['total_cost'], 8),
                'latency_ms' => isset($payload['latency_ms']) ? max(0, (int) $payload['latency_ms']) : null,
                'status' => (string) ($payload['status'] ?? 'success'),
                'error_type' => $this->nullableString($payload['error_type'] ?? null),
                'error_message' => $this->truncate($payload['error_message'] ?? null, 1200),
                'error_code' => $this->nullableString($payload['error_code'] ?? null),
                'request_id' => $this->nullableString($payload['request_id'] ?? null),
                'job_id' => $this->nullableString($payload['job_id'] ?? null),
                'retry_count' => max(0, (int) ($payload['retry_count'] ?? 0)),
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $forbiddenKeys = [
            'prompt',
            'prompts',
            'messages',
            'raw_prompt',
            'raw_prompts',
            'system_prompt',
            'user_prompt',
            'assistant_prompt',
            'input',
            'response_raw',
        ];

        $clean = Arr::except($metadata, $forbiddenKeys);

        if (isset($clean['provider_raw']) && is_array($clean['provider_raw'])) {
            $clean['provider_raw'] = $this->redactProviderRaw((array) $clean['provider_raw']);
        }

        return $clean;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function redactProviderRaw(array $raw): array
    {
        $raw = Arr::except($raw, ['input', 'messages', 'prompt', 'prompts']);
        $raw = $this->stripLargeBinaryFields($raw);

        $json = json_encode($raw);
        if (! is_string($json)) {
            return [];
        }

        if (strlen($json) <= 4000) {
            return $raw;
        }

        return [
            'truncated' => true,
            'preview' => mb_substr($json, 0, 4000),
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function stripLargeBinaryFields(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : (string) $key;
            if (in_array($normalizedKey, ['b64_json', 'data', 'inline_data', 'inlinedata'], true) && is_string($item)) {
                $result[$key] = '[omitted_binary length=' . strlen($item) . ']';
                continue;
            }

            $result[$key] = $this->stripLargeBinaryFields($item);
        }

        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v !== '' ? $v : null;
    }

    private function truncate(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? mb_substr($trimmed, 0, $max) : null;
    }
}
