<?php

namespace App\Enums;

enum SupportedLanguage: string
{
    case EN = 'en';
    case NL = 'nl';
    case DE = 'de';
    case FR = 'fr';
    case ES = 'es';

    public function label(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::NL => 'Nederlands',
            self::DE => 'Deutsch',
            self::FR => 'Français',
            self::ES => 'Español',
        };
    }

    public function nativeLabel(): string
    {
        return $this->label();
    }

    public function englishLabel(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::NL => 'Dutch',
            self::DE => 'German',
            self::FR => 'French',
            self::ES => 'Spanish',
        };
    }

    public function flag(): string
    {
        return match ($this) {
            self::EN => '🇬🇧',
            self::NL => '🇳🇱',
            self::DE => '🇩🇪',
            self::FR => '🇫🇷',
            self::ES => '🇪🇸',
        };
    }

    public static function default(): self
    {
        return self::EN;
    }

    public static function platformDefault(): self
    {
        return self::EN;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'englishLabel' => $case->englishLabel(),
                'flag' => $case->flag(),
            ],
            self::cases()
        );
    }

    public static function fromBrowserLocale(string $locale): self
    {
        return self::tryFromString($locale) ?? self::EN;
    }

    public static function normalizeLocale(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace('_', '-', $normalized);
        $segments = explode('-', $normalized);
        $language = trim((string) ($segments[0] ?? ''));

        return $language !== '' ? $language : null;
    }

    public static function tryFromString(?string $value): ?self
    {
        return self::tryFrom(self::normalizeLocale($value) ?? '');
    }

    public static function fromStringOrDefault(?string $value): self
    {
        return self::tryFromString($value) ?? self::default();
    }

    public function isRtl(): bool
    {
        return false;
    }
}
