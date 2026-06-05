<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case DRAFT = 'draft';
    case OAUTH_PENDING = 'oauth_pending';
    case CONNECTED = 'connected';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case REVOKED = 'revoked';
    case ERROR = 'error';
    case NEEDS_REAUTH = 'needs_reauth';
    case DISABLED = 'disabled';
    case FAILED = 'failed';
}
