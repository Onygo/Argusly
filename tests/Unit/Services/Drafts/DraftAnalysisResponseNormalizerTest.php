<?php

namespace Tests\Unit\Services\Drafts;

use App\Services\Drafts\DraftAnalysisResponseNormalizer;
use PHPUnit\Framework\TestCase;

class DraftAnalysisResponseNormalizerTest extends TestCase
{
    private DraftAnalysisResponseNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new DraftAnalysisResponseNormalizer();
    }

    public function test_normalizes_standard_response_structure(): void
    {
        $payload = [
            'summary' => [
                'headline' => 'Test Analysis',
                'overall_explanation' => 'Overall good draft.',
            ],
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'Good SEO.', 'improvements' => ['Add meta.']],
                'readability' => ['score' => 80, 'explanation' => 'Good read.', 'improvements' => ['Short.']],
                'cta' => ['score' => 60, 'explanation' => 'Has CTA.', 'improvements' => ['Clear.']],
                'structure' => ['score' => 85, 'explanation' => 'Good struct.', 'improvements' => ['Heads.']],
                'entities' => ['score' => 70, 'explanation' => 'Good ent.', 'improvements' => ['More.']],
            ],
            'internal_link_opportunities' => [
                ['target_title' => 'Related Article', 'reason' => 'Topic match', 'anchor_text' => 'learn more', 'placement' => 'paragraph 2'],
            ],
            'top_improvements' => ['Fix title', 'Add CTA'],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertEmpty($result['errors']);
        $this->assertSame('Test Analysis', $result['normalized']['summary']['headline']);
        $this->assertSame(75, $result['normalized']['sections']['seo']['score']);
        $this->assertSame('Good SEO.', $result['normalized']['sections']['seo']['explanation']);
        $this->assertCount(1, $result['normalized']['internal_link_opportunities']);
        $this->assertCount(2, $result['normalized']['top_improvements']);
    }

    public function test_normalizes_alternate_key_names(): void
    {
        $payload = [
            'seoScore' => 75,
            'seo_explanation' => 'Good SEO coverage.',
            'seoImprovements' => ['Add meta description.'],
            'readabilityScore' => 80,
            'readability_explanation' => 'Well structured.',
            'readabilityImprovements' => ['Shorten paragraphs.'],
            'sections' => [
                'cta' => ['score' => 60, 'explanation' => 'Has CTA.', 'improvements' => ['Clear.']],
                'structure' => ['score' => 85, 'explanation' => 'Good.', 'improvements' => ['Heads.']],
                'entities' => ['score' => 70, 'explanation' => 'Good.', 'improvements' => ['More.']],
            ],
            'links' => [
                ['target_title' => 'Article', 'reason' => 'Match', 'anchor' => 'click here', 'location' => 'end'],
            ],
            'topImprovements' => ['Do this', 'Do that'],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertSame(75, $result['normalized']['sections']['seo']['score']);
        $this->assertSame('Good SEO coverage.', $result['normalized']['sections']['seo']['explanation']);
        $this->assertSame(80, $result['normalized']['sections']['readability']['score']);
        $this->assertCount(1, $result['normalized']['internal_link_opportunities']);
        $this->assertSame('click here', $result['normalized']['internal_link_opportunities'][0]['anchor_text']);
        $this->assertCount(2, $result['normalized']['top_improvements']);
    }

    public function test_hoists_top_level_sections(): void
    {
        $payload = [
            'seo' => ['score' => 75, 'explanation' => 'SEO.', 'improvements' => ['Fix.']],
            'readability' => ['score' => 80, 'explanation' => 'Read.', 'improvements' => ['Short.']],
            'cta' => ['score' => 60, 'explanation' => 'CTA.', 'improvements' => ['Clear.']],
            'structure' => ['score' => 85, 'explanation' => 'Struct.', 'improvements' => ['Head.']],
            'entities' => ['score' => 70, 'explanation' => 'Ent.', 'improvements' => ['More.']],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertSame(75, $result['normalized']['sections']['seo']['score']);
        $this->assertSame(80, $result['normalized']['sections']['readability']['score']);
        $this->assertSame(60, $result['normalized']['sections']['cta']['score']);
    }

    public function test_normalizes_link_opportunities_with_alternate_keys(): void
    {
        $payload = [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'readability' => ['score' => 80, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'cta' => ['score' => 60, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'structure' => ['score' => 85, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'entities' => ['score' => 70, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
            ],
            'internal_link_opportunities' => [
                ['targetTitle' => 'Article 1', 'explanation' => 'Reason 1', 'anchorText' => 'link', 'position' => 'start'],
                ['target_title' => 'Article 2', 'reason' => 'Reason 2', 'anchor_text' => 'click', 'placement' => 'middle'],
            ],
        ];

        $result = $this->normalizer->normalize($payload);

        $links = $result['normalized']['internal_link_opportunities'];
        $this->assertCount(2, $links);
        $this->assertSame('Article 1', $links[0]['target_title']);
        $this->assertSame('Reason 1', $links[0]['reason']);
        $this->assertSame('link', $links[0]['anchor_text']);
        $this->assertSame('start', $links[0]['placement']);
    }

    public function test_filters_invalid_link_opportunities(): void
    {
        $payload = [
            'sections' => [
                'seo' => ['score' => 75, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'readability' => ['score' => 80, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'cta' => ['score' => 60, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'structure' => ['score' => 85, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
                'entities' => ['score' => 70, 'explanation' => 'OK.', 'improvements' => ['Fix.']],
            ],
            'internal_link_opportunities' => [
                ['target_title' => 'Valid Article', 'reason' => 'Match'],
                ['target_title' => '', 'reason' => 'No title'],
                ['reason' => 'Missing title key'],
            ],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertCount(1, $result['normalized']['internal_link_opportunities']);
        $this->assertSame('Valid Article', $result['normalized']['internal_link_opportunities'][0]['target_title']);
    }

    public function test_clamps_scores_to_valid_range(): void
    {
        $payload = [
            'sections' => [
                'seo' => ['score' => 150, 'explanation' => 'Way too high.', 'improvements' => []],
                'readability' => ['score' => -10, 'explanation' => 'Negative.', 'improvements' => []],
                'cta' => ['score' => 60, 'explanation' => 'Normal.', 'improvements' => []],
                'structure' => ['score' => 85, 'explanation' => 'Normal.', 'improvements' => []],
                'entities' => ['score' => 70, 'explanation' => 'Normal.', 'improvements' => []],
            ],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertSame(100, $result['normalized']['sections']['seo']['score']);
        $this->assertSame(0, $result['normalized']['sections']['readability']['score']);
    }

    public function test_handles_null_and_empty_values(): void
    {
        $payload = [
            'summary' => [
                'headline' => null,
                'overall_explanation' => '',
            ],
            'sections' => [
                'seo' => ['score' => null, 'explanation' => null, 'improvements' => null],
                'readability' => ['score' => 80, 'explanation' => 'OK.', 'improvements' => ['  ', '', 'Valid']],
                'cta' => ['score' => 60, 'explanation' => 'OK.', 'improvements' => []],
                'structure' => ['score' => 85, 'explanation' => 'OK.', 'improvements' => []],
                'entities' => ['score' => 70, 'explanation' => 'OK.', 'improvements' => []],
            ],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertNull($result['normalized']['summary']['headline']);
        $this->assertNull($result['normalized']['summary']['overall_explanation']);
        $this->assertNull($result['normalized']['sections']['seo']['score']);
        $this->assertNull($result['normalized']['sections']['seo']['explanation']);
        $this->assertEmpty($result['normalized']['sections']['seo']['improvements']);
        // Empty strings should be filtered from improvements
        $this->assertSame(['Valid'], $result['normalized']['sections']['readability']['improvements']);
    }

    public function test_returns_canonical_empty_structure(): void
    {
        $structure = $this->normalizer->emptyCanonicalStructure();

        $this->assertArrayHasKey('summary', $structure);
        $this->assertArrayHasKey('sections', $structure);
        $this->assertArrayHasKey('keyword_coverage', $structure);
        $this->assertArrayHasKey('entity_coverage', $structure);
        $this->assertArrayHasKey('internal_link_opportunities', $structure);
        $this->assertArrayHasKey('top_improvements', $structure);

        foreach (['seo', 'readability', 'cta', 'structure', 'entities'] as $key) {
            $this->assertArrayHasKey($key, $structure['sections']);
            $this->assertNull($structure['sections'][$key]['score']);
            $this->assertNull($structure['sections'][$key]['explanation']);
            $this->assertEmpty($structure['sections'][$key]['improvements']);
        }
    }

    public function test_reports_error_when_no_section_data_extracted(): void
    {
        $payload = [
            'random_key' => 'random_value',
            'another_key' => ['nested' => 'data'],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('No valid section data', $result['errors'][0]);
    }

    public function test_normalizes_observed_legacy_section_array_shape(): void
    {
        $payload = [
            'summary' => [
                'headline' => 'Legacy response',
                'overall_explanation' => 'Old provider shape.',
            ],
            'sections' => [
                [
                    'name' => 'Title & Metadata',
                    'score' => 90,
                    'comments' => ['Strong SEO alignment.', 'Tighten the title length.'],
                ],
                [
                    'name' => 'Heading Structure',
                    'score' => 78,
                    'comments' => ['Logical heading flow.', 'Remove duplicate headings.'],
                ],
                [
                    'name' => 'Readability & Structure',
                    'score' => 82,
                    'comments' => ['Readable for the target audience.', 'Break up dense paragraphs.'],
                ],
                [
                    'name' => 'CTA',
                    'score' => 61,
                    'comments' => ['CTA is implied.', 'Add a direct closing CTA.'],
                ],
                [
                    'name' => 'Entities',
                    'score' => 70,
                    'comments' => ['Relevant entities are present.', 'Add more specific role entities.'],
                ],
            ],
            'top_recommendations' => ['Fix headings', 'Add CTA', 'Strengthen entity coverage'],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertSame(90, $result['normalized']['sections']['seo']['score']);
        $this->assertSame('Strong SEO alignment.', $result['normalized']['sections']['seo']['explanation']);
        $this->assertSame(78, $result['normalized']['sections']['structure']['score']);
        $this->assertSame(82, $result['normalized']['sections']['readability']['score']);
        $this->assertSame(61, $result['normalized']['sections']['cta']['score']);
        $this->assertSame(70, $result['normalized']['sections']['entities']['score']);
        $this->assertSame(['Fix headings', 'Add CTA', 'Strengthen entity coverage'], $result['normalized']['top_improvements']);
    }

    public function test_normalizes_observed_legacy_object_section_keys(): void
    {
        $payload = [
            'overall' => [
                'comments' => ['Strong topical fit for the brief.'],
            ],
            'sections' => [
                'title' => [
                    'score' => 92,
                    'feedback' => 'Title is strong and keyword aligned.',
                ],
                'meta_description' => [
                    'score' => 88,
                    'feedback' => 'Meta description is clear and benefit-driven.',
                ],
                'headings' => [
                    'score' => 80,
                    'feedback' => 'Headings need one duplicate removed.',
                ],
                'body_content' => [
                    'score' => 87,
                    'feedback' => 'Readable body copy with strong examples.',
                ],
                'conclusion' => [
                    'score' => 55,
                    'feedback' => 'Conclusion lacks an explicit CTA.',
                ],
            ],
        ];

        $result = $this->normalizer->normalize($payload);

        $this->assertSame('Strong topical fit for the brief.', $result['normalized']['summary']['overall_explanation']);
        $this->assertSame(92, $result['normalized']['sections']['seo']['score']);
        $this->assertSame(80, $result['normalized']['sections']['structure']['score']);
        $this->assertSame(87, $result['normalized']['sections']['readability']['score']);
        $this->assertSame(55, $result['normalized']['sections']['cta']['score']);
    }
}
