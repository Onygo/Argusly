<?php

namespace App\Services\Content;

class ContentImprovementDiffService
{
    /**
     * @return array{html:string, inserted_text:string, removed_text:string}
     */
    public function buildPreview(string $beforeHtml, string $afterHtml): array
    {
        $before = $this->tokenize($beforeHtml);
        $after = $this->tokenize($afterHtml);
        $matrix = [];
        $beforeCount = count($before);
        $afterCount = count($after);

        for ($i = 0; $i <= $beforeCount; $i++) {
            $matrix[$i] = array_fill(0, $afterCount + 1, 0);
        }

        for ($i = $beforeCount - 1; $i >= 0; $i--) {
            for ($j = $afterCount - 1; $j >= 0; $j--) {
                $matrix[$i][$j] = $before[$i] === $after[$j]
                    ? $matrix[$i + 1][$j + 1] + 1
                    : max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
            }
        }

        $i = 0;
        $j = 0;
        $html = [];
        $inserted = [];
        $removed = [];

        while ($i < $beforeCount && $j < $afterCount) {
            if ($before[$i] === $after[$j]) {
                $html[] = e($before[$i]);
                $i++;
                $j++;

                continue;
            }

            if ($matrix[$i + 1][$j] >= $matrix[$i][$j + 1]) {
                $removed[] = $before[$i];
                $html[] = '<del class="rounded bg-rose-100 px-1 text-rose-700">' . e($before[$i]) . '</del>';
                $i++;

                continue;
            }

            $inserted[] = $after[$j];
            $html[] = '<ins class="rounded bg-emerald-100 px-1 text-emerald-800 no-underline">' . e($after[$j]) . '</ins>';
            $j++;
        }

        while ($i < $beforeCount) {
            $removed[] = $before[$i];
            $html[] = '<del class="rounded bg-rose-100 px-1 text-rose-700">' . e($before[$i]) . '</del>';
            $i++;
        }

        while ($j < $afterCount) {
            $inserted[] = $after[$j];
            $html[] = '<ins class="rounded bg-emerald-100 px-1 text-emerald-800 no-underline">' . e($after[$j]) . '</ins>';
            $j++;
        }

        return [
            'html' => implode(' ', $html),
            'inserted_text' => trim(implode(' ', $inserted)),
            'removed_text' => trim(implode(' ', $removed)),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $html): array
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        if ($plain === '') {
            return [];
        }

        return preg_split('/\s+/u', $plain) ?: [];
    }
}
