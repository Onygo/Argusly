<?php

namespace App\Services\Images;

class OgImageTextComposer
{
    /**
     * @return array{keyword:string,title:string}
     */
    public function compose(?string $title, ?string $primaryKeyword, bool $omitKeywordIfInTitle = true): array
    {
        $normalizedTitle = $this->normalize($title);
        $normalizedKeyword = $this->normalize($primaryKeyword);

        if ($normalizedTitle === '' && $normalizedKeyword === '') {
            return [
                'keyword' => '',
                'title' => 'Untitled article',
            ];
        }

        if ($normalizedTitle === '' && $normalizedKeyword !== '') {
            return [
                'keyword' => '',
                'title' => $normalizedKeyword,
            ];
        }

        if ($normalizedKeyword === '') {
            return [
                'keyword' => '',
                'title' => $normalizedTitle,
            ];
        }

        if (
            $omitKeywordIfInTitle
            && mb_stripos($normalizedTitle, $normalizedKeyword) !== false
        ) {
            return [
                'keyword' => '',
                'title' => $normalizedTitle,
            ];
        }

        return [
            'keyword' => $normalizedKeyword,
            'title' => $normalizedTitle,
        ];
    }

    private function normalize(?string $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
