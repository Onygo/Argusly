<?php

namespace App\Enums;

enum AsyncOperationType: string
{
    case DRAFT_GENERATION = 'draft_generation';
    case DRAFT_REGENERATION = 'draft_regeneration';
    case DRAFT_TRANSLATION = 'draft_translation';
    case SEO_AUDIT = 'seo_audit';
}
