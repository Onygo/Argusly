<?php

use App\Support\Intelligence\CanonicalEntityReference;
use App\Support\Intelligence\CanonicalEntityType;
use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\IntelligenceStage;
use App\Support\Intelligence\ReasoningContext;
use App\Support\Intelligence\ReasoningInput;
use App\Support\Intelligence\ReasoningOutput;
use App\Support\Intelligence\ReasoningPipeline;
use App\Support\Intelligence\ReasoningStage;
use App\Support\Intelligence\Testing\FakeReasoningPipelineProjector;
use App\Support\Intelligence\TimeWindow;

it('creates reasoning pipelines as in-memory contracts', function (): void {
    $pipeline = new ReasoningPipeline('phase-7-reasoning', new FakeReasoningPipelineProjector());

    expect($pipeline->key())->toBe('phase-7-reasoning')
        ->and($pipeline->stageValues())->toBe([
            'observation',
            'signal',
            'insight',
            'recommendation',
            'action',
            'outcome',
        ])
        ->and($pipeline->transitions())->toBe([
            ['from' => 'observation', 'to' => 'signal'],
            ['from' => 'signal', 'to' => 'insight'],
            ['from' => 'insight', 'to' => 'recommendation'],
            ['from' => 'recommendation', 'to' => 'action'],
            ['from' => 'action', 'to' => 'outcome'],
        ]);
});

it('orders reasoning stages through the intelligence stage progression', function (): void {
    $pipeline = new ReasoningPipeline('ordered-reasoning', new FakeReasoningPipelineProjector(), [
        ReasoningStage::OUTCOME,
        IntelligenceStage::RAW_OBSERVATION,
        'recommendation',
        'signal',
        ReasoningStage::ACTION,
    ]);

    expect(ReasoningStage::OBSERVATION->intelligenceStage())->toBe(IntelligenceStage::RAW_OBSERVATION)
        ->and(ReasoningStage::normalize('raw observation'))->toBe(ReasoningStage::OBSERVATION)
        ->and(ReasoningStage::SIGNAL->precedes(ReasoningStage::INSIGHT))->toBeTrue()
        ->and(ReasoningStage::RECOMMENDATION->next())->toBe(ReasoningStage::ACTION)
        ->and($pipeline->stageValues())->toBe([
            'observation',
            'signal',
            'recommendation',
            'action',
            'outcome',
        ]);
});

it('records an observation-to-signal reasoning trace', function (): void {
    $input = ReasoningInput::observation('obs-traffic-growth', 'Traffic grew on the pricing page');
    $result = (new ReasoningPipeline('observation-signal', new FakeReasoningPipelineProjector(), [
        ReasoningStage::OBSERVATION,
        ReasoningStage::SIGNAL,
    ]))->run($input);

    expect($result->trace->transitions())->toBe(['observation_to_signal'])
        ->and($result->output->stage)->toBe(ReasoningStage::SIGNAL)
        ->and($result->output->artifact)->toBeInstanceOf(IntelligenceSignal::class)
        ->and($result->graphEdges()[0]->type)->toBe('evidences')
        ->and($result->graphEdges()[0]->stage)->toBe(IntelligenceStage::SIGNAL);
});

it('records a signal-to-insight reasoning trace', function (): void {
    $input = new ReasoningInput('signal:traffic-growth', ReasoningStage::SIGNAL, 'traffic_trend');
    $result = (new ReasoningPipeline('signal-insight', new FakeReasoningPipelineProjector(), [
        ReasoningStage::SIGNAL,
        ReasoningStage::INSIGHT,
    ]))->run($input);

    expect($result->trace->transitions())->toBe(['signal_to_insight'])
        ->and($result->output->stage)->toBe(ReasoningStage::INSIGHT)
        ->and($result->graphEdges()[0]->type)->toBe('informs')
        ->and($result->graphEdges()[0]->stage)->toBe(IntelligenceStage::INSIGHT);
});

it('records an insight-to-recommendation reasoning trace', function (): void {
    $input = new ReasoningInput('insight:pricing-momentum', ReasoningStage::INSIGHT, 'growth_insight');
    $result = (new ReasoningPipeline('insight-recommendation', new FakeReasoningPipelineProjector(), [
        ReasoningStage::INSIGHT,
        ReasoningStage::RECOMMENDATION,
    ]))->run($input);

    expect($result->trace->transitions())->toBe(['insight_to_recommendation'])
        ->and($result->output->stage)->toBe(ReasoningStage::RECOMMENDATION)
        ->and($result->graphEdges()[0]->type)->toBe('recommends')
        ->and($result->graphEdges()[0]->stage)->toBe(IntelligenceStage::RECOMMENDATION);
});

