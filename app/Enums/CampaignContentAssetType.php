<?php

namespace App\Enums;

enum CampaignContentAssetType: string
{
    case ARTICLE = 'article';
    case LINKEDIN_POST = 'linkedin_post';
    case INSTAGRAM_POST = 'instagram_post';
    case FOUNDER_POST = 'founder_post';
    case NEWSLETTER_SNIPPET = 'newsletter_snippet';
    case FAQ_BLOCK = 'faq_block';
    case ANSWER_BLOCK = 'answer_block';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
