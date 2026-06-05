<?php

namespace Database\Factories;

use App\Models\Draft;
use App\Models\DraftAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DraftAnalysis>
 */
class DraftAnalysisFactory extends Factory
{
    protected $model = DraftAnalysis::class;

    public function definition(): array
    {
        return [
            'draft_id' => Draft::factory(),
            'status' => DraftAnalysis::STATUS_COMPLETED,
            'seo_score' => $this->faker->numberBetween(50, 95),
            'readability_score' => $this->faker->numberBetween(50, 95),
            'cta_score' => $this->faker->numberBetween(40, 90),
            'keyword_coverage' => $this->faker->numberBetween(50, 90),
            'entity_coverage' => $this->faker->numberBetween(50, 90),
            'internal_link_opportunities' => [],
            'suggestions' => $payload = [
                'summary' => [
                    'headline' => $this->faker->sentence(4),
                    'overall_explanation' => $this->faker->paragraph(),
                ],
                'sections' => [
                    'seo' => [
                        'score' => $this->faker->numberBetween(50, 95),
                        'explanation' => $this->faker->sentence(),
                        'improvements' => [$this->faker->sentence(), $this->faker->sentence()],
                    ],
                    'readability' => [
                        'score' => $this->faker->numberBetween(50, 95),
                        'explanation' => $this->faker->sentence(),
                        'improvements' => [$this->faker->sentence()],
                    ],
                    'cta' => [
                        'score' => $this->faker->numberBetween(40, 90),
                        'explanation' => $this->faker->sentence(),
                        'improvements' => [$this->faker->sentence(), $this->faker->sentence()],
                    ],
                    'structure' => [
                        'score' => $this->faker->numberBetween(50, 95),
                        'explanation' => $this->faker->sentence(),
                        'improvements' => [$this->faker->sentence()],
                    ],
                    'entities' => [
                        'score' => $this->faker->numberBetween(50, 90),
                        'explanation' => $this->faker->sentence(),
                        'improvements' => [$this->faker->sentence()],
                    ],
                ],
                'top_improvements' => [
                    $this->faker->sentence(),
                    $this->faker->sentence(),
                    $this->faker->sentence(),
                ],
                'internal_link_summary' => $this->faker->sentence(),
            ],
            'normalized_payload' => $payload,
            'analysis_model' => 'gpt-4-turbo',
            'analysis_provider' => 'openai',
            'prompt_version' => 'draft-intelligence.v2',
            'tokens_used' => $this->faker->numberBetween(500, 2000),
            'raw_response' => null,
            'parser_errors' => null,
            'validation_errors' => null,
        ];
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DraftAnalysis::STATUS_PARTIAL,
            'cta_score' => null,
            'entity_coverage' => null,
            'suggestions' => $payload = array_merge($attributes['suggestions'] ?? [], [
                'sections' => [
                    'seo' => ['score' => 75, 'explanation' => 'Good SEO.', 'improvements' => ['Add meta.']],
                    'readability' => ['score' => 80, 'explanation' => 'Good read.', 'improvements' => ['Short.']],
                    'cta' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'entities' => ['score' => null, 'explanation' => null, 'improvements' => []],
                ],
            ]),
            'normalized_payload' => $payload,
            'validation_errors' => ['Only 2 of 4 required sections present.'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DraftAnalysis::STATUS_FAILED,
            'seo_score' => null,
            'readability_score' => null,
            'cta_score' => null,
            'keyword_coverage' => null,
            'entity_coverage' => null,
            'internal_link_opportunities' => [],
            'suggestions' => [],
            'normalized_payload' => [],
            'parser_errors' => ['JSON parsing failed.'],
            'validation_errors' => ['No valid section data could be extracted.'],
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DraftAnalysis::STATUS_PENDING,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => DraftAnalysis::STATUS_PROCESSING,
        ]);
    }
}
