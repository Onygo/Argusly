<?php

use App\Models\ContentSource;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\SourceBriefing\SourceContentAnalyzer;
use App\Services\SourceBriefing\WorkspaceSourceContextBuilder;

it('uses strict schema and prompt metadata for source analysis', function () {
    $source = new ContentSource([
        'id' => 'source-analysis-test',
        'source_url' => 'https://example.com/article',
        'final_url' => 'https://example.com/article',
        'source_domain' => 'example.com',
        'source_title' => 'Answer engine optimization for B2B teams',
        'source_language' => 'en',
        'generation_output_mode' => 'brief_only',
        'created_by_user_id' => 12,
        'extracted_text' => str_repeat('Answer engine optimization requires clear entities, evidence, and direct answers. ', 20),
        'extracted_outline_json' => [
            'h1' => 'Answer engine optimization for B2B teams',
            'h2' => ['Why entities matter', 'How to structure answers'],
        ],
        'metadata_json' => [
            'extraction' => [
                'word_count' => 180,
                'summary' => 'A practical article about answer engine optimization.',
            ],
        ],
    ]);

    $workspace = new Workspace(['id' => 'workspace-analysis-test']);

    $contextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
    $contextBuilder->shouldReceive('build')->once()->andReturn([
        'company_profile' => [
            'target_audience' => 'B2B marketers',
            'services' => ['AI visibility strategy'],
        ],
    ]);

    $llm = Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->withArgs(function (LlmRequest $request, array $schema, array $metadata): bool {
            return data_get($request->metadata, 'prompt_version') === 'source-analysis.accuracy.v2'
                && data_get($request->metadata, 'eval_rubric_version') === 'llm-accuracy.source-briefing.v1'
                && data_get($request->metadata, 'schema_name') === 'source_briefing_analysis'
                && ($metadata['feature'] ?? null) === 'source_briefing'
                && ($schema['name'] ?? null) === 'source_briefing_analysis'
                && ($schema['strict'] ?? null) === true
                && data_get($schema, 'schema.additionalProperties') === false
                && in_array('accuracy_diagnostics', (array) data_get($schema, 'schema.required'), true)
                && str_contains((string) $request->messages[0]->content, 'Separate source-supported observations from strategic inferences')
                && str_contains((string) $request->messages[1]->content, 'Heuristic baseline:');
        })
        ->andReturn(new LlmResponse(
            text: '',
            json: [
                'main_topic' => 'Answer engine optimization',
                'primary_keyword' => 'answer engine optimization',
                'secondary_keywords' => ['AI visibility'],
                'semantic_entities' => ['Answer Engines', 'B2B'],
                'search_intent' => 'informational',
                'likely_audience' => 'B2B marketers',
                'funnel_stage' => 'awareness',
                'source_tone' => 'practical',
                'key_claims' => ['Clear entities improve answer extraction.'],
                'questions_answered' => ['What is answer engine optimization?'],
                'content_gaps' => ['Add proof examples.'],
                'cta_style' => 'subtle educational CTA',
                'suggested_differentiators' => ['AI visibility strategy'],
                'analysis_confidence' => 84,
                'accuracy_diagnostics' => [
                    'source_context_sufficiency' => 'medium',
                    'copy_risk' => 'low',
                    'missing_context' => [],
                    'uncertain_inferences' => ['Funnel stage inferred from informational framing.'],
                    'evaluation_notes' => ['Source is clear but excerpt is short.'],
                ],
            ],
            usage: new LlmUsage(100, 80, 180),
            modelUsed: 'gpt-4.1-mini',
            providerName: 'openai',
            requestId: 'resp-source-analysis-test',
        ));

    $analysis = (new SourceContentAnalyzer($llm, $contextBuilder))->analyze($source, $workspace);

    expect($analysis['analysis_confidence'])->toBe(84)
        ->and(data_get($analysis, 'accuracy_diagnostics.copy_risk'))->toBe('low')
        ->and(data_get($analysis, '_debug.prompt_version'))->toBe('source-analysis.accuracy.v2');
});
