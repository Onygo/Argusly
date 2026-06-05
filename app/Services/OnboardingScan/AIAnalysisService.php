<?php

namespace App\Services\OnboardingScan;

use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Log;

class AIAnalysisService
{
    private const MAX_CONTENT_FOR_ANALYSIS = 30000;

    public function __construct(
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * Analyze extracted content and generate profiles.
     *
     * @param  array<string, array>  $extractedContent
     * @return array{brand_profile: array, seo_profile: array, design_profile: array, technical_profile: array, suggested_briefs: array}
     */
    public function analyze(array $extractedContent): array
    {
        $contentSummary = $this->prepareContentSummary($extractedContent);

        // Run all analyses - we could parallelize these in the future
        $brandProfile = $this->analyzeBrandProfile($contentSummary);
        $seoProfile = $this->analyzeSeoProfile($contentSummary);
        $designProfile = $this->analyzeDesignProfile($contentSummary, $extractedContent);
        $technicalProfile = $this->analyzeTechnicalProfile($extractedContent);
        $suggestedBriefs = $this->generateBriefSuggestions($contentSummary, $brandProfile, $seoProfile);

        return [
            'brand_profile' => $brandProfile,
            'seo_profile' => $seoProfile,
            'design_profile' => $designProfile,
            'technical_profile' => $technicalProfile,
            'suggested_briefs' => $suggestedBriefs,
        ];
    }

    /**
     * Prepare a summary of all extracted content for analysis.
     */
    private function prepareContentSummary(array $extractedContent): array
    {
        $titles = [];
        $descriptions = [];
        $allHeadings = [];
        $allContent = '';
        $keywords = [];

        foreach ($extractedContent as $page) {
            if (! empty($page['title'])) {
                $titles[] = $page['title'];
            }
            if (! empty($page['meta_description'])) {
                $descriptions[] = $page['meta_description'];
            }
            if (! empty($page['meta_keywords'])) {
                $keywords[] = $page['meta_keywords'];
            }
            if (! empty($page['headings'])) {
                foreach ($page['headings'] as $heading) {
                    $allHeadings[] = $heading['text'];
                }
            }
            if (! empty($page['main_content'])) {
                $allContent .= "\n\n" . $page['main_content'];
            }
        }

        // Truncate content if too long
        if (mb_strlen($allContent) > self::MAX_CONTENT_FOR_ANALYSIS) {
            $allContent = mb_substr($allContent, 0, self::MAX_CONTENT_FOR_ANALYSIS) . '...';
        }

        return [
            'titles' => array_unique($titles),
            'descriptions' => array_unique($descriptions),
            'headings' => array_slice(array_unique($allHeadings), 0, 50),
            'content' => trim($allContent),
            'keywords' => implode(', ', array_unique(array_filter($keywords))),
            'page_count' => count($extractedContent),
        ];
    }

    /**
     * Analyze and generate brand profile.
     */
    private function analyzeBrandProfile(array $contentSummary): array
    {
        $systemPrompt = <<<'PROMPT'
You are a brand analyst. Analyze the provided website content and extract brand information.
Return a JSON object with the following structure:
{
    "company_name": "The company or brand name",
    "industry": "The primary industry or sector",
    "value_propositions": ["List of 3-5 main value propositions"],
    "target_audience": "Description of the target audience",
    "tone_of_voice": "Describe the brand's tone (e.g., professional, friendly, technical)",
    "key_differentiators": ["What makes this company unique"],
    "brand_personality": "Brief description of brand personality"
}
Be concise and factual. If information is not available, use null for that field.
PROMPT;

        $userPrompt = $this->buildUserPrompt($contentSummary);

        return $this->callLlmForJson($systemPrompt, $userPrompt, 'brand_analysis');
    }

    /**
     * Analyze and generate SEO profile.
     */
    private function analyzeSeoProfile(array $contentSummary): array
    {
        $systemPrompt = <<<'PROMPT'
You are an SEO analyst. Analyze the provided website content and extract SEO insights.
Return a JSON object with the following structure:
{
    "primary_keywords": ["List of 5-10 primary keywords the site appears to target"],
    "topic_clusters": ["Main topic areas covered by the content"],
    "content_gaps": ["Suggested topics they could cover but don't seem to"],
    "search_intent_focus": "Primary search intent (informational, transactional, navigational)",
    "content_types_present": ["Types of content found: blog, product pages, case studies, etc."],
    "recommended_keywords": ["5-10 keywords they should target based on their business"]
}
Be specific and actionable. Base your analysis on the actual content provided.
PROMPT;

        $userPrompt = $this->buildUserPrompt($contentSummary);

        return $this->callLlmForJson($systemPrompt, $userPrompt, 'seo_analysis');
    }

    /**
     * Analyze and generate design profile.
     */
    private function analyzeDesignProfile(array $contentSummary, array $extractedContent): array
    {
        // Aggregate design data from extracted content
        $colors = [];
        $fonts = [];

        foreach ($extractedContent as $page) {
            if (! empty($page['detected_colors'])) {
                foreach ($page['detected_colors'] as $color => $count) {
                    $colors[$color] = ($colors[$color] ?? 0) + $count;
                }
            }
            if (! empty($page['detected_fonts'])) {
                foreach ($page['detected_fonts'] as $font) {
                    $fonts[$font] = ($fonts[$font] ?? 0) + 1;
                }
            }
        }

        arsort($colors);
        arsort($fonts);

        return [
            'primary_colors' => array_slice(array_keys($colors), 0, 5),
            'color_frequency' => array_slice($colors, 0, 10, true),
            'fonts' => array_slice(array_keys($fonts), 0, 5),
            'font_frequency' => array_slice($fonts, 0, 5, true),
        ];
    }

    /**
     * Analyze and generate technical profile.
     */
    private function analyzeTechnicalProfile(array $extractedContent): array
    {
        $indicators = [];

        foreach ($extractedContent as $page) {
            if (! empty($page['technical_indicators'])) {
                foreach ($page['technical_indicators'] as $indicator) {
                    $indicators[$indicator] = ($indicators[$indicator] ?? 0) + 1;
                }
            }
        }

        // Categorize indicators
        $cms = [];
        $frameworks = [];
        $analytics = [];
        $other = [];

        $cmsIndicators = ['wordpress', 'shopify', 'wix', 'squarespace', 'webflow', 'drupal', 'joomla'];
        $frameworkIndicators = ['react', 'vue', 'angular', 'next', 'nuxt', 'gatsby', 'laravel'];
        $analyticsIndicators = ['google_analytics', 'google_tag_manager', 'hotjar', 'intercom', 'crisp'];

        foreach (array_keys($indicators) as $indicator) {
            if (in_array($indicator, $cmsIndicators, true)) {
                $cms[] = $indicator;
            } elseif (in_array($indicator, $frameworkIndicators, true)) {
                $frameworks[] = $indicator;
            } elseif (in_array($indicator, $analyticsIndicators, true)) {
                $analytics[] = $indicator;
            } else {
                $other[] = $indicator;
            }
        }

        return [
            'detected_cms' => $cms,
            'detected_frameworks' => $frameworks,
            'detected_analytics' => $analytics,
            'other_technologies' => $other,
            'all_indicators' => array_keys($indicators),
        ];
    }

    /**
     * Generate brief suggestions based on analysis.
     */
    private function generateBriefSuggestions(array $contentSummary, array $brandProfile, array $seoProfile): array
    {
        $systemPrompt = <<<'PROMPT'
You are a content strategist. Based on the website analysis, suggest 5 content briefs that would benefit this business.
Return a JSON object with the following structure:
{
    "briefs": [
        {
            "title": "Suggested article title",
            "primary_keyword": "Main keyword to target",
            "intent": "informational, transactional, or navigational",
            "audience": "Who this content is for",
            "notes": "Brief explanation of why this content would be valuable"
        }
    ]
}
Focus on content that fills gaps, builds authority, or supports their business goals.
Ensure titles are specific and actionable, not generic.
PROMPT;

        $userPrompt = "Website Analysis Summary:\n\n";
        $userPrompt .= "Company: " . ($brandProfile['company_name'] ?? 'Unknown') . "\n";
        $userPrompt .= "Industry: " . ($brandProfile['industry'] ?? 'Unknown') . "\n";
        $userPrompt .= "Target Audience: " . ($brandProfile['target_audience'] ?? 'Unknown') . "\n\n";

        if (! empty($seoProfile['primary_keywords'])) {
            $userPrompt .= "Current Keywords: " . implode(', ', array_slice($seoProfile['primary_keywords'], 0, 10)) . "\n";
        }
        if (! empty($seoProfile['content_gaps'])) {
            $userPrompt .= "Content Gaps: " . implode(', ', $seoProfile['content_gaps']) . "\n";
        }
        if (! empty($seoProfile['recommended_keywords'])) {
            $userPrompt .= "Recommended Keywords: " . implode(', ', $seoProfile['recommended_keywords']) . "\n";
        }

        $userPrompt .= "\nPage Titles:\n" . implode("\n", array_slice($contentSummary['titles'], 0, 10));

        $result = $this->callLlmForJson($systemPrompt, $userPrompt, 'brief_suggestions');

        return $result['briefs'] ?? [];
    }

    private function buildUserPrompt(array $contentSummary): string
    {
        $prompt = "Website Content Analysis:\n\n";

        if (! empty($contentSummary['titles'])) {
            $prompt .= "Page Titles:\n" . implode("\n", $contentSummary['titles']) . "\n\n";
        }

        if (! empty($contentSummary['descriptions'])) {
            $prompt .= "Meta Descriptions:\n" . implode("\n", $contentSummary['descriptions']) . "\n\n";
        }

        if (! empty($contentSummary['headings'])) {
            $prompt .= "Headings:\n" . implode("\n", array_slice($contentSummary['headings'], 0, 30)) . "\n\n";
        }

        if (! empty($contentSummary['content'])) {
            $prompt .= "Main Content:\n" . $contentSummary['content'];
        }

        return $prompt;
    }

    /**
     * Call LLM and parse JSON response.
     *
     * @return array<string, mixed>
     */
    private function callLlmForJson(string $systemPrompt, string $userPrompt, string $feature): array
    {
        try {
            $request = new LlmRequest(
                messages: [
                    new LlmMessage('system', $systemPrompt),
                    new LlmMessage('user', $userPrompt),
                ],
                temperature: 0.3,
                maxTokens: 2000,
                metadata: [
                    'feature' => 'onboarding_scan',
                    'sub_feature' => $feature,
                ],
            );

            $response = $this->llmManager->generateJson($request, null, [
                'feature' => 'onboarding_scan',
            ]);

            if ($response->json !== null) {
                return $response->json;
            }

            Log::warning('AIAnalysisService: JSON response was null', [
                'feature' => $feature,
                'text_preview' => mb_substr($response->text ?? '', 0, 200),
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::error('AIAnalysisService: LLM call failed', [
                'feature' => $feature,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
