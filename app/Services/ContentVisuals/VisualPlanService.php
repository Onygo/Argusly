<?php

namespace App\Services\ContentVisuals;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class VisualPlanService
{
    /**
     * @param array<string,mixed>|null $meta
     * @return array{featured:array<string,mixed>|null,assets:array<int,array<string,mixed>>,version:int}
     */
    public function fromMeta(?array $meta): array
    {
        return $this->normalize(data_get($meta ?? [], 'visual_plan', []));
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function putInMeta(array $meta, array $plan): array
    {
        $normalized = $this->normalize($plan);

        if ($normalized['featured'] === null && $normalized['assets'] === []) {
            unset($meta['visual_plan']);

            return $meta;
        }

        $meta['visual_plan'] = $normalized;

        return $meta;
    }

    /**
     * @param mixed $plan
     * @return array{featured:array<string,mixed>|null,assets:array<int,array<string,mixed>>,version:int}
     */
    public function normalize(mixed $plan): array
    {
        if (! is_array($plan)) {
            $plan = [];
        }

        $featured = $this->normalizeFeatured(data_get($plan, 'featured'));
        $assets = collect((array) data_get($plan, 'assets', data_get($plan, 'inline_visuals', [])))
            ->map(fn (mixed $asset) => $this->normalizeAsset($asset))
            ->filter()
            ->unique('asset_key')
            ->values()
            ->all();

        return [
            'featured' => $featured,
            'assets' => $assets,
            'version' => 1,
        ];
    }

    /**
     * @param mixed $featured
     * @return array<string,mixed>|null
     */
    private function normalizeFeatured(mixed $featured): ?array
    {
        if (! is_array($featured)) {
            return null;
        }

        $prompt = $this->cleanText(data_get($featured, 'prompt', data_get($featured, 'instructions')));
        $altText = $this->cleanText(data_get($featured, 'alt_text'));
        $caption = $this->cleanText(data_get($featured, 'caption'));

        if ($prompt === '' && $altText === '' && $caption === '') {
            return null;
        }

        return array_filter([
            'prompt' => $prompt,
            'alt_text' => $altText,
            'caption' => $caption,
        ], fn (mixed $value) => trim((string) $value) !== '');
    }

    /**
     * @param mixed $asset
     * @return array<string,mixed>|null
     */
    private function normalizeAsset(mixed $asset): ?array
    {
        if (! is_array($asset)) {
            return null;
        }

        $type = $this->normalizeType(data_get($asset, 'type'));
        $assetKey = $this->normalizeAssetKey(data_get($asset, 'asset_key', data_get($asset, 'key')));

        if ($assetKey === '') {
            $assetKey = $this->normalizeAssetKey($type . '-' . Str::random(6));
        }

        $structuredData = $this->normalizeStructuredData(data_get($asset, 'structured_data', data_get($asset, 'data')), $type);

        return array_filter([
            'asset_key' => $assetKey,
            'type' => $type,
            'status' => $this->normalizeStatus(data_get($asset, 'status')),
            'required' => (bool) data_get($asset, 'required', false),
            'placement' => $this->cleanText(data_get($asset, 'placement', data_get($asset, 'suggested_placement'))),
            'caption' => $this->cleanText(data_get($asset, 'caption')),
            'alt_text' => $this->cleanText(data_get($asset, 'alt_text')),
            'prompt' => $this->cleanText(data_get($asset, 'prompt', data_get($asset, 'instructions'))),
            'structured_data' => $structuredData,
        ], fn (mixed $value) => $value !== null && $value !== '' && $value !== []);
    }

    private function normalizeType(mixed $type): string
    {
        $candidate = Str::snake(strtolower(trim((string) $type)));

        return in_array($candidate, [
            'chart',
            'bar_chart',
            'diagram',
            'image',
            'conceptual_visual',
            'stat_card',
            'comparison_visual',
            'simple_process_diagram',
        ], true) ? $candidate : 'image';
    }

    private function normalizeStatus(mixed $status): string
    {
        $candidate = Str::snake(strtolower(trim((string) $status)));

        return in_array($candidate, ['pending', 'queued', 'generating', 'ready', 'failed'], true)
            ? $candidate
            : 'pending';
    }

    private function normalizeAssetKey(mixed $key): string
    {
        return trim(Str::slug(Str::limit((string) $key, 80, ''), '-'), '-');
    }

    private function cleanText(mixed $value): string
    {
        return trim(Str::limit(strip_tags((string) $value), 800, ''));
    }

    /**
     * @param mixed $data
     * @return array<string,mixed>
     */
    private function normalizeStructuredData(mixed $data, string $type): array
    {
        if (! is_array($data)) {
            return [];
        }

        $title = $this->cleanText(data_get($data, 'title'));
        $rows = collect((array) data_get($data, 'data', data_get($data, 'items', [])))
            ->map(function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $label = $this->cleanText(data_get($row, 'label', data_get($row, 'name')));
                if ($label === '') {
                    return null;
                }

                return array_filter([
                    'label' => $label,
                    'value' => is_numeric(data_get($row, 'value')) ? (float) data_get($row, 'value') : null,
                    'text' => $this->cleanText(data_get($row, 'text', data_get($row, 'description'))),
                ], fn (mixed $value) => $value !== null && $value !== '');
            })
            ->filter()
            ->take(8)
            ->values()
            ->all();

        if ($title === '' && $rows === []) {
            return [];
        }

        return array_filter([
            'type' => $type,
            'title' => $title,
            'data' => $rows,
        ], fn (mixed $value) => $value !== '' && $value !== []);
    }
}
