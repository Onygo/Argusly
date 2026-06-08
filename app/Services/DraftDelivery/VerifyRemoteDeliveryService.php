<?php

namespace App\Services\DraftDelivery;

use App\Models\Content;
use App\Models\ContentDeliveryEvent;
use App\Models\ContentPublication;
use App\Models\SiteToken;
use App\Services\WordPress\WordPressConnector;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Service for verifying remote WordPress post existence.
 *
 * Used to check if a published content still exists on the remote WordPress site,
 * updating the publication status and recording verification events.
 */
class VerifyRemoteDeliveryService
{
    public function __construct(
        private readonly WordPressConnector $wordPressConnector,
    ) {}

    /**
     * Verify if the WordPress post exists for this content.
     *
     * @return array{exists: bool, wp_post_id: ?string, published_url: ?string, http_status: ?int}
     *
     * @throws RuntimeException
     */
    public function verify(Content $content): array
    {
        $content->loadMissing('clientSite', 'publications');

        $publication = $content->publications()
            ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
            ->first();

        $wpPostId = $publication?->remote_id ?? $content->wp_post_id;

        if (! $wpPostId) {
            throw new RuntimeException('No WordPress post ID found for this content.');
        }

        $site = $content->clientSite;
        if (! $site) {
            throw new RuntimeException('Content is not linked to a site.');
        }

        $base = rtrim((string) ($site->base_url ?: $site->site_url), '/');
        if ($base === '') {
            throw new RuntimeException('Site has no base URL configured.');
        }

        $token = $this->resolveOutboundSiteToken((string) $site->id);
        if ($token === '') {
            throw new RuntimeException('No valid site token found. Regenerate the site connection key.');
        }

        $startTime = microtime(true);
        $lookup = $this->wordPressConnector
            ->forSite($base, $token)
            ->postExists($wpPostId);
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $result = $lookup->toArray();

        $correlationId = (string) Str::uuid();

        // Update publication record if it exists
        if ($publication) {
            if ($result['exists']) {
                $publication->markVerified();

                // Update remote URL if returned
                if (! empty($result['published_url'])) {
                    $publication->forceFill([
                        'remote_url' => $result['published_url'],
                    ])->save();
                }

                ContentDeliveryEvent::recordVerify(
                    $publication,
                    true,
                    $result,
                    $result['status'] ?? 200,
                    $correlationId,
                    $durationMs
                );
            } else {
                $publication->markMissingRemote($wpPostId);

                ContentDeliveryEvent::recordVerify(
                    $publication,
                    false,
                    $result,
                    $result['status'] ?? 404,
                    $correlationId,
                    $durationMs
                );
            }
        }

        // Update content record based on remote existence
        if (! $result['exists']) {
            $content->forceFill([
                'delivery_status' => 'missing_remote',
            ])->save();
        } else {
            // Remote exists - clear any stale publish error from previous failed attempts
            $content->forceFill([
                'publish_error' => null,
            ])->save();
        }

        return [
            'exists' => $result['exists'],
            'wp_post_id' => $result['exists'] ? $wpPostId : null,
            'published_url' => $result['published_url'] ?? null,
            'http_status' => $result['status'] ?? null,
        ];
    }

