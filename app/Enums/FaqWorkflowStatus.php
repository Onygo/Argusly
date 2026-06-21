<?php

namespace App\Enums;

enum FaqWorkflowStatus: string
{
    case PENDING = 'pending';
    case ANALYZING = 'analyzing';
    case GENERATED = 'generated';
    case REVIEW_REQUIRED = 'review_required';
    case PUBLISHED = 'published';
    case FAILED = 'failed';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
