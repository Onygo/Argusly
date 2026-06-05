<?php

namespace App\Enums;

enum ContentDestinationType: string
{
    case WORDPRESS = 'wordpress';
    case LARAVEL = 'laravel';
    case API = 'api';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $item): string => $item->value, self::cases());
    }

    public static function normalize(self|string|null $type): ?string
    {
        $value = $type instanceof self
            ? $type->value
            : strtolower(trim((string) $type));

        return match ($value) {
            self::WORDPRESS->value, 'wp' => self::WORDPRESS->value,
            self::LARAVEL->value, 'laravel_connector' => self::LARAVEL->value,
            self::API->value, 'api_only', 'custom_cms', 'webhook', 'webhook_target' => self::API->value,
            default => null,
        };
    }

    public static function fromNormalized(self|string|null $type): ?self
    {
        $normalized = self::normalize($type);

        return $normalized ? self::from($normalized) : null;
    }

    public static function label(self|string|null $type): string
    {
        return match (self::fromNormalized($type)) {
            self::WORDPRESS => 'WordPress',
            self::LARAVEL => 'Laravel',
            self::API => 'API',
            default => 'Unknown destination',
        };
    }

    /**
     * Determine if this destination type requires strict remote existence verification.
     *
     * Laravel destinations are internal/native and don't need strict remote checks.
     * WordPress and API destinations are truly external and require verification.
     */
    public function requiresStrictRemoteVerification(): bool
    {
        return match ($this) {
            self::WORDPRESS => true,
            self::API => true,
            self::LARAVEL => false,
        };
    }

    /**
     * Determine if this destination is a native/internal destination.
     *
     * Native destinations are managed within the PublishLayer ecosystem.
     */
    public function isNativeDestination(): bool
    {
        return match ($this) {
            self::LARAVEL => true,
            self::WORDPRESS => false,
            self::API => false,
        };
    }
}
