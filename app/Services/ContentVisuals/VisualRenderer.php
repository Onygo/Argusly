<?php

namespace App\Services\ContentVisuals;

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Draft;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class VisualRenderer
{
    public function __construct(
        private readonly VisualPlanService $plans,
    ) {}

    public function renderDraftHtml(Draft $draft, ?string $html = null): string
    {
        $draft->loadMissing('content.images');

        return $this->replacePlaceholders(
            html: (string) ($html ?? $draft->content_html),
            visualPlan: $this->plans->fromMeta(is_array($draft->meta) ? $draft->meta : []),
            content: $draft->content
        );
    }

    public function renderContentHtml(Content $content, ?string $html = null): string
    {
        $content->loadMissing(['images', 'currentRevision', 'currentVersion']);

        $meta = is_array($content->currentRevision?->meta)
            ? $content->currentRevision->meta
            : (is_array($content->currentVersion?->meta ?? null) ? $content->currentVersion->meta : []);

        return $this->replacePlaceholders(
            html: (string) ($html ?? $content->currentRevision?->content_html ?? $content->currentVersion?->body ?? ''),
            visualPlan: $this->plans->fromMeta($meta),
            content: $content
        );
    }

    /**
     * @param array{featured:array<string,mixed>|null,assets:array<int,array<string,mixed>>,version:int} $visualPlan
     */
    public function replacePlaceholders(string $html, array $visualPlan, ?Content $content = null): string
    {
        if (trim($html) === '' || $visualPlan['assets'] === []) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return $html;
        }

        $assetMap = collect($visualPlan['assets'])->keyBy('asset_key');
        $images = $this->imagesByAssetKey($content);

        foreach ($document->getElementsByTagName('figure') as $figure) {
            if (! $figure instanceof DOMElement || ! $figure->hasAttribute('data-asset-key')) {
                continue;
            }

            $assetKey = (string) $figure->getAttribute('data-asset-key');
            $asset = $assetMap->get($assetKey);
            if (! is_array($asset)) {
                continue;
            }

            $replacement = $this->createFigure($document, $asset, $images[$assetKey] ?? null);
            $figure->parentNode?->replaceChild($replacement, $figure);
        }

        return trim($this->innerHtml($body));
    }

    /**
     * @return array<string,ContentImage>
     */
    private function imagesByAssetKey(?Content $content): array
    {
        if (! $content) {
            return [];
        }

        $images = $content->relationLoaded('images') ? $content->images : $content->images()->get();

        return $images
            ->filter(fn (ContentImage $image) => (string) $image->status === 'ready')
            ->filter(fn (ContentImage $image) => trim((string) data_get($image->metadata, 'asset_key')) !== '')
            ->sortByDesc('created_at')
            ->unique(fn (ContentImage $image) => (string) data_get($image->metadata, 'asset_key'))
            ->mapWithKeys(fn (ContentImage $image) => [(string) data_get($image->metadata, 'asset_key') => $image])
            ->all();
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function createFigure(DOMDocument $document, array $asset, ?ContentImage $image): DOMElement
    {
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'argusly-visual');
        $figure->setAttribute('data-asset-key', (string) $asset['asset_key']);
        $figure->setAttribute('data-visual-type', (string) $asset['type']);

        if ($image) {
            $img = $document->createElement('img');
            $img->setAttribute('src', $image->original_ui_url);
            $img->setAttribute('alt', (string) ($asset['alt_text'] ?? $image->alt_text ?? ''));
            $img->setAttribute('loading', 'lazy');
            $figure->appendChild($img);
        } else {
            $html = $this->renderStructuredVisual($asset);
            if ($html !== '') {
                $fragment = $document->createDocumentFragment();
                $fragment->appendXML($html);
                $figure->appendChild($fragment);
            } else {
                $placeholder = $document->createElement('div');
                $placeholder->appendChild($document->createTextNode($this->fallbackLabel($asset)));
                $placeholder->setAttribute('class', 'argusly-visual-placeholder');
                $figure->appendChild($placeholder);
            }
        }

        $caption = trim((string) ($asset['caption'] ?? ''));
        if ($caption !== '') {
            $figcaption = $document->createElement('figcaption');
            $figcaption->appendChild($document->createTextNode($caption));
            $figure->appendChild($figcaption);
        }

        return $figure;
    }

    /**
     * @param array<string,mixed> $asset
     */
    public function renderStructuredVisual(array $asset): string
    {
        $type = (string) ($asset['type'] ?? '');
        $data = (array) ($asset['structured_data'] ?? []);
        $rows = (array) data_get($data, 'data', []);

        if ($rows === [] && ! in_array($type, ['stat_card'], true)) {
            return '';
        }

        return match ($type) {
            'bar_chart', 'chart' => $this->renderBarChart((string) data_get($data, 'title', ''), $rows),
            'stat_card' => $this->renderStatCard((string) data_get($data, 'title', ''), $rows),
            'comparison_visual' => $this->renderComparison($rows),
            'simple_process_diagram', 'diagram' => $this->renderProcess($rows),
            default => '',
        };
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function renderBarChart(string $title, array $rows): string
    {
        $items = collect($rows)
            ->filter(fn (mixed $row) => is_array($row) && is_numeric(Arr::get($row, 'value')))
            ->take(6)
            ->values();
        if ($items->isEmpty()) {
            return '';
        }

        $max = max(1, (float) $items->max(fn (array $row) => (float) $row['value']));
        $bars = $items->map(function (array $row) use ($max): string {
            $label = e((string) $row['label']);
            $value = (float) $row['value'];
            $width = max(4, min(100, (int) round(($value / $max) * 100)));

            return '<div class="argusly-bar-row"><span>' . $label . '</span><div class="argusly-bar-track"><i style="width:' . $width . '%"></i></div><b>' . e((string) round($value, 1)) . '</b></div>';
        })->implode('');

        return '<div class="argusly-chart">' . ($title !== '' ? '<strong>' . e($title) . '</strong>' : '') . $bars . '</div>';
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function renderStatCard(string $title, array $rows): string
    {
        $first = is_array($rows[0] ?? null) ? $rows[0] : [];
        $value = data_get($first, 'value', data_get($first, 'label', ''));
        $label = data_get($first, 'text', data_get($first, 'label', $title));

        return '<div class="argusly-stat-card"><strong>' . e((string) $value) . '</strong><span>' . e((string) $label) . '</span></div>';
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function renderComparison(array $rows): string
    {
        $items = collect($rows)->filter(fn (mixed $row) => is_array($row))->take(6);
        if ($items->isEmpty()) {
            return '';
        }

        $body = $items->map(fn (array $row) => '<tr><th>' . e((string) data_get($row, 'label')) . '</th><td>' . e((string) data_get($row, 'text', data_get($row, 'value', ''))) . '</td></tr>')->implode('');

        return '<table class="argusly-comparison"><tbody>' . $body . '</tbody></table>';
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function renderProcess(array $rows): string
    {
        $items = collect($rows)->filter(fn (mixed $row) => is_array($row))->take(6);
        if ($items->isEmpty()) {
            return '';
        }

        return '<ol class="argusly-process">' . $items->map(fn (array $row) => '<li><strong>' . e((string) data_get($row, 'label')) . '</strong><span>' . e((string) data_get($row, 'text', '')) . '</span></li>')->implode('') . '</ol>';
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function fallbackLabel(array $asset): string
    {
        return trim((string) ($asset['caption'] ?? 'Visual asset pending'));
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $rendered = $element->ownerDocument?->saveHTML($child);
            if ($rendered !== false) {
                $html .= $rendered;
            }
        }

        return $html;
    }
}
