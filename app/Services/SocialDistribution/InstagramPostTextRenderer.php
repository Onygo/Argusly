<?php

namespace App\Services\SocialDistribution;

use App\Models\SocialPostVariant;
use Illuminate\Support\Str;

class InstagramPostTextRenderer
{
    public function renderVariant(SocialPostVariant $variant): string
    {
        return $this->render(
            body: $variant->bodyWithoutRepeatedHook(),
            hashtags: $variant->hashtagsLine(),
        );
    }

    public function render(string $body, array|string|null $hashtags = null): string
    {
        $body = trim($body);
        $hashtagsLine = $this->hashtagsLine($hashtags);

        return Str::limit(trim(collect([$body, $hashtagsLine])->filter()->implode("\n\n")), 2200, '');
    }

    /**
     * @param array<int, string>|string|null $hashtags
     */
    private function hashtagsLine(array|string|null $hashtags): string
    {
        if (is_string($hashtags)) {
            return trim($hashtags);
        }

        return collect($hashtags ?? [])
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->filter()
            ->map(fn (string $tag): string => Str::startsWith($tag, '#') ? $tag : '#'.$tag)
            ->unique()
            ->take(12)
            ->implode(' ');
    }
}
