<?php

namespace App\Enums;

enum LearningRecommendationStatus: string
{
    case PROPOSED = 'proposed';
    case REVIEWING = 'reviewing';
    case ACCEPTED = 'accepted';
    case DISMISSED = 'dismissed';
    case COMPLETED = 'completed';
}
