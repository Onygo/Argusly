<?php

namespace App\Enums;

enum ContentRefreshTaskType: string
{
    case REFRESH_CONTENT = 'refresh_content';
    case IMPROVE_INTERNAL_LINKS = 'improve_internal_links';
    case RESTORE_AI_VISIBILITY = 'restore_ai_visibility';
    case UPDATE_ENTITY_COVERAGE = 'update_entity_coverage';
    case RECONNECT_CAMPAIGN = 'reconnect_campaign';
    case RELATED_CONTENT_SUPPORT = 'related_content_support';

    public function label(): string
    {
        return match ($this) {
            self::REFRESH_CONTENT => 'Refresh content',
            self::IMPROVE_INTERNAL_LINKS => 'Improve internal links',
            self::RESTORE_AI_VISIBILITY => 'Restore AI visibility',
            self::UPDATE_ENTITY_COVERAGE => 'Update entity coverage',
            self::RECONNECT_CAMPAIGN => 'Reconnect campaign',
            self::RELATED_CONTENT_SUPPORT => 'Related content support',
        };
    }
}
