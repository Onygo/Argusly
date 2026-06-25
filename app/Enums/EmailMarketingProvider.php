<?php

namespace App\Enums;

enum EmailMarketingProvider: string
{
    case DMT = 'dmt';
    case MAILCHIMP = 'mailchimp';
    case MAILJET = 'mailjet';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::DMT => 'Digital Marketing Tools',
            self::MAILCHIMP => 'Mailchimp',
            self::MAILJET => 'Mailjet',
        };
    }
}
