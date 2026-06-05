<?php

namespace App\Enums;

enum SocialPostType: string
{
    case THOUGHT_LEADERSHIP = 'thought_leadership';
    case TEXT = 'text';
    case ARTICLE = 'article';
    case IMAGE = 'image';
    case INSIGHT_POST = 'insight_post';
    case BUILDING_IN_PUBLIC = 'building_in_public';
    case TECHNICAL_DEEP_DIVE = 'technical_deep_dive';
    case SHORT_HOOK = 'short_hook';
    case EVENT_BASED_POST = 'event_based_post';
    case EVENT_BASED = 'event_based';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
