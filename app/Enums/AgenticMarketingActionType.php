<?php

namespace App\Enums;

enum AgenticMarketingActionType: string
{
    case RefreshArticle = 'refresh_article';
    case AddAnswerBlock = 'add_answer_block';
    case ImproveInternalLinks = 'improve_internal_links';
    case CreateLocaleVariant = 'create_locale_variant';
    case UpdateMeta = 'update_meta';
    case AddSchema = 'add_schema';
    case CreateArticle = 'create_article';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
