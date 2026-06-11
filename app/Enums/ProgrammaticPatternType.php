<?php

namespace App\Enums;

enum ProgrammaticPatternType: string
{
    case INDUSTRY_PAGE = 'industry_page';
    case LOCATION_PAGE = 'location_page';
    case COMPARISON_PAGE = 'comparison_page';
    case ALTERNATIVE_PAGE = 'alternative_page';
    case USE_CASE_PAGE = 'use_case_page';
    case FAQ_LIBRARY = 'faq_library';
    case AI_ANSWER_LIBRARY = 'ai_answer_library';
    case FEATURE_PAGE = 'feature_page';
    case INTEGRATION_PAGE = 'integration_page';

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
            self::INDUSTRY_PAGE => 'Industry page',
            self::LOCATION_PAGE => 'Location page',
            self::COMPARISON_PAGE => 'Comparison page',
            self::ALTERNATIVE_PAGE => 'Alternative page',
            self::USE_CASE_PAGE => 'Use case page',
            self::FAQ_LIBRARY => 'FAQ library',
            self::AI_ANSWER_LIBRARY => 'AI answer library',
            self::FEATURE_PAGE => 'Feature page',
            self::INTEGRATION_PAGE => 'Integration page',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::INDUSTRY_PAGE => 'Repeatable pages that position a product, service, or software category for industries.',
            self::LOCATION_PAGE => 'Repeatable pages for services, products, or solutions across locations.',
            self::COMPARISON_PAGE => 'Pages comparing two products, tools, services, or vendors.',
            self::ALTERNATIVE_PAGE => 'Alternative-to pages targeting replacement and switching intent.',
            self::USE_CASE_PAGE => 'Pages mapping a tool, service, or product to repeatable use cases.',
            self::FAQ_LIBRARY => 'Collections of question-led pages around a topic.',
            self::AI_ANSWER_LIBRARY => 'Answer blocks or pages designed for conversational and AI answer visibility.',
            self::FEATURE_PAGE => 'Repeatable pages for feature-led search and evaluation intent.',
            self::INTEGRATION_PAGE => 'Repeatable pages for integrations, connectors, and platform pairings.',
        };
    }
}
