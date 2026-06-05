<?php

namespace App\Enums;

enum SocialPublicationStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case SCHEDULED = 'scheduled';
    case QUEUED = 'queued';
    case RATE_LIMITED = 'rate_limited';
    case PUBLISHING = 'publishing';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
}
