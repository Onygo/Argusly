<?php

namespace App\Enums;

enum SocialRepostSuggestionStatus: string
{
    case PROPOSED = 'proposed';
    case APPROVED = 'approved';
    case DISMISSED = 'dismissed';
    case CONVERTED = 'converted';
}
