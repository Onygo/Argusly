<?php

namespace App\Services\StockImages;

use App\Models\Content;
use App\Models\ContentImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UnsplashImageService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $page = 1, int $perPage = 12): array
    {
        $this->assertConfigured();

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders($this->headers())
            ->get($this->baseUrl().'/search/photos', [
                'query' => $query,
                'page' => max(1, $page),
                'per_page' => max(1, min(30, $perPage)),
                'orientation' => 'landscape',
                'content_filter' => 'high',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unsplash search failed: HTTP '.$response->status());
        }

        return collect((array) data_get($response->json(), 'results', []))
            ->map(fn ($photo): array => $this->normalizePhoto((array) $photo))
            ->filter(fn (array $photo): bool => $photo['id'] !== '' && $photo['image_url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function usePhoto(Content $content, array $payload, string $createdBy): ContentImage
    {
        $this->assertConfigured();

        $photo = $this->normalizePhoto($payload);
        if ($photo['id'] === '' || $photo['image_url'] === '') {
            throw new RuntimeException('Unsplash photo payload is incomplete.');
        }
        $this->assertTrustedPhotoUrls($photo);

        $this->trackDownload((string) $photo['download_location']);

        return DB::transaction(function () use ($content, $photo, $createdBy): ContentImage {
            ContentImage::query()
                ->where('content_id', (string) $content->id)
                ->where('type', 'featured')
                ->update(['is_active' => false]);

            return ContentImage::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => (string) $content->id,
                'type' => 'featured',
                'prompt' => (string) $photo['query'],
                'provider' => 'unsplash',
                'model' => 'unsplash-api-v1',
                'image_url' => (string) $photo['image_url'],
                'alt_text' => (string) $photo['alt_text'],
                'credit_cost' => 0,
                'status' => 'ready',
                'is_active' => true,
                'width' => (int) $photo['width'] ?: null,
                'height' => (int) $photo['height'] ?: null,
                'metadata' => [
                    'source' => 'unsplash',
                    'license' => 'Unsplash License',
                    'photo_id' => (string) $photo['id'],
                    'photo_url' => (string) $photo['photo_url'],
                    'download_location' => (string) $photo['download_location'],
                    'attribution' => [
                        'text' => (string) $photo['attribution_text'],
                        'photographer_name' => (string) $photo['photographer_name'],
                        'photographer_url' => (string) $photo['photographer_url'],
                        'provider_name' => 'Unsplash',
                        'provider_url' => 'https://unsplash.com',
                    ],
                ],
                'created_by' => $createdBy,
            ]);
        });
    }

    public function isConfigured(): bool
    {
        return trim((string) config('publishlayer.stock_images.unsplash.access_key', '')) !== '';
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Client-ID '.trim((string) config('publishlayer.stock_images.unsplash.access_key', '')),
            'Accept-Version' => 'v1',
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('publishlayer.stock_images.unsplash.base_url', 'https://api.unsplash.com'), '/');
    }

    private function timeout(): int
    {
        return max(5, (int) config('publishlayer.stock_images.unsplash.timeout_seconds', 12));
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('UNSPLASH_ACCESS_KEY is not configured.');
        }
    }

    private function trackDownload(string $downloadLocation): void
    {
        if (trim($downloadLocation) === '') {
            return;
        }
        if (! $this->isAllowedHost($downloadLocation, [$this->apiHost()])) {
            throw new RuntimeException('Unsplash download tracking URL is not trusted.');
        }

        Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders($this->headers())
            ->get($downloadLocation);
    }

    /**
     * @param array<string,mixed> $photo
     * @return array<string,mixed>
     */
    private function normalizePhoto(array $photo): array
    {
        $photographer = trim((string) data_get($photo, 'user.name'));
        $photographerUrl = trim((string) data_get($photo, 'user.links.html'));

        return [
            'id' => trim((string) data_get($photo, 'id')),
            'query' => trim((string) data_get($photo, 'query')),
            'image_url' => trim((string) (data_get($photo, 'urls.regular') ?: data_get($photo, 'image_url'))),
            'thumb_url' => trim((string) (data_get($photo, 'urls.small') ?: data_get($photo, 'thumb_url'))),
            'photo_url' => trim((string) (data_get($photo, 'links.html') ?: data_get($photo, 'photo_url'))),
            'download_location' => trim((string) (data_get($photo, 'links.download_location') ?: data_get($photo, 'download_location'))),
            'photographer_name' => $photographer,
            'photographer_url' => $photographerUrl,
            'alt_text' => trim((string) (data_get($photo, 'alt_description') ?: data_get($photo, 'description') ?: data_get($photo, 'alt_text'))),
            'width' => (int) data_get($photo, 'width', 0),
            'height' => (int) data_get($photo, 'height', 0),
            'attribution_text' => trim('Photo by '.($photographer !== '' ? $photographer : 'Unsplash creator').' on Unsplash'),
        ];
    }

    /**
     * @param array<string,mixed> $photo
     */
    private function assertTrustedPhotoUrls(array $photo): void
    {
        if (! $this->isAllowedHost((string) $photo['image_url'], ['images.unsplash.com'])) {
            throw new RuntimeException('Unsplash image URL is not trusted.');
        }

        if (! $this->isAllowedHost((string) $photo['photo_url'], ['unsplash.com', 'www.unsplash.com'])) {
            throw new RuntimeException('Unsplash photo URL is not trusted.');
        }

        if (! $this->isAllowedHost((string) $photo['photographer_url'], ['unsplash.com', 'www.unsplash.com'])) {
            throw new RuntimeException('Unsplash photographer URL is not trusted.');
        }
    }

    /**
     * @param array<int,string> $allowedHosts
     */
    private function isAllowedHost(string $url, array $allowedHosts): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, $allowedHosts, true);
    }

    private function apiHost(): string
    {
        return strtolower((string) parse_url($this->baseUrl(), PHP_URL_HOST)) ?: 'api.unsplash.com';
    }
}
