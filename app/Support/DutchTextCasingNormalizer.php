<?php

namespace App\Support;

use Illuminate\Support\Str;

class DutchTextCasingNormalizer
{
    public static function normalizeText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return collect(explode("\n", $text))
            ->map(fn (string $line): string => self::normalizeLine($line))
            ->implode("\n");
    }

    public static function normalizeLine(string $line): string
    {
        if (! self::looksLikeTitleCase($line)) {
            return $line;
        }

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\']*(?:-[\p{L}\p{N}][\p{L}\p{M}\p{N}\']*)*/u', $line, $matches, PREG_OFFSET_CAPTURE);

        $words = $matches[0] ?? [];
        if ($words === []) {
            return $line;
        }

        $firstWordOffset = (int) $words[0][1];
        $normalized = '';
        $cursor = 0;

        foreach ($words as [$word, $offset]) {
            $offset = (int) $offset;
            $normalized .= substr($line, $cursor, $offset - $cursor);
            $normalized .= $offset === $firstWordOffset
                ? (string) $word
                : self::sentenceCaseWord((string) $word);
            $cursor = $offset + strlen((string) $word);
        }

        return $normalized.substr($line, $cursor);
    }

    private static function looksLikeTitleCase(string $line): bool
    {
        $words = [];
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\']*(?:-[\p{L}\p{N}][\p{L}\p{M}\p{N}\']*)*/u', $line, $words);

        $candidates = collect($words[0] ?? [])
            ->filter(fn (string $word): bool => mb_strlen($word) > 2 && ! self::shouldPreserveCase($word))
            ->values();

        if ($candidates->count() < 3) {
            return false;
        }

        $capitalized = $candidates
            ->filter(fn (string $word): bool => preg_match('/^\p{Lu}/u', $word) === 1)
            ->count();

        return $capitalized >= 3 && ($capitalized / max(1, $candidates->count())) >= 0.45;
    }

    private static function sentenceCaseWord(string $word): string
    {
        if (self::shouldPreserveCase($word)) {
            return $word;
        }

        return collect(explode('-', $word))
            ->map(function (string $part): string {
                if (self::shouldPreserveCase($part)) {
                    return $part;
                }

                return Str::lower($part);
            })
            ->implode('-');
    }

    private static function shouldPreserveCase(string $word): bool
    {
        if (preg_match('/^[A-Z0-9]{2,}$/', $word) === 1) {
            return true;
        }

        if (preg_match('/[a-z][A-Z]/', $word) === 1) {
            return true;
        }

        return in_array($word, [
            'API',
            'B2B',
            'B2C',
            'CEO',
            'CRM',
            'GEO',
            'Google',
            'HubSpot',
            'LinkedIn',
            'OpenAI',
            'PublishLayer',
            'ROI',
            'SEO',
            'WordPress',
        ], true);
    }
}
