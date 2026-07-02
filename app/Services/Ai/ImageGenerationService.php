<?php

namespace App\Services\Ai;

use App\Jobs\GenerateContentFeaturedImageJob;
use App\Jobs\GenerateContentOgImageJob;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\CreditAction;
use App\Services\CreditWalletService;
use App\Services\GenerationFinalizer;
use App\Services\ImagePresetService;
use App\Services\Llm\LlmRequestLoggingService;
use App\Services\Llm\LlmRoutingService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageGenerationService
{
    private const MEDIUM_MAX_WIDTH = 1280;

    private const THUMBNAIL_MAX_WIDTH = 420;

    private const THUMBNAIL_WEBP_QUALITY = 72;

    private const MEDIUM_WEBP_QUALITY = 78;

    private const ORIGINAL_WEBP_QUALITY = 82;

    private const ORIGINAL_WEBP_MAX_PIXELS = 20000000;

    public function __construct(
        private readonly LlmRoutingService $routing,
        private readonly LlmRequestLoggingService $llmLogging,
        private readonly ImagePresetService $presetService,
    ) {
    }

    public const FEATURED_TYPE = 'featured';

    public const OG_TYPE = 'og';

    public const INLINE_TYPE = 'inline';

    public function buildPrompt(Content $content, ?string $presetId = null): string
    {
        $content->loadMissing(['workspace.companyProfile', 'brandVoice', 'drafts']);

        $title = trim((string) $content->title);
        $keyword = trim((string) $content->primary_keyword);
        $subKeywords = collect($this->extractSubKeywords($content))->filter()->join(', ');
        $tone = trim((string) ($content->brandVoice?->tone_of_voice ?: $content->brandVoice?->default_tone ?: 'professional'));
        $industry = trim((string) ($content->workspace?->companyProfile?->industry ?: 'B2B software'));
        $audience = trim((string) ($content->workspace?->companyProfile?->target_audience ?: 'business professionals'));
        $customInstructions = trim((string) ($content->image_prompt_instructions ?? ''));

        // Resolve preset for organization-specific style instructions
        $styleInstructions = $this->resolveStyleInstructions($content, $presetId);

        return trim(implode(' ', array_filter([
            'Create a professional, modern, clean blog hero image.',
            $title !== '' ? "Topic: {$title}." : null,
            $keyword !== '' ? "Main keyword: {$keyword}." : null,
            $subKeywords !== '' ? "Optional supporting keywords: {$subKeywords}." : null,
            "Industry context: {$industry}.",
            "Audience: {$audience}.",
            "Tone: {$tone}.",
            $styleInstructions !== '' ? "Visual style direction: {$styleInstructions}." : null,
            'No text overlay, no logos, no watermarks, no UI mockups.',
            'High quality editorial visual composition with strong subject focus.',
            $customInstructions !== '' ? "Custom image direction (highest priority): {$customInstructions}." : null,
        ])));
    }

    /**
     * Resolve style instructions from organization preset or system defaults.
     */
    private function resolveStyleInstructions(Content $content, ?string $presetId = null): string
    {
        $organizationId = (int) ($content->workspace?->organization_id ?? 0);
        if ($organizationId < 1) {
            return $this->presetService->getSystemDefaultInstructions();
        }

        $preset = $this->presetService->resolvePresetForGeneration($organizationId, $presetId);

        return $this->presetService->buildStyleInstructions($preset);
    }

    public function generateFeaturedImage(Content $content): ContentImage
    {
        $this->assertImagesEnabled();

        $prompt = $this->buildPrompt($content);

        $image = ContentImage::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $content->workspace_id,
            'content_id' => (string) $content->id,
            'type' => self::FEATURED_TYPE,
            'source' => ContentImage::SOURCE_GENERATED,
            'prompt' => $prompt,
            'provider' => $this->resolveImageRoute($content)['provider'],
            'credit_cost' => $this->resolveCreditCost(),
            'status' => 'queued',
            'is_active' => false,
            'display_on_website' => true,
            'display_as_featured_image' => true,
            'use_as_social_image' => true,
            'created_by' => $content->updated_by,
        ]);

        Artisan::call('optimize:clear');

        GenerateContentFeaturedImageJob::dispatch((string) $image->id)->onQueue('generation');

        return $image;
    }

    public function generateOgImage(Content $content): ContentImage
    {
        $this->assertImagesEnabled();

        $image = ContentImage::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $content->workspace_id,
            'content_id' => (string) $content->id,
            'type' => self::OG_TYPE,
            'source' => ContentImage::SOURCE_GENERATED,
            'prompt' => $this->buildOgPrompt($content),
            'provider' => 'pl-renderer',
            'credit_cost' => 0,
            'status' => 'queued',
            'is_active' => false,
            'use_as_meta_image' => true,
            'use_as_social_image' => true,
            'use_for_linkedin' => true,
            'created_by' => $content->updated_by,
        ]);

        Artisan::call('optimize:clear');

        GenerateContentOgImageJob::dispatch((string) $image->id)->onQueue('generation');

        return $image;
    }

    /**
     * @param array<string,mixed> $asset
     */
    public function generateInlineVisualImage(Content $content, array $asset): ContentImage
    {
        $this->assertImagesEnabled();

        $assetKey = trim((string) ($asset['asset_key'] ?? ''));
        if ($assetKey === '') {
            throw new RuntimeException('Inline visual asset_key is required.');
        }

        $prompt = trim((string) ($asset['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = trim(implode(' ', array_filter([
                'Create a professional inline editorial visual for this article.',
                trim((string) $content->title) !== '' ? 'Article: ' . trim((string) $content->title) . '.' : null,
                trim((string) ($asset['caption'] ?? '')) !== '' ? 'Caption intent: ' . trim((string) $asset['caption']) . '.' : null,
                'No text overlay, no logos, no watermarks.',
            ])));
        }

        $image = ContentImage::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $content->workspace_id,
            'content_id' => (string) $content->id,
            'type' => self::INLINE_TYPE,
            'source' => ContentImage::SOURCE_GENERATED,
            'prompt' => $prompt,
            'provider' => $this->resolveImageRoute($content)['provider'],
            'credit_cost' => $this->resolveCreditCost(),
            'status' => 'queued',
            'is_active' => false,
            'display_on_website' => true,
            'alt_text' => trim((string) ($asset['alt_text'] ?? '')),
            'created_by' => $content->updated_by,
            'metadata' => [
                'asset_key' => $assetKey,
                'visual_type' => (string) ($asset['type'] ?? self::INLINE_TYPE),
                'caption' => trim((string) ($asset['caption'] ?? '')),
                'required' => (bool) ($asset['required'] ?? false),
            ],
        ]);

        Artisan::call('optimize:clear');

        GenerateContentFeaturedImageJob::dispatch((string) $image->id)->onQueue('generation');

        return $image;
    }

    public function buildOgPrompt(Content $content): string
    {
        return trim($this->buildPrompt($content).' Background intended for 1200x630 social OG composition. No text in image.');
    }

    /**
     * @return array{provider:string,mime:string,binary:string}
     */
    public function requestImageBinary(string $prompt, ?Content $content = null): array
    {
        $this->assertImagesEnabled();

        $route = $this->resolveImageRoute($content);
        $provider = $route['provider'];

        return match ($provider) {
            'openai' => $this->requestOpenAiImageBinary($prompt, $content, $route),
            'gemini' => $this->requestGeminiImageBinary($prompt, $content, $route),
            default => throw new RuntimeException('Unsupported image provider configured.'),
        };
    }

    /**
     * @param array{provider:string,model:string} $route
     * @return array{provider:string,mime:string,binary:string}
     */
    private function requestOpenAiImageBinary(string $prompt, ?Content $content, array $route): array
    {
        $provider = 'openai';

        $cfg = (array) config('argusly.ai.images.openai', []);
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured for image generation.');
        }

        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $model = (string) ($route['model'] ?: ($cfg['model'] ?? 'gpt-image-1'));
        $size = (string) ($cfg['size'] ?? '1536x1024');
        $quality = (string) ($cfg['quality'] ?? 'medium');
        $timeout = (int) ($cfg['request_timeout_seconds'] ?? 90);

        $payload = $this->buildImageRequestPayload(
            model: $model,
            prompt: $prompt,
            size: $size,
            quality: $quality
        );

        Log::debug('content_image.requesting_provider', [
            'provider' => $provider,
            'model' => $model,
            'size' => $size,
            'quality' => $quality,
            'payload_keys' => array_values(array_keys($payload)),
        ]);

        $started = microtime(true);
        $response = Http::timeout(max(20, $timeout))
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($baseUrl.'/images/generations', $payload);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            $details = $this->extractErrorDetails($response);

            $this->logImageRequest(
                content: $content,
                provider: $provider,
                model: $model,
                prompt: $prompt,
                latencyMs: $latencyMs,
                status: 'error',
                requestId: (string) ($response->header('x-request-id') ?: ''),
                responseRaw: null,
                errorCode: (string) $response->status(),
                errorMessage: 'Image generation failed: HTTP '.$response->status().' '.$details,
            );

            $message = 'Image generation failed: HTTP '.$response->status();
            if ($details !== '') {
                $message .= ' - '.mb_substr($details, 0, 300);
            }

            throw new RuntimeException($message);
        }

        $payload = $response->json();
        $b64 = data_get($payload, 'data.0.b64_json');
        if (! is_string($b64) || trim($b64) === '') {
            throw new RuntimeException('Image generation failed: empty image payload.');
        }

        $binary = base64_decode($b64, true);
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Image generation failed: invalid image payload.');
        }

        $this->logImageRequest(
            content: $content,
            provider: $provider,
            model: $model,
            prompt: $prompt,
            latencyMs: $latencyMs,
            status: 'success',
            requestId: (string) ($response->header('x-request-id') ?: ''),
            responseRaw: (array) $response->json(),
        );

        return [
            'provider' => $provider,
            'mime' => 'image/png',
            'binary' => $binary,
        ];
    }

    /**
     * @param array{provider:string,model:string} $route
     * @return array{provider:string,mime:string,binary:string}
     */
    private function requestGeminiImageBinary(string $prompt, ?Content $content, array $route): array
    {
        $provider = 'gemini';
        $cfg = (array) config('argusly.ai.images.gemini', []);
        $providerCfg = (array) config('llm.providers.gemini', []);
        $apiKey = trim((string) (($cfg['api_key'] ?? '') ?: ($providerCfg['api_key'] ?? '')));
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured for image generation.');
        }

        $baseUrl = rtrim((string) (($cfg['base_url'] ?? '') ?: ($providerCfg['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta')), '/');
        $model = $this->normalizeGeminiImageModel(
            trim((string) ($route['model'] ?: ($cfg['model'] ?? 'gemini-2.5-flash-image')))
        );
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/'.$model;
        $timeout = (int) ($cfg['request_timeout_seconds'] ?? 90);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ];

        $started = microtime(true);
        $response = Http::timeout(max(20, $timeout))
            ->acceptJson()
            ->asJson()
            ->post($baseUrl.'/'.$modelPath.':generateContent?key='.urlencode($apiKey), $payload);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            $details = $this->extractErrorDetails($response);
            $requestId = (string) (data_get($response->json(), 'responseId') ?: '');

            $this->logImageRequest(
                content: $content,
                provider: $provider,
                model: $model,
                prompt: $prompt,
                latencyMs: $latencyMs,
                status: 'error',
                requestId: $requestId,
                responseRaw: null,
                errorCode: (string) $response->status(),
                errorMessage: 'Image generation failed: HTTP '.$response->status().' '.$details,
            );

            $message = 'Image generation failed: HTTP '.$response->status();
            if ($details !== '') {
                $message .= ' - '.mb_substr($details, 0, 300);
            }

            throw new RuntimeException($message);
        }

        $json = (array) $response->json();
        $inline = collect((array) data_get($json, 'candidates', []))
            ->flatMap(fn ($candidate) => (array) data_get($candidate, 'content.parts', []))
            ->first(function ($part): bool {
                return is_string(data_get($part, 'inlineData.data'))
                    || is_string(data_get($part, 'inline_data.data'));
            });

        $b64 = is_array($inline)
            ? (string) (data_get($inline, 'inlineData.data') ?: data_get($inline, 'inline_data.data') ?: '')
            : '';

        if (trim($b64) === '') {
            throw new RuntimeException('Image generation failed: empty image payload.');
        }

        $binary = base64_decode($b64, true);
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Image generation failed: invalid image payload.');
        }

        $mime = is_array($inline)
            ? (string) (data_get($inline, 'inlineData.mimeType') ?: data_get($inline, 'inline_data.mime_type') ?: 'image/png')
            : 'image/png';
        $requestId = (string) (data_get($json, 'responseId') ?: '');

        $this->logImageRequest(
            content: $content,
            provider: $provider,
            model: $model,
            prompt: $prompt,
            latencyMs: $latencyMs,
            status: 'success',
            requestId: $requestId,
            responseRaw: $json,
        );

        return [
            'provider' => $provider,
            'mime' => $mime !== '' ? $mime : 'image/png',
            'binary' => $binary,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function buildImageRequestPayload(string $model, string $prompt, string $size, string $quality): array
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
        ];

        // GPT image models reject response_format for /images/generations.
        if ($this->isDallEModel($model)) {
            $payload['response_format'] = 'b64_json';
        }

        return $payload;
    }

    private function isDallEModel(string $model): bool
    {
        return Str::startsWith(Str::lower(trim($model)), 'dall-e');
    }

    private function normalizeGeminiImageModel(string $model): string
    {
        $normalized = trim($model);
        if ($normalized === '') {
            return 'gemini-2.5-flash-image';
        }

        return match ($normalized) {
            'gemini-2.5-flash-image-preview', 'models/gemini-2.5-flash-image-preview' => 'gemini-2.5-flash-image',
            default => $normalized,
        };
    }

    private function extractErrorDetails(Response $response): string
    {
        $json = $response->json();

        $candidates = [
            data_get($json, 'error.message'),
            data_get($json, 'message'),
            data_get($json, 'error'),
            data_get($json, 'details'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return trim((string) $response->body());
    }

    /**
     * @param array<string,mixed>|null $responseRaw
     */
    private function logImageRequest(
        ?Content $content,
        string $provider,
        string $model,
        string $prompt,
        int $latencyMs,
        string $status,
        string $requestId,
        ?array $responseRaw,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $this->llmLogging->log([
            'workspace_id' => $content?->workspace_id,
            'site_id' => $content?->client_site_id,
            'feature' => 'image_generation',
            'modality' => 'image',
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'error_type' => $status === 'error' ? 'RuntimeException' : null,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'request_id' => $requestId !== '' ? $requestId : null,
            'metadata' => [
                'prompt_chars_total' => mb_strlen($prompt),
                'message_count' => 1,
                'provider_raw' => $this->summarizeImageProviderRaw($responseRaw),
                'trigger' => 'image_generation_service',
            ],
        ]);
    }

    /**
     * @param array<string,mixed>|null $responseRaw
     * @return array<string,mixed>|null
     */
    private function summarizeImageProviderRaw(?array $responseRaw): ?array
    {
        if (! is_array($responseRaw)) {
            return null;
        }

        $summary = [
            'id' => data_get($responseRaw, 'id') ?: data_get($responseRaw, 'responseId'),
            'model' => data_get($responseRaw, 'model') ?: data_get($responseRaw, 'modelVersion'),
            'created' => data_get($responseRaw, 'created'),
            'usage' => data_get($responseRaw, 'usage') ?: data_get($responseRaw, 'usageMetadata'),
        ];

        $imageCount = 0;
        if (is_array(data_get($responseRaw, 'data'))) {
            foreach ((array) data_get($responseRaw, 'data') as $item) {
                if (is_string(data_get($item, 'b64_json')) || is_string(data_get($item, 'url'))) {
                    $imageCount++;
                }
            }
        } elseif (is_array(data_get($responseRaw, 'candidates'))) {
            foreach ((array) data_get($responseRaw, 'candidates') as $candidate) {
                foreach ((array) data_get($candidate, 'content.parts', []) as $part) {
                    if (is_string(data_get($part, 'inlineData.data')) || is_string(data_get($part, 'inline_data.data'))) {
                        $imageCount++;
                    }
                }
            }
        }

        $summary['image_parts_count'] = $imageCount;

        return array_filter($summary, static fn ($value) => $value !== null && $value !== '');
    }

    public function resolveCreditCost(): int
    {
        $action = CreditAction::query()
            ->where('key', 'content.featured_image')
            ->where('is_active', true)
            ->first();

        if ($action) {
            return (int) $action->credits_cost;
        }

        return max(1, (int) config('argusly.ai.images.credit_cost', 6));
    }

    public function processFeaturedImage(ContentImage $image, CreditWalletService $wallets): ContentImage
    {
        $this->assertImagesEnabled();

        $content = $image->content()->with('clientSite.workspace')->first();
        if (! $content || ! $content->client_site_id) {
            throw new RuntimeException('No connected site available for credit wallet debit.');
        }

        $wallets->reserveForContentImage($image);
        $image->refresh();

        try {
            $prompt = trim((string) $image->prompt);
            if ($prompt === '') {
                $prompt = $this->buildPrompt($content);
            }

            $payload = $this->requestImageBinary($prompt, $content);
            $disk = $this->resolveImageStorageDisk();
            $ext = $payload['mime'] === 'image/jpeg' ? 'jpg' : 'png';
            $basePath = sprintf(
                '%s/%s-featured-%s.%s',
                ContentImage::storagePath((string) $content->id),
                now()->format('YmdHis'),
                Str::random(8),
                $ext
            );

            Storage::disk($disk)->put($basePath, $payload['binary']);

            $variants = $this->createDerivativeVariants(
                content: $content,
                imageId: (string) $image->id,
                sourceBinary: (string) $payload['binary'],
                sourceMime: (string) $payload['mime'],
                originalPath: $basePath
            );

            $originalPath = (string) ($variants['original_path'] ?? $basePath);
            $imageUrl = ContentImage::publicUrlForStorageValue((string) Storage::disk($disk)->url($originalPath));

            $cost = max(1, (int) ($image->credit_cost ?: $this->resolveCreditCost()));
            $entry = $wallets->commitUsageForContentImage($image);

            ContentImage::query()
                ->where('content_id', $content->id)
                ->where('type', self::FEATURED_TYPE)
                ->where('id', '!=', $image->id)
                ->update(['is_active' => false]);

            $existingMetadata = is_array($image->metadata) ? $image->metadata : [];

            $image->update([
                'status' => 'ready',
                'source' => ContentImage::SOURCE_GENERATED,
                'provider' => (string) $payload['provider'],
                'model' => (string) ($this->resolveImageRoute($content)['model'] ?: ''),
                'prompt' => $prompt,
                'image_path' => $originalPath,
                'image_url' => $imageUrl,
                'credit_cost' => $cost,
                'credit_status' => 'committed',
                'credit_ledger_entry_id' => (string) ($entry->id ?? ''),
                'credit_release_reason' => null,
                'original_path' => $originalPath,
                'medium_path' => $variants['medium_path'] ?? null,
                'thumbnail_path' => $variants['thumbnail_path'] ?? null,
                'original_webp_path' => $variants['original_webp_path'] ?? null,
                'medium_webp_path' => $variants['medium_webp_path'] ?? null,
                'thumbnail_webp_path' => $variants['thumbnail_webp_path'] ?? null,
                'width' => $variants['width'] ?? null,
                'height' => $variants['height'] ?? null,
                'file_size' => $variants['file_size'] ?? null,
                'is_active' => true,
                'display_on_website' => (string) $image->type === self::FEATURED_TYPE || (bool) $image->display_on_website,
                'display_as_featured_image' => (string) $image->type === self::FEATURED_TYPE || (bool) $image->display_as_featured_image,
                'use_as_social_image' => (string) $image->type === self::FEATURED_TYPE || (bool) $image->use_as_social_image,
                'error_message' => null,
                'metadata' => array_replace_recursive($existingMetadata, [
                    'mime' => (string) ($payload['mime'] ?? ''),
                    'generated_at' => now()->toIso8601String(),
                    'variants' => [
                        'medium_path' => $variants['medium_path'] ?? null,
                        'thumbnail_path' => $variants['thumbnail_path'] ?? null,
                        'medium_webp_path' => $variants['medium_webp_path'] ?? null,
                        'thumbnail_webp_path' => $variants['thumbnail_webp_path'] ?? null,
                    ],
                ]),
            ]);

            return $image;
        } catch (\Throwable $exception) {
            app(GenerationFinalizer::class)->markContentImageFailedAndRefundIfNeeded(
                $image,
                'provider_error',
                $exception->getMessage()
            );
            throw $exception;
        }
    }

    public function generateDerivativesForStoredImage(ContentImage $image, ?Content $content = null): ContentImage
    {
        $content = $content ?: $image->content;
        if (! $content) {
            return $image;
        }

        $disk = $this->resolveImageStorageDisk();
        $originalPath = trim((string) ($image->original_path ?: $image->image_path));
        if ($originalPath === '' || ! Storage::disk($disk)->exists($originalPath)) {
            return $image;
        }

        $binary = (string) Storage::disk($disk)->get($originalPath);
        if ($binary === '') {
            return $image;
        }

        $mime = trim((string) data_get($image->metadata, 'mime', ''));
        if ($mime === '') {
            $ext = strtolower((string) pathinfo($originalPath, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : 'image/png');
        }

        $variants = $this->createDerivativeVariants(
            content: $content,
            imageId: (string) $image->id,
            sourceBinary: $binary,
            sourceMime: $mime,
            originalPath: $originalPath
        );

        $image->update([
            'original_path' => $variants['original_path'] ?? $originalPath,
            'medium_path' => $variants['medium_path'] ?? $image->medium_path,
            'thumbnail_path' => $variants['thumbnail_path'] ?? $image->thumbnail_path,
            'original_webp_path' => $variants['original_webp_path'] ?? $image->original_webp_path,
            'medium_webp_path' => $variants['medium_webp_path'] ?? $image->medium_webp_path,
            'thumbnail_webp_path' => $variants['thumbnail_webp_path'] ?? $image->thumbnail_webp_path,
            'width' => $variants['width'] ?? $image->width,
            'height' => $variants['height'] ?? $image->height,
            'file_size' => $variants['file_size'] ?? $image->file_size,
        ]);

        return $image->refresh();
    }

    public function createAndProcessFeaturedImage(Content $content, CreditWalletService $wallets): ContentImage
    {
        $image = ContentImage::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $content->workspace_id,
            'content_id' => (string) $content->id,
            'type' => self::FEATURED_TYPE,
            'source' => ContentImage::SOURCE_GENERATED,
            'prompt' => $this->buildPrompt($content),
            'provider' => $this->resolveImageRoute($content)['provider'],
            'credit_cost' => $this->resolveCreditCost(),
            'status' => 'generating',
            'is_active' => false,
            'display_on_website' => true,
            'display_as_featured_image' => true,
            'use_as_social_image' => true,
            'created_by' => $content->updated_by,
        ]);

        Artisan::call('optimize:clear');

        return $this->processFeaturedImage($image, $wallets);
    }

    /**
     * @return array{provider:string,model:string}
     */
    private function resolveImageRoute(?Content $content = null): array
    {
        $route = $this->routing->resolve(
            feature: 'image_generation',
            modality: 'image',
            workspaceId: $content ? (string) $content->workspace_id : null,
            siteId: $content ? (string) $content->client_site_id : null,
        );

        return [
            'provider' => (string) ($route['provider'] ?: config('argusly.ai.images.provider', 'openai')),
            'model' => (string) ($route['model'] ?: ''),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function extractSubKeywords(Content $content): array
    {
        $draft = $content->drafts()->latest('created_at')->first();
        $meta = is_array($draft?->meta) ? $draft->meta : [];
        $keywords = data_get($meta, 'secondary_keywords', []);

        if (is_string($keywords)) {
            $keywords = preg_split('/[,|]/', $keywords) ?: [];
        }

        if (! is_array($keywords)) {
            return [];
        }

        return collect($keywords)
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   original_path:string,
     *   medium_path:?string,
     *   thumbnail_path:?string,
     *   original_webp_path:?string,
     *   medium_webp_path:?string,
     *   thumbnail_webp_path:?string,
     *   width:?int,
     *   height:?int,
     *   file_size:int
     * }
     */
    private function createDerivativeVariants(
        Content $content,
        string $imageId,
        string $sourceBinary,
        string $sourceMime,
        string $originalPath
    ): array {
        $disk = $this->resolveImageStorageDisk();
        $fileSize = strlen($sourceBinary);

        $source = @imagecreatefromstring($sourceBinary);
        if (! is_resource($source) && ! $source instanceof \GdImage) {
            return [
                'original_path' => $originalPath,
                'medium_path' => null,
                'thumbnail_path' => null,
                'original_webp_path' => null,
                'medium_webp_path' => null,
                'thumbnail_webp_path' => null,
                'width' => null,
                'height' => null,
                'file_size' => $fileSize,
            ];
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $basePath = preg_replace('/\.[a-zA-Z0-9]+$/', '', $originalPath) ?: $originalPath;
        $isJpeg = Str::contains(strtolower($sourceMime), 'jpeg') || Str::endsWith(strtolower($originalPath), '.jpg') || Str::endsWith(strtolower($originalPath), '.jpeg');

        $mediumPath = $basePath.'-medium.'.($isJpeg ? 'jpg' : 'png');
        $thumbnailPath = $basePath.'-thumb.'.($isJpeg ? 'jpg' : 'png');
        $mediumBinary = $this->renderResizedBinary($source, self::MEDIUM_MAX_WIDTH, $isJpeg);
        $thumbnailBinary = $this->renderResizedBinary($source, self::THUMBNAIL_MAX_WIDTH, $isJpeg);

        if ($mediumBinary !== null) {
            Storage::disk($disk)->put($mediumPath, $mediumBinary);
        } else {
            $mediumPath = null;
        }

        if ($thumbnailBinary !== null) {
            Storage::disk($disk)->put($thumbnailPath, $thumbnailBinary);
        } else {
            $thumbnailPath = null;
        }

        $mediumWebpPath = null;
        $thumbnailWebpPath = null;
        $originalWebpPath = null;

        if ($this->canEncodeWebp()) {
            if ($mediumBinary !== null) {
                $mediumWebpBinary = $this->encodeBinaryToWebp($mediumBinary, self::MEDIUM_WEBP_QUALITY);
                if ($mediumWebpBinary !== null) {
                    $mediumWebpPath = $basePath.'-medium.webp';
                    Storage::disk($disk)->put($mediumWebpPath, $mediumWebpBinary);
                }
            }

            if ($thumbnailBinary !== null) {
                $thumbnailWebpBinary = $this->encodeBinaryToWebp($thumbnailBinary, self::THUMBNAIL_WEBP_QUALITY);
                if ($thumbnailWebpBinary !== null) {
                    $thumbnailWebpPath = $basePath.'-thumb.webp';
                    Storage::disk($disk)->put($thumbnailWebpPath, $thumbnailWebpBinary);
                }
            }

            if (($width * $height) <= self::ORIGINAL_WEBP_MAX_PIXELS && ($isJpeg || Str::contains(strtolower($sourceMime), 'png'))) {
                $originalWebpBinary = $this->encodeBinaryToWebp($sourceBinary, self::ORIGINAL_WEBP_QUALITY);
                if ($originalWebpBinary !== null) {
                    $originalWebpPath = $basePath.'.webp';
                    Storage::disk($disk)->put($originalWebpPath, $originalWebpBinary);
                }
            }
        } else {
            Log::warning('content_image.webp_encoding_unavailable', [
                'content_id' => (string) $content->id,
                'content_image_id' => $imageId,
                'mime' => $sourceMime,
            ]);
        }

        imagedestroy($source);

        return [
            'original_path' => $originalPath,
            'medium_path' => $mediumPath,
            'thumbnail_path' => $thumbnailPath,
            'original_webp_path' => $originalWebpPath,
            'medium_webp_path' => $mediumWebpPath,
            'thumbnail_webp_path' => $thumbnailWebpPath,
            'width' => $width,
            'height' => $height,
            'file_size' => $fileSize,
        ];
    }

    private function assertImagesEnabled(): void
    {
        if (! (bool) config('argusly.images.enabled', true)) {
            throw new RuntimeException('Image generation is disabled by configuration.');
        }
    }

    private function resolveImageStorageDisk(): string
    {
        return (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images'));
    }

    private function canEncodeWebp(): bool
    {
        if (! (bool) config('argusly.ai.images.webp.enabled', true)) {
            return false;
        }

        return function_exists('imagewebp');
    }

    private function renderResizedBinary($source, int $maxWidth, bool $asJpeg): ?string
    {
        $sourceWidth = max(1, imagesx($source));
        $sourceHeight = max(1, imagesy($source));

        $targetWidth = min($sourceWidth, $maxWidth);
        $scale = $targetWidth / $sourceWidth;
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $canvas) {
            return null;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        if ($asJpeg) {
            imagejpeg($canvas, null, 86);
        } else {
            imagepng($canvas, null, 6);
        }
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary !== '' ? $binary : null;
    }

    private function encodeBinaryToWebp(string $binary, int $quality): ?string
    {
        $source = @imagecreatefromstring($binary);
        if (! is_resource($source) && ! $source instanceof \GdImage) {
            return null;
        }

        ob_start();
        imagewebp($source, null, $quality);
        $webp = (string) ob_get_clean();
        imagedestroy($source);

        return $webp !== '' ? $webp : null;
    }
}
