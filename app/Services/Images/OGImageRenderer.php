<?php

namespace App\Services\Images;

use App\Models\Content;
use App\Models\ContentImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OGImageRenderer
{
    public function __construct(private readonly OgImageTextComposer $textComposer)
    {
    }

    public function render(Content $content, ContentImage $bgImage): RenderedImageResult
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is required for OG rendering.');
        }

        if (blank($bgImage->image_path)) {
            throw new RuntimeException('Background image path is missing.');
        }

        $template = $this->templateConfig();
        $canvasWidth = (int) $template['canvas']['width'];
        $canvasHeight = (int) $template['canvas']['height'];
        $safePadding = (int) $template['canvas']['padding'];

        $disk = (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'public'));
        $bgPath = (string) $bgImage->image_path;
        $bgBinary = Storage::disk($disk)->get($bgPath);
        $source = @imagecreatefromstring($bgBinary);

        if (! is_resource($source) && ! $source instanceof \GdImage) {
            throw new RuntimeException('Unable to decode background image for OG render.');
        }

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        if (! $canvas) {
            throw new RuntimeException('Unable to create OG canvas.');
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $this->drawCoveredBackground($canvas, $source, $canvasWidth, $canvasHeight);
        imagedestroy($source);

        $this->drawAdaptiveOverlay($canvas, $canvasWidth, $canvasHeight, $template['overlay']);

        $text = $this->textComposer->compose(
            (string) $content->title,
            (string) ($content->primary_keyword ?? ''),
            (bool) $template['text']['omit_keyword_if_in_title']
        );

        $fontPath = $this->resolveFontPath((array) $template['text']['font_paths']);
        if ($fontPath !== null) {
            $this->drawTextWithTtf(
                canvas: $canvas,
                title: $text['title'],
                keyword: $text['keyword'],
                fontPath: $fontPath,
                safePadding: $safePadding,
                canvasWidth: $canvasWidth,
                template: $template
            );
        } else {
            $this->drawTextFallback(
                canvas: $canvas,
                title: $text['title'],
                keyword: $text['keyword'],
                safePadding: $safePadding
            );
        }

        $filename = sprintf(
            'content-images/%s/%s-og-%s.png',
            (string) $content->id,
            now()->format('YmdHis'),
            Str::random(8)
        );

        ob_start();
        imagepng($canvas, null, 8);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        Storage::disk($disk)->put($filename, $binary);

        return new RenderedImageResult(
            path: $filename,
            url: Storage::disk($disk)->url($filename)
        );
    }

    /**
     * @return array{
     *   canvas:array{width:int,height:int,padding:int,max_text_width:int,keyword_title_gap:int},
     *   text:array{
     *     font_paths:array<int,string>,
     *     keyword_size:int,
     *     title_size_min:int,
     *     title_size_max:int,
     *     line_height:float,
     *     keyword_max_chars:int,
     *     title_max_chars:int,
     *     omit_keyword_if_in_title:bool,
     *     shadow:bool,
     *     shadow_offset:int
     *   },
     *   overlay:array{opacity_min:float,opacity_max:float}
     * }
     */
    private function templateConfig(): array
    {
        $cfg = (array) config('og_image', []);

        $fontPaths = collect((array) ($cfg['font_paths'] ?? []))
            ->filter(fn ($path): bool => is_string($path) && trim($path) !== '')
            ->map(fn (string $path): string => trim($path))
            ->values()
            ->all();

        return [
            'canvas' => [
                'width' => 1200,
                'height' => 630,
                'padding' => max(72, (int) ($cfg['padding'] ?? 72)),
                'max_text_width' => max(760, (int) ($cfg['max_text_width'] ?? 980)),
                'keyword_title_gap' => max(16, (int) ($cfg['keyword_title_gap'] ?? 28)),
            ],
            'text' => [
                'font_paths' => $fontPaths,
                'keyword_size' => max(24, min(56, (int) ($cfg['keyword_font_size'] ?? 40))),
                'title_size_min' => max(40, min(96, (int) ($cfg['title_font_size_min'] ?? 64))),
                'title_size_max' => max(40, min(108, (int) ($cfg['title_font_size_max'] ?? 78))),
                'line_height' => max(1.05, min(1.2, (float) ($cfg['title_line_height'] ?? 1.1))),
                'keyword_max_chars' => max(20, (int) ($cfg['keyword_max_chars'] ?? 90)),
                'title_max_chars' => max(80, (int) ($cfg['title_max_chars'] ?? 260)),
                'omit_keyword_if_in_title' => (bool) ($cfg['omit_keyword_if_in_title'] ?? true),
                'shadow' => (bool) ($cfg['text_shadow'] ?? true),
                'shadow_offset' => max(1, min(4, (int) ($cfg['shadow_offset'] ?? 2))),
            ],
            'overlay' => [
                'opacity_min' => max(0.2, min(0.8, (float) ($cfg['overlay_opacity_min'] ?? 0.35))),
                'opacity_max' => max(0.2, min(0.8, (float) ($cfg['overlay_opacity_max'] ?? 0.55))),
            ],
        ];
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function resolveFontPath(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  \GdImage|resource  $target
     * @param  \GdImage|resource  $source
     */
    private function drawCoveredBackground($target, $source, int $targetWidth, int $targetHeight): void
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $sourceRatio = $sourceWidth / max(1, $sourceHeight);
        $targetRatio = $targetWidth / max(1, $targetHeight);

        if ($sourceRatio > $targetRatio) {
            $copyHeight = $sourceHeight;
            $copyWidth = (int) round($sourceHeight * $targetRatio);
            $srcX = (int) round(($sourceWidth - $copyWidth) / 2);
            $srcY = 0;
        } else {
            $copyWidth = $sourceWidth;
            $copyHeight = (int) round($sourceWidth / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($sourceHeight - $copyHeight) / 2);
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            max(0, $srcX),
            max(0, $srcY),
            $targetWidth,
            $targetHeight,
            max(1, $copyWidth),
            max(1, $copyHeight)
        );
    }

    /**
     * @param  \GdImage|resource  $canvas
     * @param  array{opacity_min:float,opacity_max:float}  $overlay
     */
    private function drawAdaptiveOverlay($canvas, int $canvasWidth, int $canvasHeight, array $overlay): void
    {
        $brightness = $this->estimateBrightness($canvas, $canvasWidth, $canvasHeight);
        $min = min($overlay['opacity_min'], $overlay['opacity_max']);
        $max = max($overlay['opacity_min'], $overlay['opacity_max']);
        $opacity = $min + (($brightness / 255) * ($max - $min));
        $alpha = (int) round((1 - $opacity) * 127);

        $color = imagecolorallocatealpha($canvas, 0, 0, 0, max(0, min(127, $alpha)));
        imagefilledrectangle($canvas, 0, 0, $canvasWidth, $canvasHeight, $color);
    }

    /**
     * @param  \GdImage|resource  $canvas
     */
    private function estimateBrightness($canvas, int $canvasWidth, int $canvasHeight): float
    {
        $stepX = max(10, (int) floor($canvasWidth / 24));
        $stepY = max(10, (int) floor($canvasHeight / 24));
        $sum = 0.0;
        $count = 0;

        for ($y = 0; $y < $canvasHeight; $y += $stepY) {
            for ($x = 0; $x < $canvasWidth; $x += $stepX) {
                $rgb = imagecolorat($canvas, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $sum += (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                $count++;
            }
        }

        if ($count === 0) {
            return 128.0;
        }

        return $sum / $count;
    }

    /**
     * @param  \GdImage|resource  $canvas
     * @param  array{
     *   canvas:array{width:int,height:int,padding:int,max_text_width:int,keyword_title_gap:int},
     *   text:array{
     *     font_paths:array<int,string>,
     *     keyword_size:int,
     *     title_size_min:int,
     *     title_size_max:int,
     *     line_height:float,
     *     keyword_max_chars:int,
     *     title_max_chars:int,
     *     omit_keyword_if_in_title:bool,
     *     shadow:bool,
     *     shadow_offset:int
     *   },
     *   overlay:array{opacity_min:float,opacity_max:float}
     * }  $template
     */
    private function drawTextWithTtf(
        $canvas,
        string $title,
        string $keyword,
        string $fontPath,
        int $safePadding,
        int $canvasWidth,
        array $template
    ): void {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $keywordColor = imagecolorallocate($canvas, 240, 245, 255);
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 72);

        $maxTextWidth = min($template['canvas']['max_text_width'], $canvasWidth - ($safePadding * 2));
        $title = Str::limit($title, $template['text']['title_max_chars'], '…');

        $keywordLine = trim($keyword) !== ''
            ? Str::limit($keyword, $template['text']['keyword_max_chars'], '…')
            : '';

        $keywordSize = (int) $template['text']['keyword_size'];
        $titleSizeMin = (int) $template['text']['title_size_min'];
        $titleSizeMax = max($titleSizeMin, (int) $template['text']['title_size_max']);

        $titleSize = $this->resolveTitleFontSize($title, $titleSizeMin, $titleSizeMax);
        $titleLines = $this->wrapTtfLines($title, $fontPath, $titleSize, $maxTextWidth);
        while (count($titleLines) > 4 && $titleSize > $titleSizeMin) {
            $titleSize -= 2;
            $titleLines = $this->wrapTtfLines($title, $fontPath, $titleSize, $maxTextWidth);
        }

        $titleLines = $this->clampTtfLines($titleLines, $fontPath, $titleSize, $maxTextWidth, 4);
        $lineHeight = (int) ceil($titleSize * (float) $template['text']['line_height']);

        $currentY = $safePadding + $titleSize;
        if ($keywordLine !== '') {
            $currentY = $safePadding + $keywordSize;
            $this->drawTextLine(
                canvas: $canvas,
                fontPath: $fontPath,
                text: $keywordLine,
                size: $keywordSize,
                x: $safePadding,
                y: $currentY,
                textColor: $keywordColor,
                shadowColor: $shadow,
                withShadow: (bool) $template['text']['shadow'],
                shadowOffset: (int) $template['text']['shadow_offset']
            );

            $currentY += (int) $template['canvas']['keyword_title_gap'] + $titleSize;
        }

        foreach ($titleLines as $line) {
            $this->drawTextLine(
                canvas: $canvas,
                fontPath: $fontPath,
                text: $line,
                size: $titleSize,
                x: $safePadding,
                y: $currentY,
                textColor: $white,
                shadowColor: $shadow,
                withShadow: (bool) $template['text']['shadow'],
                shadowOffset: (int) $template['text']['shadow_offset']
            );

            $currentY += $lineHeight;
        }
    }

    /**
     * @param  \GdImage|resource  $canvas
     */
    private function drawTextLine(
        $canvas,
        string $fontPath,
        string $text,
        int $size,
        int $x,
        int $y,
        int $textColor,
        int $shadowColor,
        bool $withShadow,
        int $shadowOffset
    ): void {
        if ($withShadow) {
            imagettftext($canvas, $size, 0, $x + $shadowOffset, $y + $shadowOffset, $shadowColor, $fontPath, $text);
        }

        imagettftext($canvas, $size, 0, $x, $y, $textColor, $fontPath, $text);
    }

    /**
     * @param  \GdImage|resource  $canvas
     */
    private function drawTextFallback($canvas, string $title, string $keyword, int $safePadding): void
    {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 232, 238, 248);

        $y = $safePadding;
        if (trim($keyword) !== '') {
            imagestring($canvas, 4, $safePadding, $y, Str::limit($keyword, 42, '...'), $muted);
            $y += 28;
        }

        $lines = $this->wrapFallbackLines(Str::limit($title, 200, '...'), 48);
        $lines = array_slice($lines, 0, 4);

        foreach ($lines as $line) {
            imagestring($canvas, 5, $safePadding, $y, $line, $white);
            $y += 24;
        }
    }

    /**
     * @return array<int,string>
     */
    private function wrapFallbackLines(string $text, int $maxChars): array
    {
        $wrapped = wordwrap($text, $maxChars, "\n", true);

        return array_values(array_filter(explode("\n", $wrapped), fn ($line): bool => trim($line) !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function wrapTtfLines(string $text, string $fontPath, int $fontSize, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === []) {
            return [];
        }

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current === '' ? $word : $current.' '.$word);
            if ($this->ttfTextWidth($candidate, $fontPath, $fontSize) <= $maxWidth || $current === '') {
                $current = $candidate;

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @param  array<int,string>  $lines
     * @return array<int,string>
     */
    private function clampTtfLines(array $lines, string $fontPath, int $fontSize, int $maxWidth, int $maxLines): array
    {
        if (count($lines) <= $maxLines) {
            return $lines;
        }

        $clamped = array_slice($lines, 0, $maxLines);
        $lastIndex = count($clamped) - 1;
        $clamped[$lastIndex] = $this->truncateLineToWidth($clamped[$lastIndex], $fontPath, $fontSize, $maxWidth, '…');

        return $clamped;
    }

    private function truncateLineToWidth(string $line, string $fontPath, int $fontSize, int $maxWidth, string $suffix): string
    {
        $trimmed = rtrim($line);
        if ($trimmed === '') {
            return $suffix;
        }

        $candidate = $trimmed.$suffix;
        while (mb_strlen($candidate) > 1 && $this->ttfTextWidth($candidate, $fontPath, $fontSize) > $maxWidth) {
            $trimmed = rtrim(mb_substr($trimmed, 0, -1));
            $candidate = $trimmed.$suffix;
        }

        return $candidate;
    }

    private function ttfTextWidth(string $text, string $fontPath, int $fontSize): int
    {
        $box = imagettfbbox($fontSize, 0, $fontPath, $text);

        if (! is_array($box)) {
            return 0;
        }

        return (int) abs($box[2] - $box[0]);
    }

    private function resolveTitleFontSize(string $title, int $minSize, int $maxSize): int
    {
        $length = mb_strlen($title);

        if ($length >= 220) {
            return $minSize;
        }

        if ($length >= 180) {
            return max($minSize, $maxSize - 10);
        }

        if ($length >= 140) {
            return max($minSize, $maxSize - 8);
        }

        if ($length >= 110) {
            return max($minSize, $maxSize - 6);
        }

        if ($length >= 80) {
            return max($minSize, $maxSize - 4);
        }

        return $maxSize;
    }
}
