<?php

namespace App\Services\Attribution;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use Illuminate\Support\Collection;

class AttributionIdentityMatcher
{
    public const CONFIDENCE_EXACT = 'exact';

    public const CONFIDENCE_STRONG = 'strong';

    public const CONFIDENCE_PROBABLE = 'probable';

    public const CONFIDENCE_WEAK = 'weak';

    public const CONFIDENCE_UNMATCHED = 'unmatched';

    /**
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array{touchpoint: AttributionTouchpoint, match_confidence: string, score: int}>
     */
    public function matches(AttributionConversion $conversion, int $lookbackDays = 90, array $settings = []): Collection
    {
        $lookbackDays = (int) ($settings['lookback_days'] ?? $lookbackDays);
        $lookbackDays = max(1, $lookbackDays);

        return AttributionTouchpoint::query()
            ->forWorkspace($conversion->workspace_id)
            ->where('occurred_at', '<=', $conversion->occurred_at)
            ->where('occurred_at', '>=', $conversion->occurred_at->copy()->subDays($lookbackDays))
            ->orderBy('occurred_at')
            ->limit((int) ($settings['max_touchpoints'] ?? 1000))
            ->get()
            ->map(function (AttributionTouchpoint $touchpoint) use ($conversion): array {
                [$confidence, $score] = $this->confidence($touchpoint, $conversion);

                return [
                    'touchpoint' => $touchpoint,
                    'match_confidence' => $confidence,
                    'score' => $score,
                ];
            })
            ->filter(fn (array $match): bool => $match['match_confidence'] !== self::CONFIDENCE_UNMATCHED)
            ->sort(function (array $first, array $second): int {
                $score = $second['score'] <=> $first['score'];

                if ($score !== 0) {
                    return $score;
                }

                return ($first['touchpoint']->occurred_at?->getTimestamp() ?? 0)
                    <=> ($second['touchpoint']->occurred_at?->getTimestamp() ?? 0);
            })
            ->values();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function confidence(AttributionTouchpoint $touchpoint, AttributionConversion $conversion): array
    {
        $conversionKey = $this->firstFilled([
            $conversion->contact_key,
            $conversion->email_hash,
            data_get($conversion->raw_reference, 'anonymous_or_contact_key'),
            data_get($conversion->raw_reference, 'session_key'),
        ]);

        if ($conversionKey !== null && $this->same($touchpoint->anonymous_or_contact_key, $conversionKey)) {
            return [self::CONFIDENCE_EXACT, 100];
        }

        if ($conversion->email_hash && $this->same($touchpoint->anonymous_or_contact_key, $conversion->email_hash)) {
            return [self::CONFIDENCE_EXACT, 100];
        }

        if ($conversion->deal_id && $this->same(data_get($touchpoint->raw_reference, 'deal_id'), $conversion->deal_id)) {
            return [self::CONFIDENCE_STRONG, 90];
        }

        if ($conversion->contact_key && $this->same(data_get($touchpoint->raw_reference, 'contact_key'), $conversion->contact_key)) {
            return [self::CONFIDENCE_STRONG, 85];
        }

        if ($conversion->email_hash && $this->same(data_get($touchpoint->raw_reference, 'email_hash'), $conversion->email_hash)) {
            return [self::CONFIDENCE_STRONG, 85];
        }

        $campaign = $this->firstFilled([
            data_get($conversion->raw_reference, 'campaign_id'),
            data_get($conversion->raw_reference, 'utm_campaign'),
            data_get($conversion->raw_reference, 'campaign'),
        ]);

        if ($campaign !== null && $this->same($touchpoint->campaign_id, $campaign)) {
            return [self::CONFIDENCE_PROBABLE, 70];
        }

        $landingPage = $this->firstFilled([
            data_get($conversion->raw_reference, 'landing_page'),
            data_get($conversion->raw_reference, 'first_conversion_url'),
        ]);

        if ($landingPage !== null && $this->sameUrl($touchpoint->landing_page, $landingPage)) {
            return [self::CONFIDENCE_WEAK, 40];
        }

        $source = data_get($conversion->raw_reference, 'source') ?: data_get($conversion->raw_reference, 'utm_source');
        $medium = data_get($conversion->raw_reference, 'medium') ?: data_get($conversion->raw_reference, 'utm_medium');

        if ($source && $medium && $this->same($touchpoint->source, $source) && $this->same($touchpoint->medium, $medium)) {
            return [self::CONFIDENCE_WEAK, 35];
        }

        if ($touchpoint->session_key && $this->same($touchpoint->session_key, data_get($conversion->raw_reference, 'session_key'))) {
            return [self::CONFIDENCE_STRONG, 80];
        }

        return [self::CONFIDENCE_UNMATCHED, 0];
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function same(mixed $first, mixed $second): bool
    {
        return strtolower(trim((string) $first)) === strtolower(trim((string) $second))
            && trim((string) $first) !== ''
            && trim((string) $second) !== '';
    }

    private function sameUrl(mixed $first, mixed $second): bool
    {
        $normalize = function (mixed $value): string {
            $value = strtolower(trim((string) $value));
            $value = preg_replace('/[?#].*$/', '', $value) ?? $value;

            return rtrim($value, '/');
        };

        return $normalize($first) !== '' && $normalize($first) === $normalize($second);
    }
}
