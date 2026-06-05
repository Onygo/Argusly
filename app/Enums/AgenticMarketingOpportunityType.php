<?php

namespace App\Enums;

enum AgenticMarketingOpportunityType: string
{
    case Refresh = 'refresh';
    case AnswerCoverage = 'answer_coverage';
    case InternalLinks = 'internal_links';
    case LocaleExpansion = 'locale_expansion';
    case Metadata = 'metadata';
    case Schema = 'schema';
    case NewArticle = 'new_article';
    case SeoIndexability = 'seo_indexability';
    case ContentNetwork = 'content_network';
    case AiVisibility = 'ai_visibility';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
