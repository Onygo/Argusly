<?php

namespace App\Services\SocialDistribution;

use App\Models\SocialPostVariant;
use Illuminate\Support\Str;

class LinkedInPostTextRenderer
{
    public function renderVariant(SocialPostVariant $variant): string
    {
        return $this->render(
            body: $variant->bodyWithoutRepeatedHook(),
            sourceUrl: $variant->sourceUrl(),
            hashtags: $variant->hashtagsLine(),
        );
    }

    /**
     * @param array<int, string>|string|null $hashtags
     */
    public function render(string $body, ?string $sourceUrl = null, array|string|null $hashtags = null): string
    {
        $body = $this->withDisclaimer(trim($body));
        $parts = [$body];

        $sourceUrl = trim((string) $sourceUrl);
        if ($sourceUrl !== '') {
            $parts[] = $sourceUrl;
        }

        $hashtagsLine = $this->hashtagsLine($hashtags);
        if ($hashtagsLine !== '') {
            $parts[] = $hashtagsLine;
        }

        return trim(collect($parts)->filter()->implode("\n\n"));
    }

    public function disclaimerEnabled(): bool
    {
        return (bool) config('social_distribution.linkedin_test_disclaimer_enabled', false)
            && $this->disclaimerText() !== '';
    }

    public function disclaimerText(): string
    {
        return trim((string) config('social_distribution.linkedin_test_disclaimer_text', ''));
    }

    public function withDisclaimer(string $body): string
    {
        $body = trim($body);

        if (! $this->disclaimerEnabled()) {
            return $body;
        }

        $disclaimer = $this->disclaimerText();
        if ($this->alreadyContainsDisclaimer($body, $disclaimer)) {
            return $body;
        }

        return trim($disclaimer."\n\n".$body);
    }

    private function alreadyContainsDisclaimer(string $body, string $disclaimer): bool
    {
        $normalizedBody = Str::of($body)->replaceMatches('/\s+/', ' ')->lower()->toString();
        $normalizedDisclaimer = Str::of($disclaimer)->replaceMatches('/\s+/', ' ')->lower()->toString();

        return $normalizedDisclaimer !== '' && Str::contains($normalizedBody, $normalizedDisclaimer);
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
            ->take(8)
            ->implode(' ');
    }
}
