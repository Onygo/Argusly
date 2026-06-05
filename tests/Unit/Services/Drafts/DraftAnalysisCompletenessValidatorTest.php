<?php

namespace Tests\Unit\Services\Drafts;

use App\Models\DraftAnalysis;
use App\Services\Drafts\DraftAnalysisCompletenessValidator;
use PHPUnit\Framework\TestCase;

class DraftAnalysisCompletenessValidatorTest extends TestCase
{
    private DraftAnalysisCompletenessValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DraftAnalysisCompletenessValidator();
    }

    public function test_valid_full_response_returns_completed_status(): void
    {
        $suggestions = [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'Good SEO coverage.', 'improvements' => ['Add meta description.', 'Optimize title.']],
                'readability' => ['score' => 80, 'explanation' => 'Well structured content.', 'improvements' => ['Shorten paragraphs.']],
                'cta' => ['score' => 60, 'explanation' => 'CTA could be stronger.', 'improvements' => ['Add clear action.', 'Use urgency.']],
                'structure' => ['score' => 85, 'explanation' => 'Good heading structure.', 'improvements' => ['Add subheadings.']],
                'entities' => ['score' => 70, 'explanation' => 'Good entity coverage.', 'improvements' => ['Add more examples.']],
            ],
        ];

        $result = $this->validator->validate($suggestions, 75, 80, 60, 70);

        $this->assertSame(DraftAnalysis::STATUS_COMPLETED, $result['status']);
        $this->assertEmpty($result['errors']);
        $this->assertSame(5, $result['metrics']['sections_present']);
        $this->assertSame(5, $result['metrics']['sections_scored']);
        $this->assertSame(5, $result['metrics']['sections_explained']);
        $this->assertSame(7, $result['metrics']['total_improvements']);
    }

    public function test_partial_response_with_missing_sections_returns_partial_status(): void
    {
        $suggestions = [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'Good SEO.', 'improvements' => ['Fix title.']],
                'readability' => ['score' => 80, 'explanation' => 'Good readability.', 'improvements' => ['Add spacing.']],
                // Missing cta, structure, entities
            ],
        ];

        $result = $this->validator->validate($suggestions, 75, 80, null, null);

        $this->assertSame(DraftAnalysis::STATUS_PARTIAL, $result['status']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(2, $result['metrics']['sections_present']);
    }

    public function test_response_with_empty_explanations_returns_partial_status(): void
    {
        $suggestions = [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => '', 'improvements' => ['Fix title.']],
                'readability' => ['score' => 80, 'explanation' => '', 'improvements' => ['Add spacing.']],
                'cta' => ['score' => 60, 'explanation' => 'Has CTA.', 'improvements' => ['Improve.']],
                'structure' => ['score' => 85, 'explanation' => '', 'improvements' => ['Add.']],
                'entities' => ['score' => 70, 'explanation' => '', 'improvements' => ['More.']],
            ],
        ];

        $result = $this->validator->validate($suggestions, 75, 80, 60, 70);

        $this->assertSame(DraftAnalysis::STATUS_PARTIAL, $result['status']);
        $this->assertContains('Only 1 of 3 required section explanations present.', $result['errors']);
    }

    public function test_response_with_null_scores_returns_partial_status(): void
    {
        $suggestions = [
            'sections' => [
                'seo' => ['score' => null, 'explanation' => 'SEO analysis.', 'improvements' => ['Fix.']],
                'readability' => ['score' => null, 'explanation' => 'Readability.', 'improvements' => ['Add.']],
                'cta' => ['score' => 60, 'explanation' => 'CTA analysis.', 'improvements' => ['Improve.', 'Add.']],
                'structure' => ['score' => 85, 'explanation' => 'Structure.', 'improvements' => ['Better.']],
                'entities' => ['score' => null, 'explanation' => 'Entities.', 'improvements' => ['More.']],
            ],
        ];

        $result = $this->validator->validate($suggestions, null, null, 60, null);

        $this->assertSame(DraftAnalysis::STATUS_PARTIAL, $result['status']);
        $this->assertSame(2, $result['metrics']['sections_scored']);
    }

    public function test_completely_empty_response_returns_failed_status(): void
    {
        $suggestions = [];

        $result = $this->validator->validate($suggestions, null, null, null, null);

        $this->assertSame(DraftAnalysis::STATUS_FAILED, $result['status']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, $result['metrics']['sections_present']);
    }

    public function test_empty_canonical_section_shells_do_not_count_as_present(): void
    {
        $suggestions = [
            'sections' => [
                'seo' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'readability' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'cta' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'entities' => ['score' => null, 'explanation' => null, 'improvements' => []],
            ],
        ];

        $result = $this->validator->validate($suggestions, null, null, null, null);

        $this->assertSame(DraftAnalysis::STATUS_FAILED, $result['status']);
        $this->assertSame(0, $result['metrics']['sections_present']);
        $this->assertSame(0, $result['metrics']['sections_explained']);
        $this->assertSame(0, $result['metrics']['total_improvements']);
    }

    public function test_top_level_scores_are_considered_for_validation(): void
    {
        // Sections have no scores but top-level scores are present
        $suggestions = [
            'sections' => [
                'seo' => ['score' => null, 'explanation' => 'SEO.', 'improvements' => ['Fix.', 'Add.']],
                'readability' => ['score' => null, 'explanation' => 'Read.', 'improvements' => ['Short.']],
                'cta' => ['score' => null, 'explanation' => 'CTA.', 'improvements' => ['Clear.', 'Urgent.']],
                'structure' => ['score' => null, 'explanation' => 'Struct.', 'improvements' => ['Heads.']],
                'entities' => ['score' => null, 'explanation' => 'Ent.', 'improvements' => ['More.']],
            ],
        ];

        // Top-level scores from database columns
        $result = $this->validator->validate($suggestions, 75, 80, 60, 70);

        // Should be completed because top-level scores satisfy the threshold
        $this->assertSame(DraftAnalysis::STATUS_COMPLETED, $result['status']);
        $this->assertSame(4, $result['metrics']['top_level_scores']);
    }

    public function test_has_parsable_structure_with_sections_key(): void
    {
        $payload = [
            'sections' => [
                'seo' => ['score' => 75],
            ],
        ];

        $this->assertTrue($this->validator->hasParsableStructure($payload));
    }

    public function test_has_parsable_structure_with_top_level_sections(): void
    {
        $payload = [
            'seo' => ['score' => 75],
            'readability' => ['score' => 80],
        ];

        $this->assertTrue($this->validator->hasParsableStructure($payload));
    }

    public function test_has_parsable_structure_fails_with_insufficient_data(): void
    {
        $payload = [
            'seo' => ['score' => 75],
        ];

        $this->assertFalse($this->validator->hasParsableStructure($payload));
    }
}
