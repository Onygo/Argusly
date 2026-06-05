<?php

namespace App\Services\CompetitorIntelligence;

class CompetitorContentPatternDetector
{
    /**
     * @return array<string, mixed>
     */
    public function detect(string $url, string $title, string $text): array
    {
        $haystack = strtolower($url . ' ' . $title . ' ' . $text);
        $isComparison = $this->hasAny($haystack, [' vs ', '-vs-', '/vs/', ' versus ', 'alternative', 'alternatives', 'comparison']);
        $format = match (true) {
            $isComparison => 'comparison_page',
            $this->hasAny($haystack, ['pricing', 'price', 'plans']) => 'pricing_page',
            $this->hasAny($haystack, ['use case', 'case study', 'customer story']) => 'use_case',
            $this->hasAny($haystack, ['implementation', 'setup', 'how to', 'guide', 'playbook']) => 'implementation_guide',
            $this->hasAny($haystack, ['template', 'checklist']) => 'template',
            default => 'article',
        };

        $angle = match (true) {
            $this->hasAny($haystack, ['fast', 'faster', 'speed', 'automate']) => 'speed_to_value',
            $this->hasAny($haystack, ['enterprise', 'security', 'governance', 'compliance']) => 'enterprise_control',
            $this->hasAny($haystack, ['roi', 'revenue', 'growth', 'pipeline']) => 'growth_outcome',
            $this->hasAny($haystack, ['simple', 'easy', 'no code', 'workflow']) => 'ease_of_use',
            default => null,
        };

        return [
            'content_type' => in_array($format, ['pricing_page', 'comparison_page'], true) ? 'landing_page' : 'article',
            'content_format' => $format,
            'landing_page_angle' => $angle,
            'is_comparison_page' => $isComparison,
            'has_answer_block_pattern' => $this->hasAny($haystack, ['faq', 'frequently asked', 'what is', 'how does', 'answer']),
            'has_schema_pattern' => $this->hasAny($haystack, ['schema', 'faqpage', 'howto', 'structured data']),
            'seo_patterns' => [
                'title_has_modifier' => $this->hasAny(strtolower($title), ['best', 'top', 'guide', 'pricing', 'alternative']),
                'url_contains_topic' => str_contains($url, '-'),
                'commercial_modifier' => $this->hasAny($haystack, ['pricing', 'alternative', 'software', 'platform', 'tool']),
            ],
            'aeo_patterns' => [
                'answer_block' => $this->hasAny($haystack, ['what is', 'how to', 'faq', 'frequently asked']),
                'implementation_steps' => $this->hasAny($haystack, ['step 1', 'implementation', 'setup', 'configure']),
                'entity_rich' => preg_match_all('/\b[A-Z][A-Za-z0-9]+/', $text) > 5,
            ],
        ];
    }

    private function hasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
