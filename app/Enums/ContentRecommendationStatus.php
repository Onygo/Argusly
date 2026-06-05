<?php

namespace App\Enums;

enum ContentRecommendationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DISMISSED = 'dismissed';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
