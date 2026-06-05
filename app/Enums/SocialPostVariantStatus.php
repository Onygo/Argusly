<?php

namespace App\Enums;

enum SocialPostVariantStatus: string
{
    case GENERATION_REQUESTED = 'generation_requested';
    case GENERATING = 'generating';
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case CHANGES_REQUESTED = 'changes_requested';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SCHEDULED = 'scheduled';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
}
