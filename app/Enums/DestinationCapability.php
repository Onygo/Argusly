<?php

namespace App\Enums;

enum DestinationCapability: string
{
    case REMOTE_VERIFICATION = 'supports_remote_verification';
    case PREVIEW_URL = 'supports_preview_url';
    case STATUS_SYNC = 'supports_status_sync';
    case MARKDOWN_PUSH = 'supports_markdown_push';
    case SEO_META_SYNC = 'supports_seo_meta_sync';
    case SLUG_UPDATES = 'supports_slug_updates';

    public function label(): string
    {
        return match ($this) {
            self::REMOTE_VERIFICATION => 'Remote verification',
            self::PREVIEW_URL => 'Preview URLs',
            self::STATUS_SYNC => 'Status sync',
            self::MARKDOWN_PUSH => 'Markdown push',
            self::SEO_META_SYNC => 'SEO metadata sync',
            self::SLUG_UPDATES => 'Slug updates',
        };
    }
}
