<?php

namespace Tests\Feature\Drafts;

use App\Models\DraftAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftIntelligenceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_correctly_identifies_missing_sections(): void
    {
        $analysis = new DraftAnalysis([
            'status' => DraftAnalysis::STATUS_PARTIAL,
            'suggestions' => [
                'sections' => [
                    'seo' => ['score' => 75, 'explanation' => 'Good SEO.', 'improvements' => []],
                    'readability' => ['score' => 80, 'explanation' => 'Good read.', 'improvements' => []],
                    'cta' => ['score' => null, 'explanation' => '', 'improvements' => []],
                    'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'entities' => ['score' => 70, 'explanation' => 'Good entities.', 'improvements' => []],
                ],
            ],
        ]);

        $missing = $analysis->missing_sections;
        $available = $analysis->available_sections;

        $this->assertContains('cta', $missing);
        $this->assertContains('structure', $missing);
        $this->assertNotContains('seo', $missing);
        $this->assertNotContains('readability', $missing);
        $this->assertNotContains('entities', $missing);

        $this->assertContains('seo', $available);
        $this->assertContains('readability', $available);
        $this->assertContains('entities', $available);
    }

    public function test_model_status_helper_methods(): void
    {
        $completed = new DraftAnalysis([
            'status' => DraftAnalysis::STATUS_COMPLETED,
            'suggestions' => [
                'sections' => [
                    'seo' => ['score' => 80, 'explanation' => 'Strong SEO.', 'improvements' => ['Tighten title.']],
                    'readability' => ['score' => 82, 'explanation' => 'Readable.', 'improvements' => ['Shorten intro.']],
                    'cta' => ['score' => 70, 'explanation' => 'CTA exists.', 'improvements' => ['Clarify ask.']],
                    'structure' => ['score' => 78, 'explanation' => 'Good structure.', 'improvements' => ['Merge duplicate heading.']],
                    'entities' => ['score' => 74, 'explanation' => 'Good entity coverage.', 'improvements' => ['Add one supporting entity.']],
                ],
            ],
        ]);
        $partial = new DraftAnalysis([
            'status' => DraftAnalysis::STATUS_PARTIAL,
            'suggestions' => [
                'sections' => [
                    'seo' => ['score' => 80, 'explanation' => 'Strong SEO.', 'improvements' => ['Tighten title.']],
                    'readability' => ['score' => 82, 'explanation' => 'Readable.', 'improvements' => ['Shorten intro.']],
                    'cta' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'entities' => ['score' => null, 'explanation' => null, 'improvements' => []],
                ],
            ],
        ]);
        $failed = new DraftAnalysis(['status' => DraftAnalysis::STATUS_FAILED]);
        $pending = new DraftAnalysis(['status' => DraftAnalysis::STATUS_PENDING]);
        $processing = new DraftAnalysis(['status' => DraftAnalysis::STATUS_PROCESSING]);

        $this->assertTrue($completed->isComplete());
        $this->assertFalse($completed->isPartial());
        $this->assertTrue($completed->hasUsableData());

        $this->assertTrue($partial->isPartial());
        $this->assertFalse($partial->isComplete());
        $this->assertTrue($partial->hasUsableData());

        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->hasUsableData());

        $this->assertTrue($pending->isPending());
        $this->assertTrue($processing->isProcessing());
    }

    public function test_effective_status_downgrades_empty_completed_analysis(): void
    {
        $analysis = new DraftAnalysis([
            'status' => DraftAnalysis::STATUS_COMPLETED,
            'suggestions' => [
                'sections' => [
                    'seo' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'readability' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'cta' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                    'entities' => ['score' => null, 'explanation' => null, 'improvements' => []],
                ],
            ],
        ]);

        $this->assertSame(DraftAnalysis::STATUS_FAILED, $analysis->effective_status);
        $this->assertFalse($analysis->hasUsableData());
    }
}
