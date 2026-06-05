<?php

namespace App\Enums;

enum SeoAuditSuggestionState: string
{
    case SUGGESTED = 'suggested';
    case APPLIED_LOCAL = 'applied_local';
    case SYNCED_EXTERNAL = 'synced_external';

    public function label(): string
    {
        return match ($this) {
            self::SUGGESTED => 'Suggested',
            self::APPLIED_LOCAL => 'Applied to content',
            self::SYNCED_EXTERNAL => 'Synced to WordPress',
        };
    }
}
