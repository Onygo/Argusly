<?php

namespace App\Agents\Data;

use App\Agents\Support\AgentRunStatus;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use UnitEnum;

class AgentResult
{
    /**
     * @param array<int,array<string,mixed>> $suggestions
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string|array<string,mixed>> $warnings
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $rawPayload
     */
    public function __construct(
        public readonly string $agentKey,
        public readonly string $status,
        public readonly string $summary = '',
        public readonly array $suggestions = [],
        public readonly array $actions = [],
        public readonly array $warnings = [],
        public readonly array $metrics = [],
        public readonly array $rawPayload = [],
        public readonly ?DateTimeInterface $startedAt = null,
        public readonly ?DateTimeInterface $finishedAt = null,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $suggestions
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string|array<string,mixed>> $warnings
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $rawPayload
     */
    public static function success(
        string $agentKey,
        string $summary = '',
        array $suggestions = [],
        array $actions = [],
        array $warnings = [],
        array $metrics = [],
        array $rawPayload = [],
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
    ): self {
        return new self(
            agentKey: $agentKey,
            status: AgentRunStatus::SUCCESS->value,
            summary: trim($summary),
            suggestions: self::normalizeList($suggestions),
            actions: self::normalizeList($actions),
            warnings: self::normalizeList($warnings),
            metrics: self::normalizeMap($metrics),
            rawPayload: self::normalizeMap($rawPayload),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    /**
     * @param array<int,string|array<string,mixed>> $warnings
     * @param array<string,mixed> $rawPayload
     */
    public static function skipped(
        string $agentKey,
        string $summary = 'Agent does not support the provided context.',
        array $warnings = [],
        array $rawPayload = [],
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
    ): self {
        return new self(
            agentKey: $agentKey,
            status: AgentRunStatus::SKIPPED->value,
            summary: trim($summary),
            warnings: self::normalizeList($warnings),
            rawPayload: self::normalizeMap($rawPayload),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $suggestions
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string|array<string,mixed>> $warnings
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $rawPayload
     */
    public static function warning(
        string $agentKey,
        string $summary,
        array $suggestions = [],
        array $actions = [],
        array $warnings = [],
        array $metrics = [],
        array $rawPayload = [],
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
    ): self {
        return new self(
            agentKey: $agentKey,
            status: AgentRunStatus::WARNING->value,
            summary: trim($summary),
            suggestions: self::normalizeList($suggestions),
            actions: self::normalizeList($actions),
            warnings: self::normalizeList($warnings),
            metrics: self::normalizeMap($metrics),
            rawPayload: self::normalizeMap($rawPayload),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    /**
     * @param array<int,string|array<string,mixed>> $warnings
     * @param array<string,mixed> $rawPayload
     */
    public static function failed(
        string $agentKey,
        string $summary,
        array $warnings = [],
        array $rawPayload = [],
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
    ): self {
        return new self(
            agentKey: $agentKey,
            status: AgentRunStatus::FAILED->value,
            summary: trim($summary),
            warnings: self::normalizeList($warnings),
            rawPayload: self::normalizeMap($rawPayload),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    public function withAgentKey(string $agentKey): self
    {
        return new self(
            agentKey: trim($agentKey),
            status: $this->status,
            summary: $this->summary,
            suggestions: $this->suggestions,
            actions: $this->actions,
            warnings: $this->warnings,
            metrics: $this->metrics,
            rawPayload: $this->rawPayload,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
        );
    }

    public function withLifecycle(?DateTimeInterface $startedAt, ?DateTimeInterface $finishedAt): self
    {
        return new self(
            agentKey: $this->agentKey,
            status: $this->status,
            summary: $this->summary,
            suggestions: $this->suggestions,
            actions: $this->actions,
            warnings: $this->warnings,
            metrics: $this->metrics,
            rawPayload: $this->rawPayload,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'agent_key' => $this->agentKey,
            'status' => $this->status,
            'summary' => $this->summary,
            'suggestions' => $this->suggestions,
            'actions' => $this->actions,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
            'raw_payload' => $this->rawPayload,
            'started_at' => $this->startedAt?->format(DateTimeInterface::ATOM),
            'finished_at' => $this->finishedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function normalizeList(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): mixed => self::normalizeValue($value),
            $values
        ));
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function normalizeMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::normalizeMap($value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Arrayable) {
            return self::normalizeValue($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return self::normalizeValue($value->jsonSerialize());
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return method_exists($value, '__toString')
            ? (string) $value
            : ['type' => $value::class];
    }
}
