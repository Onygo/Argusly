<?php

namespace App\Agents\Data;

use App\Agents\Support\AgentRunStatus;
use DateTimeInterface;

class AgentWorkflowResult
{
    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly string $workflowKey,
        public readonly string $status,
        public readonly string $summary = '',
        public readonly array $steps = [],
        public readonly array $metrics = [],
        public readonly array $rawPayload = [],
        public readonly ?DateTimeInterface $startedAt = null,
        public readonly ?DateTimeInterface $finishedAt = null,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $rawPayload
     */
    public static function make(
        string $workflowKey,
        string $status,
        string $summary = '',
        array $steps = [],
        array $metrics = [],
        array $rawPayload = [],
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
    ): self {
        return new self(
            workflowKey: $workflowKey,
            status: $status,
            summary: trim($summary),
            steps: $steps,
            metrics: $metrics,
            rawPayload: $rawPayload,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    public static function skipped(
        string $workflowKey,
        string $summary,
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
    ): self {
        return self::make(
            workflowKey: $workflowKey,
            status: AgentRunStatus::SKIPPED->value,
            summary: $summary,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workflow_key' => $this->workflowKey,
            'status' => $this->status,
            'summary' => $this->summary,
            'steps' => $this->steps,
            'metrics' => $this->metrics,
            'raw_payload' => $this->rawPayload,
            'started_at' => $this->startedAt?->format(DateTimeInterface::ATOM),
            'finished_at' => $this->finishedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}

