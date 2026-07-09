<?php

namespace App\Support\Intelligence\Testing;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\IntelligenceSignalSource;
use App\Support\Intelligence\ReasoningContext;
use App\Support\Intelligence\ReasoningInput;
use App\Support\Intelligence\ReasoningOutput;
use App\Support\Intelligence\ReasoningPipelineProjector;
use App\Support\Intelligence\ReasoningStage;
use App\Support\Intelligence\TimeWindow;

class FakeReasoningPipelineProjector implements ReasoningPipelineProjector
{
    /**
     * @var array<int, ReasoningOutput>
     */
    private array $outputs = [];

    public function project(ReasoningContext $context, ReasoningInput $input, ReasoningStage $targetStage): ReasoningOutput
    {
        $evidence = EvidenceBag::merge($context->evidence, $input->evidence);
        $graphReferences = $this->graphReferences($context, $input);
        $confidence = $input->confidence ?? 0.5;
        $priority = $input->priority;
        $timeWindow = $input->timeWindow ?? $context->timeWindow;
        $key = $this->keyFor($input, $targetStage);

        $output = $targetStage === ReasoningStage::SIGNAL
            ? $this->signalOutput($key, $context, $input, $evidence, $graphReferences, $confidence, $priority, $timeWindow)
            : new ReasoningOutput(
                key: $key,
                stage: $targetStage,
                type: $targetStage->value.'_from_'.$input->stage->value,
                summary: $targetStage->value.' projected from '.$input->stage->value,
                payload: [
                    'input_key' => $input->key,
                    'input_stage' => $input->stage->value,
                    'target_stage' => $targetStage->value,
                    'projector' => 'fake',
                ],
                evidence: $evidence,
                timeWindow: $timeWindow,
                graphReferences: $graphReferences,
                confidence: $confidence,
                priority: $priority,
                metadata: [
                    'projector' => 'fake',
                    'deterministic' => true,
                ],
                provenance: [
                    'projector' => self::class,
                    'provider' => 'fake',
                    'external_llm' => false,
                ],
            );

        $this->outputs[] = $output;

        return $output;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function outputs(): array
    {
        return array_map(
            fn (ReasoningOutput $output): array => $output->toArray(),
            $this->outputs,
        );
    }

    /**
     * @param  array<int, IntelligenceGraphReference>  $graphReferences
     */
    private function signalOutput(
        string $key,
        ReasoningContext $context,
        ReasoningInput $input,
        EvidenceBag $evidence,
        array $graphReferences,
        float $confidence,
        int|float|null $priority,
        ?TimeWindow $timeWindow,
    ): ReasoningOutput {
        $subject = $context->subject ?? $input->toGraphReference();
        $signal = new IntelligenceSignal(
            type: 'reasoning_signal',
            subject: $subject,
            metric: 'reasoning_progress',
            value: 1,
            baseline: 0,
            confidence: $confidence,
            evidence: $evidence->toSignalEvidence(),
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            source: new IntelligenceSignalSource(
                provider: 'fake_reasoning_projector',
                key: $key,
                metadata: ['external_llm' => false],
            ),
            metadata: [
                'projector' => 'fake',
                'deterministic' => true,
            ],
            provenance: [
                'projector' => self::class,
                'provider' => 'fake',
            ],
            key: $key,
        );

        return ReasoningOutput::fromSignal(
            signal: $signal,
            evidence: $evidence,
            priority: $priority,
            metadata: [
                'projector' => 'fake',
                'deterministic' => true,
            ],
            provenance: [
                'projector' => self::class,
                'provider' => 'fake',
                'external_llm' => false,
            ],
        );
    }

    /**
     * @return array<int, IntelligenceGraphReference>
     */
    private function graphReferences(ReasoningContext $context, ReasoningInput $input): array
    {
        $references = [];

        foreach ([
            ...$context->graphReferences,
            $input->toGraphReference(),
            ...$input->graphReferences,
        ] as $reference) {
            $references[$reference->graphKey()] = $reference;
        }

        return array_values($references);
    }

    private function keyFor(ReasoningInput $input, ReasoningStage $targetStage): string
    {
        return 'fake-'.$targetStage->value.':'.hash('sha1', implode('|', [
            $input->stage->value,
            $input->key,
            $targetStage->value,
        ]));
    }
}
