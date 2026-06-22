<?php

namespace App\Services\SocialDistribution;

use App\Enums\SocialPlatform;

class SocialPlatformCapabilities
{
    /**
     * @return array<string,mixed>
     */
    public function forPlatform(SocialPlatform|string|null $platform): array
    {
        $value = $platform instanceof SocialPlatform ? $platform->value : (string) $platform;

        return (array) config('social-platforms.'.$value, []);
    }

    public function label(SocialPlatform|string|null $platform): string
    {
        $value = $platform instanceof SocialPlatform ? $platform->value : (string) $platform;

        return (string) ($this->forPlatform($value)['label'] ?? str($value ?: 'social')->replace('_', ' ')->title());
    }

    public function postLabel(SocialPlatform|string|null $platform): string
    {
        $value = $platform instanceof SocialPlatform ? $platform->value : (string) $platform;

        return (string) ($this->forPlatform($value)['post_label'] ?? $this->label($value).' Post');
    }

    public function requiresMedia(SocialPlatform|string|null $platform): bool
    {
        return (bool) ($this->forPlatform($platform)['requires_media'] ?? false);
    }

    public function captionLimit(SocialPlatform|string|null $platform): int
    {
        return (int) ($this->forPlatform($platform)['caption_limit'] ?? 3000);
    }
}
