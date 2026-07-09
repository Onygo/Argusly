<?php

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Images\OGImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders og image file with expected 1200x630 resolution', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');
    config(['argusly.images.disk' => 'content_images']);

    $organization = Organization::query()->create([
        'name' => 'OG Org',
        'slug' => 'og-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'OG Workspace',
        'organization_id' => $organization->id,
        'visual_settings' => [
            'og_theme' => 'dark',
            'og_accent_hex' => '#dcf365',
            'og_bg_overlay' => 'gradient',
            'og_font' => 'inter',
        ],
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Argusly OG Rendering Test Title',
        'primary_keyword' => 'ai content governance',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $bgPath = 'content-images/test-bg.png';
    Storage::disk('content_images')->put($bgPath, makeSolidPng(1600, 1000, [34, 56, 78]));

    $bgImage = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_path' => $bgPath,
        'image_url' => Storage::disk('content_images')->url($bgPath),
        'credit_cost' => 5,
        'provider' => 'openai',
    ]);

    $result = app(OGImageRenderer::class)->render($content, $bgImage);

    Storage::disk('content_images')->assertExists($result->path);
    $binary = Storage::disk('content_images')->get($result->path);
    $dimensions = getimagesizefromstring($binary);

    expect($dimensions)->not->toBeFalse()
        ->and((int) $dimensions[0])->toBe(1200)
        ->and((int) $dimensions[1])->toBe(630);

    $rendered = imagecreatefromstring($binary);
    expect($rendered)->not->toBeFalse()
        ->and(ogImagePixelRgb($rendered, 20, 20))->toBe([34, 56, 78])
        ->and(ogImageChangedPixelCount($rendered, [34, 56, 78], 1000, 540, 1168, 598))->toBeGreaterThan(0);

    imagedestroy($rendered);
});

it('renders long titles without breaking og canvas dimensions', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');
    config(['argusly.images.disk' => 'content_images']);

    $organization = Organization::query()->create([
        'name' => 'OG Org Long',
        'slug' => 'og-org-long-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'OG Workspace Long',
        'organization_id' => $organization->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Authority Engineering in de AI Search Era with practical implementation details for B2B organizations that need governance, operations, and measurable outcomes across content workflows',
        'primary_keyword' => 'Authority Engineering',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $bgPath = 'content-images/test-bg-long.png';
    Storage::disk('content_images')->put($bgPath, makeSolidPng(1800, 1200, [140, 180, 215]));

    $bgImage = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_path' => $bgPath,
        'image_url' => Storage::disk('content_images')->url($bgPath),
        'credit_cost' => 5,
        'provider' => 'openai',
    ]);

    $result = app(OGImageRenderer::class)->render($content, $bgImage);
    $dimensions = getimagesizefromstring(Storage::disk('content_images')->get($result->path));

    expect($dimensions)->not->toBeFalse()
        ->and((int) $dimensions[0])->toBe(1200)
        ->and((int) $dimensions[1])->toBe(630);
});

function makeSolidPng(int $width, int $height, array $rgb): string
{
    $img = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    imagefilledrectangle($img, 0, 0, $width, $height, $color);

    ob_start();
    imagepng($img);
    $binary = (string) ob_get_clean();
    imagedestroy($img);

    return $binary;
}

function ogImagePixelRgb($image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);

    return [
        ($rgb >> 16) & 0xFF,
        ($rgb >> 8) & 0xFF,
        $rgb & 0xFF,
    ];
}

function ogImageChangedPixelCount($image, array $backgroundRgb, int $x1, int $y1, int $x2, int $y2): int
{
    $changed = 0;

    for ($y = $y1; $y <= $y2; $y += 4) {
        for ($x = $x1; $x <= $x2; $x += 4) {
            if (ogImagePixelRgb($image, $x, $y) !== $backgroundRgb) {
                $changed++;
            }
        }
    }

    return $changed;
}
