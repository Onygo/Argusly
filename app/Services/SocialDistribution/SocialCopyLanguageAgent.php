<?php

namespace App\Services\SocialDistribution;

use App\Support\DutchTextCasingNormalizer;
use Illuminate\Support\Str;

class SocialCopyLanguageAgent
{
    /**
     * @return array{
     *     hook:string,
     *     body:string,
     *     report:array{language:string,changed:bool,warnings:list<string>,corrections:list<string>}
     * }
     */
    public function review(string $hook, string $body, string $language): array
    {
        $language = $this->normalizeLanguage($language);
        $corrections = [];
        $warnings = [];

        $hook = $this->normalizeWhitespace($hook);
        $body = $this->normalizeWhitespace($body);

        foreach ($this->rules($language) as $incorrect => $correct) {
            $newHook = str_replace($incorrect, $correct, $hook);
            $newBody = str_replace($incorrect, $correct, $body);

            if ($newHook !== $hook || $newBody !== $body) {
                $corrections[] = $incorrect.' -> '.$correct;
                $hook = $newHook;
                $body = $newBody;
            }
        }

        if ($language === 'nl') {
            $newHook = DutchTextCasingNormalizer::normalizeText($hook);
            $newBody = DutchTextCasingNormalizer::normalizeText($body);

            if ($newHook !== $hook || $newBody !== $body) {
                $corrections[] = 'Dutch title case -> Dutch sentence case';
                $hook = $newHook;
                $body = $newBody;
            }
        }

        $combined = trim($hook."\n".$body);
        if ($combined !== '') {
            $warnings = $this->languageWarnings($combined, $language);
        }

        return [
            'hook' => $hook,
            'body' => $body,
            'report' => [
                'language' => $language,
                'changed' => $corrections !== [],
                'warnings' => $warnings,
                'corrections' => array_values(array_unique($corrections)),
            ],
        ];
    }

    private function normalizeLanguage(string $language): string
    {
        return in_array($language, ['nl', 'en'], true) ? $language : 'en';
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace("/[ \t]+\n/", "\n", str_replace("\r\n", "\n", $value)) ?? '');
    }

    /**
     * @return array<string,string>
     */
    private function rules(string $language): array
    {
        return match ($language) {
            'nl' => [
                'geen nieuwe buzzword' => 'geen nieuw buzzword',
                'deze framework' => 'dit framework',
                'deze model' => 'dit model',
                'deze systeem' => 'dit systeem',
                'een andere architectuur voor je marketing organisatie' => 'een andere architectuur voor je marketingorganisatie',
            ],
            'en' => [
                'an unique' => 'a unique',
                'a operational' => 'an operational',
                'a approval' => 'an approval',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function languageWarnings(string $text, string $language): array
    {
        $lower = Str::lower($text);

        if ($language === 'nl' && $this->countMatches($lower, [' the ', ' and ', ' with ', ' for ', ' your ']) >= 3) {
            return ['Text may contain English phrases while Dutch was requested.'];
        }

        if ($language === 'en' && $this->countMatches($lower, [' het ', ' een ', ' geen ', ' voor ', ' maar ']) >= 3) {
            return ['Text may contain Dutch phrases while English was requested.'];
        }

        return [];
    }

    /**
     * @param list<string> $needles
     */
    private function countMatches(string $text, array $needles): int
    {
        return collect($needles)
            ->sum(fn (string $needle): int => substr_count(' '.$text.' ', $needle));
    }
}