it('records a recommendation-to-action reasoning trace', function (): void {
    $input = new ReasoningInput('recommendation:refresh-pricing', ReasoningStage::RECOMMENDATION, 'content_refresh');
    $result = (new ReasoningPipeline('recommendation-action', new FakeReasoningPipelineProjector(), [
        ReasoningStage::RECOMMENDATION,
        ReasoningStage::ACTION,
    ]))->run($input);

    expect($result->trace->transitions())->toBe(['recommendation_to_action'])
        ->and($result->output->stage)->toBe(ReasoningStage::ACTION)
        ->and($result->graphEdges()[0]->type)->toBe('acts_on')
        ->and($result->graphEdges()[0]->stage)->toBe(IntelligenceStage::ACTION);
});

it('records an action-to-outcome reasoning trace', function (): void {
    $input = new ReasoningInput('action:refresh-pricing', ReasoningStage::ACTION, 'content_refresh_completed');
    $result = (new ReasoningPipeline('action-outcome', new FakeReasoningPipelineProjector(), [
        ReasoningStage::ACTION,
        ReasoningStage::OUTCOME,
    ]))->run($input);

    expect($result->trace->transitions())->toBe(['action_to_outcome'])
        ->and($result->output->stage)->toBe(ReasoningStage::OUTCOME)
        ->and($result->graphEdges()[0]->type)->toBe('achieves')
        ->and($result->graphEdges()[0]->stage)->toBe(IntelligenceStage::OUTCOME);
});

