<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;
use InvalidArgumentException;

class ReasoningPipeline
{
    /**
     * @var array<int, ReasoningStage>
     */
    private array $stages;

    /**
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * @var array<string, mixed>
     */
    private array $provenance;

    /**
     * @param  array<int, ReasoningStage|IntelligenceStage|string>  $stages
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        private readonly string $key,
        private readonly ReasoningPipelineProjector $projector,
        array $stages = [],
        array $metadata = [],
        array $provenance = [],
    ) {
        $this->stages = $this->normalizeStages($stages === [] ? ReasoningStage::ordered() : $stages);
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<int, ReasoningStage>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    /**
     * @return array<int, string>
     */
    public function stageValues(): array
    {
        return array_map(
            fn (ReasoningStage $stage): string => $stage->value,
            $this->stages,
        );
    }

    /**
     * @return array<int, array{from:string,to:string}>
     */
    public function transitions(): array
    {
        $transitions = [];

        foreach (array_values($this->stages) as $index => $stage) {
            $next = $this->stages[$index + 1] ?? null;

            if (! $next instanceof ReasoningStage) {
                continue;
            }

            $transitions[] = [
                'from' => $stage->value,
                'to' => $next->value,
            ];
        }

        return $transitions;
    }

    public function run(ReasoningInput $input, ?ReasoningContext $context = null): ReasoningResult
    {
        $context ??= new ReasoningContext($this->key);
        $startIndex = $this->indexOf($input->stage);

        if ($startIndex === null) {
            throw new InvalidArgumentException(sprintf(
                'Reasoning pipeline [%s] does not include the input stage [%s].',
                $this->key,
                $input->stage->value,
            ));
        }

        $current = $input;
        $steps = [];

        foreach (array_slice($this->stages, $startIndex + 1) as $targetStage) {
            $output = $this->projector->project($context, $current, $targetStage);
            $steps[] = new ReasoningStep(
                input: $current,
                output: $output,
                evidence: $context->evidence,
                timeWindow: $output->timeWindow ?? $context->timeWindow ?? $current->timeWindow,
                graphReferences: $context->graphReferences,
                confidence: $output->confidence ?? $current->confidence,
                priority: $output->priority ?? $current->priority,
                provenance: [
                    'pipeline' => $this->key,
                    'projector' => $this->projector::class,
                ] + $this->provenance,
            );
            $current = $output->toInput();
        }

        $trace = new ReasoningTrace($steps);
        $lastStep = $trace->lastStep();

        return new ReasoningResult(
            pipelineKey: $this->key,
            context: $context,
            input: $input,
            output: $lastStep?->output ?? new ReasoningOutput(
                key: $input->key,
                stage: $input->stage,
                type: $input->type,
                summary: $input->summary,
                payload: $input->payload,
                evidence: $input->evidence,
                timeWindow: $input->timeWindow,
                graphReferences: $input->graphReferences,
                confidence: $input->confidence,
                priority: $input->priority,
                metadata: $input->metadata,
                provenance: $input->provenance,
            ),
            trace: $trace,
            metadata: [
                'stages' => $this->stageValues(),
                'steps_count' => count($steps),
            ] + $this->metadata,
            provenance: [
                'pipeline' => $this->key,
                'projector' => $this->projector::class,
            ] + $this->provenance,
        );
    }

    /**
     * @param  array<int, ReasoningStage|IntelligenceStage|string>  $stages
     * @return array<int, ReasoningStage>
     */
    private function normalizeStages(array $stages): array
    {
        $normalized = [];

        foreach ($stages as $stage) {
            $stage = ReasoningStage::normalize($stage);
            $normalized[$stage->value] = $stage;
        }

        $ordered = array_values(array_filter(
            ReasoningStage::ordered(),
            fn (ReasoningStage $stage): bool => isset($normalized[$stage->value]),
        ));

        if (count($ordered) < 2) {
            throw new InvalidArgumentException('Reasoning pipelines require at least two stages.');
        }

        return $ordered;
    }

    private function indexOf(ReasoningStage $stage): ?int
    {
        foreach ($this->stages as $index => $candidate) {
            if ($candidate === $stage) {
                return $index;
            }
        }

        return null;
    }
}
