<?php

function runtimePlaceholders(string $value): array
{
    preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $value, $matches);

    return array_values(array_unique($matches[0] ?? []));
}

it('keeps Dutch app runtime translation keys available in English', function (): void {
    $english = trans('app.runtime', [], 'en');
    $dutch = trans('app.runtime', [], 'nl');

    expect($english)->toBeArray()
        ->and($dutch)->toBeArray();

    $missingInEnglish = array_diff(array_keys($dutch), array_keys($english));

    expect($missingInEnglish)->toBe([]);
});

it('keeps runtime translation placeholders aligned between English and Dutch', function (): void {
    $english = trans('app.runtime', [], 'en');
    $dutch = trans('app.runtime', [], 'nl');
    $mismatches = [];

    foreach ($dutch as $key => $translation) {
        if (! is_string($translation) || ! is_string($english[$key] ?? null)) {
            continue;
        }

        $sourcePlaceholders = runtimePlaceholders((string) $english[$key]);
        $targetPlaceholders = runtimePlaceholders($translation);
        sort($sourcePlaceholders);
        sort($targetPlaceholders);

        if ($sourcePlaceholders !== $targetPlaceholders) {
            $mismatches[$key] = [
                'en' => $sourcePlaceholders,
                'nl' => $targetPlaceholders,
            ];
        }
    }

    expect($mismatches)->toBe([]);
});