it('propagates evidence through pipeline results and traces', function (): void {
    $context = new ReasoningContext(
        key: 'evidence-context',
        evidence: new EvidenceBag([
            EvidenceReference::report('report-1'),
        ]),
    );
    $input = ReasoningInput::observation(
        key: 'obs-1',
        evidence: new EvidenceBag([
            EvidenceReference::marketingObservation('obs-1'),
            EvidenceReference::pageSnapshot('snapshot-1'),
        ]),
    );

    $result = (new ReasoningPipeline('evidence-pipeline', new FakeReasoningPipelineProjector(), [
        ReasoningStage::OBSERVATION,
        ReasoningStage::SIGNAL,
    ]))->run($input, $context);

    expect($result->evidence->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($result->evidence->referenceKeys(EvidenceReference::TYPE_PAGE_SNAPSHOT))->toBe(['snapshot-1'])
        ->and($result->evidence->referenceKeys(EvidenceReference::TYPE_REPORT))->toBe(['report-1'])
        ->and(data_get($result->trace->toArray(), 'evidence.legacy_evidence.marketing_observation_ids'))->toBe(['obs-1']);
});

it('propagates graph references without persistence', function (): void {
    $brand = CanonicalEntityReference::fromName(CanonicalEntityType::BRAND, 'Argusly');
    $context = new ReasoningContext(
        key: 'graph-context',
        subject: $brand,
        graphReferences: [
            IntelligenceGraphReference::page('/pricing', 'Pricing page'),
        ],
    );
    $input = ReasoningInput::observation(
        key: 'obs-graph-1',
        graphReferences: [
            IntelligenceGraphReference::topic('agentic-marketing', 'Agentic marketing'),
        ],
    );

    $result = (new ReasoningPipeline('graph-pipeline', new FakeReasoningPipelineProjector(), [
        ReasoningStage::OBSERVATION,
        ReasoningStage::SIGNAL,
    ]))->run($input, $context);

    expect(collect($result->graphReferences())->map(fn (IntelligenceGraphReference $reference): string => $reference->graphKey())->all())
        ->toContain(
            'entity:brand:argusly',
            'page:/pricing',
            'topic:agentic-marketing',
            'observation:obs-graph-1',
            $result->output->toGraphReference()->graphKey(),
        );
});

it('carries confidence priority provenance and time windows through the reasoning flow', function (): void {
    $window = TimeWindow::between('2026-07-01', '2026-07-07');
    $input = ReasoningInput::observation(
        key: 'obs-confidence',
        timeWindow: $window,
        confidence: 91,
        priority: 120,
        provenance: ['source' => 'unit-test'],
    );

    $result = (new ReasoningPipeline('confidence-pipeline', new FakeReasoningPipelineProjector(), [
        ReasoningStage::OBSERVATION,
        ReasoningStage::SIGNAL,
    ], provenance: ['runner' => 'architecture-test']))->run($input);

    expect($input->confidence)->toBe(0.91)
        ->and($input->priority)->toBe(100)
        ->and($result->confidence)->toBe(0.91)
        ->and($result->priority)->toBe(100)
        ->and(data_get($result->toArray(), 'trace.steps.0.time_window.periods_count'))->toBe(7)
        ->and(data_get($result->toArray(), 'trace.steps.0.provenance.pipeline'))->toBe('confidence-pipeline')
        ->and(data_get($result->toArray(), 'trace.steps.0.provenance.runner'))->toBe('architecture-test');
});

it('redacts metadata and provenance on reasoning payloads', function (): void {
    $context = new ReasoningContext(
        key: 'redaction-context',
        metadata: ['api_key' => 'secret', 'visible' => 'kept'],
        provenance: ['authorization' => 'Bearer secret'],
    );
    $input = ReasoningInput::observation(
        key: 'obs-redaction',
        metadata: ['nested' => ['refresh_token' => 'secret-refresh']],
        provenance: ['client_secret' => 'secret-client'],
    );
    $output = new ReasoningOutput(
        key: 'insight-redaction',
        stage: ReasoningStage::INSIGHT,
        type: 'test',
        metadata: ['password' => 'secret-password'],
        provenance: ['session' => 'secret-session'],
    );

    expect(data_get($context->toArray(), 'metadata.api_key'))->toBe('[redacted]')
        ->and(data_get($context->toArray(), 'metadata.visible'))->toBe('kept')
        ->and(data_get($context->toArray(), 'provenance.authorization'))->toBe('[redacted]')
        ->and(data_get($input->toArray(), 'metadata.nested.refresh_token'))->toBe('[redacted]')
        ->and(data_get($input->toArray(), 'provenance.client_secret'))->toBe('[redacted]')
        ->and(data_get($output->toArray(), 'metadata.password'))->toBe('[redacted]')
        ->and(data_get($output->toArray(), 'provenance.session'))->toBe('[redacted]');
});

it('exposes deterministic fake projector output for tests', function (): void {
    $projector = new FakeReasoningPipelineProjector();
    $pipeline = new ReasoningPipeline('fake-output-pipeline', $projector, [
        ReasoningStage::OBSERVATION,
        ReasoningStage::SIGNAL,
        ReasoningStage::INSIGHT,
    ]);

    $result = $pipeline->run(ReasoningInput::observation('obs-fake-output', confidence: 0.72, priority: 64));
    $outputs = $projector->outputs();

    expect($outputs)->toHaveCount(2)
        ->and(data_get($outputs, '0.reasoning_stage'))->toBe('signal')
        ->and(data_get($outputs, '0.artifact.source.provider'))->toBe('fake_reasoning_projector')
        ->and(data_get($outputs, '0.provenance.external_llm'))->toBeFalse()
        ->and(data_get($outputs, '1.reasoning_stage'))->toBe('insight')
        ->and(data_get($outputs, '1.metadata.deterministic'))->toBeTrue()
        ->and($result->trace->transitions())->toBe(['observation_to_signal', 'signal_to_insight']);
});

it('keeps phase seven free of reasoning database tables and migrations', function (): void {
    $migrationPaths = collect(glob(base_path('database/migrations/*.php')) ?: []);
    $tableNames = $migrationPaths
        ->flatMap(function (string $path): array {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($path) ?: '', $matches);

            return $matches[1] ?? [];
        })
        ->values()
        ->all();

    expect($tableNames)->not->toContain('reasoning_pipelines')
        ->and($tableNames)->not->toContain('reasoning_stages')
        ->and($tableNames)->not->toContain('reasoning_contexts')
        ->and($tableNames)->not->toContain('reasoning_inputs')
        ->and($tableNames)->not->toContain('reasoning_outputs')
        ->and($tableNames)->not->toContain('reasoning_traces')
        ->and($tableNames)->not->toContain('reasoning_steps')
        ->and($tableNames)->not->toContain('reasoning_results')
        ->and($tableNames)->not->toContain('reasoning_pipeline_runs')
        ->and($migrationPaths->filter(fn (string $path): bool => str_contains(basename($path), 'reasoning'))->all())->toBe([]);
});
