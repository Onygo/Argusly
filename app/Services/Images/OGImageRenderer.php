<?php

namespace App\Services\Images;

use App\Models\Content;
use App\Models\ContentImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OGImageRenderer
{
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

        $disk = (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images'));
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

        $this->drawLogo($canvas, $canvasWidth, $canvasHeight, $template['logo']);

        $filename = sprintf(
            '%s/%s-og-%s.png',
            ContentImage::storagePath((string) $content->id),
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
            url: ContentImage::publicUrlForStorageValue((string) Storage::disk($disk)->url($filename))
        );
    }

    /**
     * @return array{
     *   canvas:array{width:int,height:int},
     *   logo:array{path:string,max_width:int,margin:int}
     * }
     */
    private function templateConfig(): array
    {
        $cfg = (array) config('og_image', []);

        return [
            'canvas' => [
                'width' => 1200,
                'height' => 630,
            ],
            'logo' => [
                'path' => (string) ($cfg['logo_path'] ?? public_path('images/argusly-logo-standalone.png')),
                'max_width' => max(72, min(320, (int) ($cfg['logo_max_width'] ?? 150))),
                'margin' => max(16, min(96, (int) ($cfg['logo_margin'] ?? 32))),
            ],
        ];
    }

    /**
     * @param  \GdImage|resource  $canvas
     * @param  array{path:string,max_width:int,margin:int}  $logo
     */
    private function drawLogo($canvas, int $canvasWidth, int $canvasHeight, array $logo): void
    {
        $logoPath = trim((string) $logo['path']);
        if ($logoPath === '' || ! is_file($logoPath)) {
            throw new RuntimeException('Argusly OG logo asset is missing.');
        }

        $source = @imagecreatefrompng($logoPath);
        if (! is_resource($source) && ! $source instanceof \GdImage) {
            throw new RuntimeException('Unable to decode Argusly OG logo asset.');
        }

        imagealphablending($source, true);
        imagesavealpha($source, true);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = min((int) $logo['max_width'], $sourceWidth);
        $targetHeight = (int) round($targetWidth * ($sourceHeight / max(1, $sourceWidth)));
        $margin = (int) $logo['margin'];
        $targetX = $canvasWidth - $targetWidth - $margin;
        $targetY = $canvasHeight - $targetHeight - $margin;

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        imagedestroy($source);
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
}
