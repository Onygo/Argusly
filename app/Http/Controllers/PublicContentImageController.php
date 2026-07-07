<?php

namespace App\Http\Controllers;

use App\Models\ContentImage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicContentImageController extends Controller
{
    public function __invoke(string $path): Response
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        $relativePath = ContentImage::storagePath($path);
        foreach ($this->imageDisks() as $diskName) {
            $disk = Storage::disk($diskName);

            if (! $disk->exists($relativePath)) {
                continue;
            }

            return response((string) $disk->get($relativePath), 200, [
                'Content-Type' => $disk->mimeType($relativePath) ?: $this->mimeTypeFromPath($relativePath),
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        abort(404);
    }

    /**
     * @return array<int,string>
     */
    private function imageDisks(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images')),
            'public',
        ])));
    }

    private function mimeTypeFromPath(string $path): string
    {
        return match (Str::lower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
