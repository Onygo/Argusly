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
        $disk = Storage::disk('public');

        if (! $disk->exists($relativePath)) {
            abort(404);
        }

        return response((string) $disk->get($relativePath), 200, [
            'Content-Type' => $disk->mimeType($relativePath) ?: $this->mimeTypeFromPath($relativePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
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
