<?php

namespace App\Services\DraftDelivery;

use App\Models\Content;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PushContentOgImageToWordPress
{
    /**
     * @return array{ok:bool,status:int|null,body:string|null,error:string|null}
     */
    public function push(Content $content): array
    {
        $content->loadMissing(['clientSite', 'drafts', 'ogImage', 'currentRevision', 'currentVersion']);

        if (! $content->clientSite) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'No connected site found for this content.',
            ];
        }

        $og = $content->ogImage;
        $ogImageUrl = $og?->getWordPressUploadUrl($content->clientSite);
        if (! $og || $og->status !== 'ready' || blank($ogImageUrl)) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'No ready OG image available to push.',
            ];
        }

        $draft = $content->drafts()->latest('created_at')->first();
        $meta = is_array($draft?->meta) ? $draft->meta : [];
        $clientRefs = is_array(data_get($meta, 'client_refs')) ? data_get($meta, 'client_refs') : [];
        $remoteDraftId = $this->resolveRemoteDraftId($content, $draft, $clientRefs);

        $url = trim((string) ($clientRefs['draft_webhook_url'] ?? $content->clientSite->draft_webhook_url ?? ''));
        $secret = trim((string) ($clientRefs['draft_webhook_secret'] ?? $content->clientSite->draft_webhook_secret ?? ''));
        if ($url !== '' && $secret === '') {
            $secret = trim((string) config('argusly.webhooks.secret', ''));
        }

        if ($url === '' || $secret === '') {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'WordPress connector is not configured for this content/site.',
            ];
        }

        $payload = [
            // Backward-compatible draft fields for older WP handlers.
            'id' => $remoteDraftId,
            'pl_draft_id' => (string) ($draft?->id ?? ''),
            'brief_id' => (string) ($draft?->brief_id ?? ''),
            'event' => 'content.og_image',
            'content_id' => (string) $content->id,
            'draft_id' => (string) ($draft?->id ?? ''),
            'wp_post_id' => (string) ($content->wp_post_id ?? ''),
            'title' => (string) (($draft?->title ?: $content->title) ?? ''),
            'content_html' => $this->resolveContentHtml($content, $draft),
            'meta' => $this->resolveContentMeta($content, $draft),
            'links' => $this->resolveContentLinks($draft),
            'og_image_url' => (string) $ogImageUrl,
            'og_image_path' => $og->getPathForWordPressUpload($content->clientSite),
            'og_image_filename' => $og->getWordPressUploadFilename($content->clientSite),
            'og_image_mime' => $og->getWordPressUploadMimeType($content->clientSite),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            throw new RuntimeException('Failed to encode WP OG image payload.');
        }

        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$body, $secret);

        try {
            $response = Http::timeout(20)
                ->withOptions([
                    'verify' => app()->environment('local') ? false : true,
                ])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Argusly-Timestamp' => $ts,
                    'X-Argusly-Signature' => $signature,
                    'X-Argusly-Timestamp' => $ts,
                    'X-Argusly-Signature' => $signature,
                ])
                ->send('POST', $url, ['body' => $body]);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
                'error' => $response->successful() ? null : ('HTTP '.$response->status()),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $clientRefs
     */
    private function resolveRemoteDraftId(Content $content, mixed $draft, array $clientRefs): string
    {
        $remoteDraftId = trim((string) ($clientRefs['remote_draft_id'] ?? ''));
        if ($remoteDraftId !== '') {
            return $remoteDraftId;
        }

        $wpDraftId = trim((string) ($clientRefs['wp_draft_id'] ?? ''));
        if ($wpDraftId !== '') {
            return $wpDraftId;
        }

        $wpPostId = trim((string) ($content->wp_post_id ?: ($clientRefs['wp_post_id'] ?? '')));
        if ($wpPostId !== '') {
            return $wpPostId;
        }

        $contentId = trim((string) $content->id);
        if ($contentId !== '') {
            return $contentId;
        }

        return (string) ($draft?->id ?? '');
    }

    private function resolveContentHtml(Content $content, mixed $draft): string
    {
        $versionHtml = (string) ($content->currentVersion?->body ?? '');
        if ($versionHtml !== '') {
            return $versionHtml;
        }

        $revisionHtml = (string) ($content->currentRevision?->content_html ?? '');
        if ($revisionHtml !== '') {
            return $revisionHtml;
        }

        return (string) ($draft?->content_html ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveContentMeta(Content $content, mixed $draft): array
    {
        $versionMeta = $content->currentVersion?->meta;
        if (is_array($versionMeta)) {
            return $versionMeta;
        }

        $revisionMeta = $content->currentRevision?->meta;
        if (is_array($revisionMeta)) {
            return $revisionMeta;
        }

        $draftMeta = $draft?->meta;
        if (is_array($draftMeta)) {
            return $draftMeta;
        }

        return [];
    }

    /**
     * @return array<int,mixed>
     */
    private function resolveContentLinks(mixed $draft): array
    {
        return is_array($draft?->links) ? $draft->links : [];
    }
}
