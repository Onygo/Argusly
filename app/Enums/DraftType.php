<?php

namespace App\Enums;

enum DraftType: string
{
    case ORIGINAL = 'original';
    case TRANSLATION = 'translation';
    case HYBRID = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::ORIGINAL => 'Original',
            self::TRANSLATION => 'Translation',
            self::HYBRID => 'Hybrid',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ORIGINAL => 'Originally generated content',
            self::TRANSLATION => 'Translated from another draft',
            self::HYBRID => 'Hybrid draft combining multiple sources',
        };
    }

    public static function default(): self
    {
        return self::ORIGINAL;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTranslation(): bool
    {
        return $this === self::TRANSLATION;
    }

    public function isOriginal(): bool
    {
        return $this === self::ORIGINAL;
    }

    public function isHybrid(): bool
    {
        return $this === self::HYBRID;
    }

    public function canBeTranslated(): bool
    {
        return $this === self::ORIGINAL || $this === self::HYBRID;
    }
}