    /**
     * Resolve the site token for outbound API calls.
     */
    private function resolveOutboundSiteToken(string $clientSiteId): string
    {
        if ($clientSiteId === '') {
            return '';
        }

        $tokens = SiteToken::query()
            ->where('client_site_id', $clientSiteId)
            ->where('revoked', false)
            ->whereNull('revoked_at')
            ->whereNotNull('token_encrypted')
            ->latest('created_at')
            ->get(['token_encrypted']);

        foreach ($tokens as $token) {
            try {
                $plain = trim((string) Crypt::decryptString((string) $token->token_encrypted));
                if ($plain !== '') {
                    return $plain;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return '';
    }

    /**
     * Reconcile a content's delivery status after a failed or uncertain delivery.
     *
     * This verifies whether the content exists remotely and updates Argusly
     * state accordingly. Used to recover from partial success scenarios where
     * the post was created but an error was returned.
     *
     * @return array{
     *     reconciled: bool,
     *     previous_status: string,
     *     new_status: string,
     *     wp_post_id: ?string,
     *     published_url: ?string,
     *     message: string
     * }
     */
    public function reconcile(Content $content, ?string $originalError = null): array
    {
        $content->loadMissing('clientSite', 'publications', 'drafts');
        $previousStatus = (string) ($content->delivery_status ?? 'unknown');

        // Skip if already successfully delivered
        if (in_array($previousStatus, ['delivered', 'partial_success'], true)) {
            return [
                'reconciled' => false,
                'previous_status' => $previousStatus,
                'new_status' => $previousStatus,
                'wp_post_id' => $content->wp_post_id,
                'published_url' => null,
                'message' => 'Content is already marked as delivered.',
            ];
        }

        // Try to verify by existing wp_post_id first
        $wpPostId = $content->wp_post_id;
        $publication = $content->publications()
            ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
            ->first();

        if (! $wpPostId && $publication) {
            $wpPostId = $publication->remote_id;
        }

        // If we have a wp_post_id, verify it exists
        if ($wpPostId && trim((string) $wpPostId) !== '') {
            try {
                $result = $this->verify($content);

                if ($result['exists']) {
                    return $this->markReconciled(
                        $content,
                        $publication,
                        $previousStatus,
                        $wpPostId,
                        $result['published_url'],
                        $originalError
                    );
                }
            } catch (\Throwable $e) {
                // Verification failed, continue to lookup by meta
            }
        }

        // Try to find the post by Argusly metadata
        $foundPost = $this->lookupRemotePostByMeta($content);

        if ($foundPost !== null) {
            $wpPostId = $foundPost['wp_post_id'];
            $publishedUrl = $foundPost['published_url'];

            // Persist the discovered wp_post_id
            $content->forceFill(['wp_post_id' => $wpPostId])->save();

            if ($publication) {
                $publication->forceFill([
                    'remote_id' => $wpPostId,
                    'remote_url' => $publishedUrl,
                ])->save();
            }

            return $this->markReconciled(
                $content,
                $publication,
                $previousStatus,
                $wpPostId,
                $publishedUrl,
                $originalError
            );
        }

        return [
            'reconciled' => false,
            'previous_status' => $previousStatus,
            'new_status' => $previousStatus,
            'wp_post_id' => null,
            'published_url' => null,
            'message' => 'Could not find the remote post. The delivery may have truly failed.',
        ];
    }

    /**
     * Look up a remote post by Argusly metadata.
     *
     * @return array{wp_post_id: string, published_url: ?string}|null
     */
    private function lookupRemotePostByMeta(Content $content): ?array
    {
        $site = $content->clientSite;
        if (! $site) {
            return null;
        }

        $base = rtrim((string) ($site->base_url ?: $site->site_url), '/');
        if ($base === '') {
            return null;
        }

        $token = $this->resolveOutboundSiteToken((string) $site->id);
        if ($token === '') {
            return null;
        }

        $connector = $this->wordPressConnector->forSite($base, $token);

        // Build lookup criteria
        $criteria = [
            'argusly_content_id' => (string) $content->id,
            'argusly_locale' => strtolower(trim((string) ($content->language ?? ''))),
            'argusly_destination_id' => trim((string) ($content->content_destination_id ?? $content->client_site_id ?? '')),
        ];

        if ($content->external_key) {
            $criteria['external_key'] = $content->external_key;
        }

        // Get the latest draft ID
        $latestDraft = $content->drafts()->latest('created_at')->first();
        if ($latestDraft) {
            $criteria['argusly_draft_id'] = (string) $latestDraft->id;
        }

        try {
            $post = $connector->findPostByMeta($criteria);

            if ($post !== null) {
                return [
                    'wp_post_id' => $post->id,
                    'published_url' => $post->publishedUrl,
                ];
            }
        } catch (\Throwable) {
            // Lookup failed, return null
        }

        return null;
    }

    /**
     * Mark a content as reconciled (found to exist remotely).
     *
     * @return array{
     *     reconciled: bool,
     *     previous_status: string,
     *     new_status: string,
     *     wp_post_id: string,
     *     published_url: ?string,
     *     message: string
     * }
     */
    private function markReconciled(
        Content $content,
        ?ContentPublication $publication,
        string $previousStatus,
        string $wpPostId,
        ?string $publishedUrl,
        ?string $originalError
    ): array {
        $newStatus = 'delivered';

        // Update content
        $content->forceFill([
            'delivery_status' => $newStatus,
            'wp_post_id' => $wpPostId,
            // Clear any stale publish error since delivery is now reconciled
            'publish_error' => null,
            'status' => in_array($content->status, ['brief', 'draft', 'review', 'approved', 'ready_to_deliver'], true)
                ? 'published'
                : $content->status,
        ])->save();

        // Update publication
        if ($publication) {
            $meta = is_array($publication->meta) ? $publication->meta : [];
            $meta['reconciled'] = true;
            $meta['reconciled_at'] = now()->toIso8601String();
            $meta['reconciled_from_status'] = $previousStatus;
            if ($originalError) {
                $meta['original_delivery_error'] = Str::limit($originalError, 2000);
            }

            $publication->forceFill([
                'delivery_status' => $newStatus,
                'remote_id' => $wpPostId,
                'remote_url' => $publishedUrl,
                'last_verified_at' => now(),
                'meta' => $meta,
            ])->save();

            ContentDeliveryEvent::recordReconcile(
                $publication,
                true,
                ['wp_post_id' => $wpPostId, 'published_url' => $publishedUrl],
                (string) Str::uuid()
            );
        }

        // Update the latest draft
        $latestDraft = $content->drafts()->latest('created_at')->first();
        if ($latestDraft) {
            $latestDraft->forceFill([
                'delivery_status' => $newStatus,
                'delivered_at' => now(),
                'acked_at' => now(),
                'delivery_last_error' => $originalError
                    ? '[RECONCILED] Original error: ' . Str::limit($originalError, 500)
                    : null,
            ])->save();
        }

        return [
            'reconciled' => true,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'wp_post_id' => $wpPostId,
            'published_url' => $publishedUrl,
            'message' => sprintf(
                'Delivery reconciled. Post was found on WordPress (ID: %s). Status changed from %s to %s.',
                $wpPostId,
                $previousStatus,
                $newStatus
            ),
        ];
    }
}
