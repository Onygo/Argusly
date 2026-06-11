<?php

namespace App\Enums;

enum GrowthAssetType: string
{
    case BLOG_POST = 'blog_post';
    case LANDING_PAGE = 'landing_page';
    case INDUSTRY_PAGE = 'industry_page';
    case LOCATION_PAGE = 'location_page';
    case COMPARISON_PAGE = 'comparison_page';
    case ALTERNATIVE_PAGE = 'alternative_page';
    case FAQ_PAGE = 'faq_page';
    case AI_ANSWER_PAGE = 'ai_answer_page';
    case PILLAR_PAGE = 'pillar_page';
    case SUPPORTING_PAGE = 'supporting_page';
    case INTEGRATION_PAGE = 'integration_page';
    case FEATURE_PAGE = 'feature_page';
    case STRUCTURED_ANSWER = 'structured_answer';
    case SCHEMA_MARKUP = 'schema_markup';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::BLOG_POST => 'Blog post',
            self::LANDING_PAGE => 'Landing page',
            self::INDUSTRY_PAGE => 'Industry page',
            self::LOCATION_PAGE => 'Location page',
            self::COMPARISON_PAGE => 'Comparison page',
            self::ALTERNATIVE_PAGE => 'Alternative page',
            self::FAQ_PAGE => 'FAQ page',
            self::AI_ANSWER_PAGE => 'AI answer page',
            self::PILLAR_PAGE => 'Pillar page',
            self::SUPPORTING_PAGE => 'Supporting page',
            self::INTEGRATION_PAGE => 'Integration page',
            self::FEATURE_PAGE => 'Feature page',
            self::STRUCTURED_ANSWER => 'Structured answer',
            self::SCHEMA_MARKUP => 'Schema markup',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BLOG_POST => 'Editorial article for education, thought leadership, or organic discovery.',
            self::LANDING_PAGE => 'Conversion-oriented page for a focused offer, audience, or campaign.',
            self::INDUSTRY_PAGE => 'Solution page tailored to an industry segment and its requirements.',
            self::LOCATION_PAGE => 'Location-specific service or solution page.',
            self::COMPARISON_PAGE => 'Evaluation page comparing products, tools, vendors, or approaches.',
            self::ALTERNATIVE_PAGE => 'Switching-intent page positioning alternatives and decision criteria.',
            self::FAQ_PAGE => 'Question-led page for common search and support intents.',
            self::AI_ANSWER_PAGE => 'Direct answer page optimized for conversational and AI visibility.',
            self::PILLAR_PAGE => 'Broad authoritative hub page for a core topic cluster.',
            self::SUPPORTING_PAGE => 'Supporting cluster page that reinforces a pillar or program.',
            self::INTEGRATION_PAGE => 'Page explaining integration value, setup, and use cases.',
            self::FEATURE_PAGE => 'Feature-led page mapping a capability to value and use cases.',
            self::STRUCTURED_ANSWER => 'Compact answer asset designed for structured retrieval.',
            self::SCHEMA_MARKUP => 'Structured data asset for machine-readable content context.',
        };
    }

    public function defaultIntent(): string
    {
        return match ($this) {
            self::COMPARISON_PAGE, self::ALTERNATIVE_PAGE => 'commercial_investigation',
            self::LANDING_PAGE, self::INDUSTRY_PAGE, self::LOCATION_PAGE, self::INTEGRATION_PAGE, self::FEATURE_PAGE => 'solution_evaluation',
            self::FAQ_PAGE, self::AI_ANSWER_PAGE, self::STRUCTURED_ANSWER, self::SCHEMA_MARKUP => 'informational',
            self::PILLAR_PAGE => 'authority_building',
            self::BLOG_POST, self::SUPPORTING_PAGE => 'educational',
        };
    }

    public function defaultContentDepth(): string
    {
        return match ($this) {
            self::PILLAR_PAGE => 'pillar',
            self::COMPARISON_PAGE, self::ALTERNATIVE_PAGE, self::INDUSTRY_PAGE, self::LANDING_PAGE => 'deep',
            self::AI_ANSWER_PAGE, self::FAQ_PAGE, self::STRUCTURED_ANSWER, self::SCHEMA_MARKUP => 'concise',
            default => 'standard',
        };
    }

    public function defaultCtaStyle(): string
    {
        return match ($this) {
            self::COMPARISON_PAGE, self::ALTERNATIVE_PAGE => 'decision',
            self::LANDING_PAGE, self::INDUSTRY_PAGE, self::LOCATION_PAGE, self::INTEGRATION_PAGE, self::FEATURE_PAGE => 'conversion',
            self::FAQ_PAGE, self::AI_ANSWER_PAGE, self::STRUCTURED_ANSWER => 'soft_next_step',
            self::PILLAR_PAGE, self::BLOG_POST, self::SUPPORTING_PAGE => 'educational',
            self::SCHEMA_MARKUP => 'none',
        };
    }

    /**
     * @return array<int,string>
     */
    public function defaultSchemaRecommendation(): array
    {
        return match ($this) {
            self::COMPARISON_PAGE, self::ALTERNATIVE_PAGE, self::INDUSTRY_PAGE, self::LOCATION_PAGE, self::LANDING_PAGE, self::INTEGRATION_PAGE, self::FEATURE_PAGE => ['WebPage', 'BreadcrumbList', 'FAQPage'],
            self::FAQ_PAGE, self::AI_ANSWER_PAGE => ['WebPage', 'FAQPage'],
            self::BLOG_POST, self::PILLAR_PAGE, self::SUPPORTING_PAGE => ['Article', 'BreadcrumbList'],
            self::STRUCTURED_ANSWER => ['WebPage', 'FAQPage', 'DefinedTerm'],
            self::SCHEMA_MARKUP => ['WebPage', 'BreadcrumbList'],
        };
    }

    public function defaultInternalLinkingRole(): string
    {
        return match ($this) {
            self::PILLAR_PAGE => 'pillar',
            self::SUPPORTING_PAGE, self::BLOG_POST, self::FAQ_PAGE, self::AI_ANSWER_PAGE, self::STRUCTURED_ANSWER => 'supporting',
            self::COMPARISON_PAGE, self::ALTERNATIVE_PAGE, self::LANDING_PAGE => 'conversion',
            self::INDUSTRY_PAGE, self::LOCATION_PAGE, self::INTEGRATION_PAGE, self::FEATURE_PAGE => 'spoke',
            self::SCHEMA_MARKUP => 'metadata',
        };
    }
}
