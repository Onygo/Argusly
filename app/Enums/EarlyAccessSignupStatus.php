<?php

namespace App\Enums;

enum EarlyAccessSignupStatus: string
{
    case NEW = 'new';
    case REVIEWED = 'reviewed';
    case APPROVED = 'approved';
    case INVITED = 'invited';
    case ACTIVATED = 'activated';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::REVIEWED => 'Reviewed',
            self::APPROVED => 'Approved',
            self::INVITED => 'Invited',
            self::ACTIVATED => 'Activated',
            self::REJECTED => 'Rejected',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::NEW => in_array($target, [self::REVIEWED, self::APPROVED, self::REJECTED], true),
            self::REVIEWED => in_array($target, [self::APPROVED, self::REJECTED], true),
            self::APPROVED => in_array($target, [self::INVITED, self::REJECTED], true),
            self::INVITED => in_array($target, [self::ACTIVATED, self::REJECTED], true),
            self::REJECTED => in_array($target, [self::REVIEWED, self::APPROVED], true),
            self::ACTIVATED => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
