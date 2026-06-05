<?php

namespace App\Services\DraftDelivery;

use App\Enums\WordPressPostType;
use App\Events\Onboarding\ContentPushedToWordPress;
use App\Events\Notifications\DraftDeliveryFailed;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDeliveryEvent;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Event;
use App\Models\SiteToken;
use App\Models\WebhookEndpoint;
use App\Services\Content\AnswerBlockInjectorService;
use App\Services\Content\AnswerBlockSchemaService;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\Seo\SeoProviderRegistry;
use App\Services\WordPress\Data\WordPressPost;
use App\Services\WordPress\WordPressConnector;
use App\Services\WordPress\Exceptions\WordPressConnectorException;
use App\Support\ImageAttribution;
use App\Support\SeoMetadata;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliverDraftToWordPress
{
    // Lock timeout for delivery operations (5 minutes)
    private const DELIVERY_LOCK_TIMEOUT = 300;

    // Tracks the current publication during delivery for event logging
    private ?ContentPublication $currentPublication = null;

    // Tracks delivery timing for events
    private ?float $deliveryStartTime = null;

    // Tracks the current delivery lock
    private ?Lock $currentLock = null;

    // Tracks the current correlation ID for logging
    private ?string $currentCorrelationId = null;

    // Allows connector orchestration to own canonical publication writes.
    private bool $suppressPublicationWrites = false;

    public function __construct(
        private readonly WorkspaceEntitlementsService $entitlements,
        private readonly ?WordPressConnector $wordPressConnector = null,
        private readonly ?PayloadChecksumService $checksumService = null,
        private readonly ?AnswerBlockInjectorService $answerBlockInjector = null,
        private readonly ?AnswerBlockSchemaService $answerBlockSchema = null,
    ) {}

    public function markDelivering(string $draftId): ?Draft
    {
        return DB::transaction(function () use ($draftId) {
            $draft = Draft::query()
                ->where('id', $draftId)
                ->lockForUpdate()
                ->first();

            if (! $draft) {
                return null;
            }

            // Accept both the canonical delivery-ready state and older generated drafts
            // that are otherwise complete but not yet normalized to ready_to_deliver.
            if (! in_array((string) $draft->status, ['ready_to_deliver', 'generated'], true)) {
                return null;
            }

            if (! in_array((string) $draft->delivery_status, ['pending', 'failed', 'missing_remote'], true)) {
                return null;
            }

            $draft->delivery_status = 'processing';
            $draft->status = 'ready_to_deliver';
            $draft->delivery_started_at = now();
            $draft->delivery_attempts = (int) $draft->delivery_attempts + 1;
            $draft->delivery_last_error = null;
            $draft->save();

            return $draft;
        });
    }

    public function primeConnectorDraftForDelivery(string $draftId): ?Draft
    {
        return DB::transaction(function () use ($draftId) {
            $draft = Draft::query()
                ->where('id', $draftId)
                ->lockForUpdate()
                ->first();

            if (! $draft) {
                return null;
            }

            $status = (string) $draft->status;
            $deliveryStatus = (string) $draft->delivery_status;

            if (
                in_array($status, ['ready_to_deliver', 'generated'], true)
                && in_array($deliveryStatus, ['pending', 'failed', 'missing_remote'], true)
            ) {
                $draft->delivery_status = 'processing';
                $draft->status = 'ready_to_deliver';
                $draft->delivery_started_at = now();
                $draft->delivery_attempts = (int) $draft->delivery_attempts + 1;
                $draft->delivery_last_error = null;
                $draft->save();

                return $draft;
            }

            if ($status === 'generated') {
                $draft->status = 'ready_to_deliver';
                $draft->save();
            }

            return $draft;
        });
    }

    public function deliver(Draft $draft, bool $forceDelivery = false): array
    {
        $this->deliveryStartTime = microtime(true);
        $this->generateCorrelationId();
        $draft->loadMissing('content.currentRevision', 'content.currentVersion', 'content.featuredImage', 'content.ogImage', 'content.seo', 'clientSite', 'brief');

        // Attempt to acquire a delivery lock to prevent concurrent deliveries
        $lock = $this->acquireDeliveryLockForDraft($draft);
        if (! $lock) {
            $this->logDelivery('skipped', $draft, [
                'reason' => 'concurrent_delivery_in_progress',
            ], 'warning');

            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'Another delivery is already in progress for this content. Please try again shortly.',
                'skipped' => true,
                'reason' => 'concurrent_lock',
            ];
        }

        try {
            return $this->executeDeliveryWithLock($draft, $forceDelivery);
        } finally {
            $this->releaseDeliveryLock($draft);
        }
    }

    /**
     * Execute the actual delivery logic while holding the lock.
     */
    private function executeDeliveryWithLock(Draft $draft, bool $forceDelivery = false): array
    {
        $this->logDelivery('started', $draft, [
            'force' => $forceDelivery,
            'attempt' => (int) $draft->delivery_attempts,
        ]);

        // Resolve or create the publication record for this content + destination
        if ($this->suppressPublicationWrites) {
            $this->currentPublication ??= $this->resolvePublicationForDraft($draft);
        } else {
            $this->currentPublication = $this->resolvePublicationForDraft($draft);
        }

        if ($draft->clientSite?->workspace) {
            try {
                $this->entitlements->assertCanPushToWp($draft->clientSite->workspace);
            } catch (\RuntimeException $exception) {
                $this->logDelivery('entitlement_failed', $draft, [
                    'error' => $exception->getMessage(),
                ], 'error');

                return [
                    'ok' => false,
                    'status' => null,
                    'body' => null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $draftMeta = is_array($draft->meta) ? $draft->meta : [];
        $draftMetaRefs = data_get($draftMeta, 'client_refs', []);
        $briefRefs = is_array($draft->brief?->client_refs) ? $draft->brief->client_refs : [];
        $clientRefs = array_replace($briefRefs, is_array($draftMetaRefs) ? $draftMetaRefs : []);

        $content = $draft->content?->loadMissing('answerBlocks');
        $activeRevision = $content?->currentRevision;
        $activeVersion = $content?->currentVersion;
        $payloadHtml = $this->normalizeOutgoingHtml(
            (string) ($activeVersion?->body ?: ($activeRevision?->content_html ?: $draft->content_html))
        );
        if ($content instanceof Content) {
            $payloadHtml = $this->normalizeOutgoingHtml(
                $this->answerBlockInjector()->inject($payloadHtml, $content)
            );
        }
        $featuredImageAttribution = $content instanceof Content
            ? ImageAttribution::fromContentImage($content->featuredImage)
            : [];
        $payloadMeta = $activeVersion?->meta ?: ($activeRevision?->meta ?: $draft->meta);
        $payloadSeo = $this->resolveSeoPayload($draft, $payloadMeta, (string) $payloadHtml);
        $payloadMeta = array_replace_recursive(
            is_array($payloadMeta) ? $payloadMeta : [],
            array_filter([
                'primary_keyword' => $payloadSeo['primary_keyword'],
                'meta_description' => $payloadSeo['seo_meta_description'],
                'canonical_url' => $payloadSeo['seo_canonical'],
                'og_title' => $payloadSeo['seo_og_title'],
                'og_description' => $payloadSeo['seo_og_description'],
                'og_image' => $payloadSeo['seo_og_image'],
                'twitter_title' => $payloadSeo['seo_twitter_title'],
                'twitter_description' => $payloadSeo['seo_twitter_description'],
                'robots_index' => $payloadSeo['robots_index'],
                'robots_follow' => $payloadSeo['robots_follow'],
                'schema_type' => $payloadSeo['schema_type'],
            ], static fn ($value) => is_bool($value) || trim((string) $value) !== '')
        );
        $payloadMeta = $this->withPublishLayerMeta($payloadMeta, $draft, $payloadSeo);
        $remoteDraftId = $this->resolveRemoteDraftId($draft, $clientRefs);
        $correlationId = $this->currentCorrelationId ?? (string) Str::uuid();
        $slug = $this->resolvePayloadSlug($draft, $payloadMeta);
        $excerpt = $this->resolvePayloadExcerpt($payloadMeta, (string) $payloadHtml);
        $exportedAnswerBlocks = $content instanceof Content ? $this->answerBlockSchema()->exportableBlocks($content) : [];
        $faqSchema = $content instanceof Content ? $this->answerBlockSchema()->forContent($content) : null;

        // WP expects "id" as the remote draft id
        $wpPostType = $this->resolveWordPressPostType($draft);
        $payload = [
            'id' => $remoteDraftId,
            'correlation_id' => $correlationId,
            'pl_draft_id' => $draft->id,
            'publishlayer_draft_id' => (string) $draft->id,
            'publishlayer_content_id' => (string) ($draft->content_id ?? ''),
            'publishlayer_publication_id' => (string) ($this->currentPublication?->id ?? ''),
            'publishlayer_origin' => 'publishlayer',
            'post_type' => $wpPostType->value,
            'language' => $draft->language->value,
            'is_translation' => $draft->isTranslation(),
            'source_draft_id' => $draft->source_draft_id ? (string) $draft->source_draft_id : null,
            'brief_id' => $draft->brief_id,
            'content_id' => $draft->content_id,
            'external_key' => $draft->content?->external_key,
            'wp_post_id' => $draft->content?->wp_post_id ?: data_get($clientRefs, 'wp_post_id'),
            'wp_draft_id' => data_get($clientRefs, 'wp_draft_id'),
            'status' => $this->resolveTargetWpStatus($draft),
            'title' => $draft->title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'output_type' => $draft->output_type,
            'content' => $payloadHtml,
            'content_html' => $payloadHtml,
            'answer_blocks' => $exportedAnswerBlocks,
            'faq_schema' => $faqSchema,
            'seo_title' => $payloadSeo['seo_title'],
            'seo_meta_description' => $payloadSeo['seo_meta_description'],
            'seo_h1' => $payloadSeo['seo_h1'],
            'primary_keyword' => $payloadSeo['primary_keyword'],
            'robots_index' => $payloadSeo['robots_index'],
            'robots_follow' => $payloadSeo['robots_follow'],
            'schema_type' => $payloadSeo['schema_type'],
            'seo_canonical' => $payloadSeo['seo_canonical'],
            'seo_og_title' => $payloadSeo['seo_og_title'],
            'seo_og_description' => $payloadSeo['seo_og_description'],
            'seo_og_image' => $payloadSeo['seo_og_image'],
            'seo_twitter_title' => $payloadSeo['seo_twitter_title'],
            'seo_twitter_description' => $payloadSeo['seo_twitter_description'],
            'meta_title' => $payloadSeo['seo_title'],
            'meta_description' => $payloadSeo['seo_meta_description'],
            'canonical_url' => $payloadSeo['seo_canonical'],
            'og_image' => $payloadSeo['seo_og_image'],
            'meta' => $payloadMeta,
            // Internal links are already materialized inside content_html.
            // Do not send link hints that a connector could render again.
            'links' => [],
            'featured_image_url' => $this->resolveFeaturedImageUrl($draft),
            'featured_image_attribution' => (string) ($featuredImageAttribution['text'] ?? ''),
            'image_attribution' => $featuredImageAttribution,
            'og_image_url' => $this->resolveOgImageUrl($draft),
            'policy' => $this->resolveConnectorPolicyPayload($draft),
        ];
        if ($exportedAnswerBlocks !== [] || $faqSchema !== null) {
            $payload['meta']['publishlayer'] = array_replace_recursive(
                is_array($payload['meta']['publishlayer'] ?? null) ? $payload['meta']['publishlayer'] : [],
                array_filter([
                    'answer_blocks' => $exportedAnswerBlocks !== [] ? $exportedAnswerBlocks : null,
                    'faq_schema' => $faqSchema,
                ], fn ($value) => $value !== null)
            );
        }
        $payload = $this->applySeoSyncPayload($draft, $payload, $payloadSeo);
        $payload = $this->applyPublishLayerRemoteMeta($payload, $draft);
        [$clientRefs, $payload, $resolvedRemote] = $this->alignPayloadWithResolvedRemotePost($draft, $clientRefs, $payload);
        [$clientRefs, $payload] = $this->normalizePayloadForRemoteWordPressState($draft, $clientRefs, $payload);
        $remoteDraftId = $this->resolveRemoteDraftId($draft, $clientRefs);
        $payload['id'] = $remoteDraftId;

        [$url, $secret] = $this->resolveWebhookCredentials($draft, $clientRefs);
        if ($url && $secret !== '') {
            if (empty($clientRefs['draft_webhook_url'])) {
                $clientRefs['draft_webhook_url'] = $url;
            }
            if (empty($clientRefs['draft_webhook_secret'])) {
                $clientRefs['draft_webhook_secret'] = $secret;
            }
        }

        $clientRefs['remote_draft_id'] = $remoteDraftId;
        $draftMeta['client_refs'] = $clientRefs;
        $draft->meta = $draftMeta;
        $draft->save();

        // Calculate payload checksum and check if delivery can be skipped
        $currentChecksum = $this->calculatePayloadChecksum($payload);
        $storedChecksum = $this->currentPublication?->payload_checksum;

        $checksumResult = $this->shouldSkipDeliveryByChecksum($payload, $storedChecksum, $forceDelivery);

        if ($checksumResult['skip']) {
            $this->logDelivery('skipped_unchanged', $draft, [
                'reason' => 'checksum_unchanged',
                'checksum' => $currentChecksum,
                'sync_action' => $this->resolveSyncAction($draft, $clientRefs, $payload),
                'resolved_remote_post_id' => $resolvedRemote['id'] ?? null,
            ]);

            return [
                'ok' => true,
                'status' => 200,
                'body' => null,
                'error' => null,
                'skipped' => true,
                'reason' => 'checksum_unchanged',
                'payload_checksum' => $currentChecksum,
                'sync_action' => $this->resolveSyncAction($draft, $clientRefs, $payload),
                'resolved_remote_post_id' => $resolvedRemote['id'] ?? null,
            ];
        }

        $this->logDelivery('pushing', $draft, [
                'channel' => $url && $secret !== '' ? 'webhook' : 'direct_api',
                'checksum' => $currentChecksum,
                'checksum_reason' => $checksumResult['reason'],
                'sync_action' => $this->resolveSyncAction($draft, $clientRefs, $payload),
                'resolved_remote_post_id' => $resolvedRemote['id'] ?? null,
            ]);

        if ($url && $secret !== '') {
            $result = $this->pushViaWebhook($draft, $clientRefs, $payload, $correlationId, $remoteDraftId, $url, $secret);
            $result['payload_checksum'] = $currentChecksum;
            $this->recordSeoSyncForPublishTarget($draft, $payload, $result);
            $this->logDeliveryResult($draft, $result, $currentChecksum);

            return $result;
        }

        $result = $this->pushViaSiteTokenApi($draft, $clientRefs, $payload, $correlationId, $remoteDraftId);
        $result['payload_checksum'] = $currentChecksum;
        $this->recordSeoSyncForPublishTarget($draft, $payload, $result);
        $this->logDeliveryResult($draft, $result, $currentChecksum);

        return $result;
    }

    /**
     * @return array{
     *     ok:bool,
     *     wp_post_id:?string,
     *     status:int|null,
     *     error:?string,
     *     source:?string,
     *     retryable:bool
     * }
     */
    public function ensureWpPostIdForContent(Content $content): array
    {
        $content->loadMissing('clientSite', 'publishTargets');

        $existingWpPostId = trim((string) ($content->wp_post_id ?? ''));
        if ($existingWpPostId !== '') {
            $this->logWpPostIdEnsure($content, 'reused', [
                'source' => 'content.wp_post_id',
                'wp_post_id' => $existingWpPostId,
            ]);

            return [
                'ok' => true,
                'wp_post_id' => $existingWpPostId,
                'status' => 200,
                'error' => null,
                'source' => 'content.wp_post_id',
                'retryable' => false,
            ];
        }

        $draft = Draft::query()
            ->with('brief')
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();

        if ($draft) {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $draftRefs = is_array(data_get($meta, 'client_refs')) ? data_get($meta, 'client_refs') : [];
            $briefRefs = is_array($draft->brief?->client_refs) ? $draft->brief->client_refs : [];
            $refs = array_replace($briefRefs, $draftRefs);
            $wpPostIdFromRefs = trim((string) ($refs['wp_post_id'] ?? ''));

            if ($wpPostIdFromRefs !== '') {
                $this->persistWpPostId($content, $wpPostIdFromRefs, $draft, 'draft.client_refs');
                $this->logWpPostIdEnsure($content, 'set', [
                    'source' => 'draft.client_refs',
                    'wp_post_id' => $wpPostIdFromRefs,
                ]);

                return [
                    'ok' => true,
                    'wp_post_id' => $wpPostIdFromRefs,
                    'status' => 200,
                    'error' => null,
                    'source' => 'draft.client_refs',
                    'retryable' => false,
                ];
            }
        }

        // Check publication record first (new canonical source)
        $publication = ContentPublication::query()
            ->where('content_id', $content->id)
            ->where(function ($query) use ($content) {
                $query->where('destination_id', $content->content_destination_id)
                    ->orWhere(function ($q) use ($content) {
                        $q->whereNull('destination_id')
                            ->where('client_site_id', $content->client_site_id);
                    });
            })
            ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
            ->first();

        if ($publication && $publication->hasRemoteId()) {
            $wpPostIdFromPublication = $publication->getWpPostId();
            $this->persistWpPostId($content, $wpPostIdFromPublication, $draft, 'content_publications');
            $this->logWpPostIdEnsure($content, 'set', [
                'source' => 'content_publications',
                'wp_post_id' => $wpPostIdFromPublication,
                'publication_id' => $publication->id,
            ]);

            return [
                'ok' => true,
                'wp_post_id' => $wpPostIdFromPublication,
                'status' => 200,
                'error' => null,
                'source' => 'content_publications',
                'retryable' => false,
            ];
        }

        // Fallback to legacy publish targets
        $publishTarget = ContentPublishTarget::query()
            ->where('content_id', $content->id)
            ->where('client_site_id', $content->client_site_id)
            ->where('target_type', 'wp')
            ->latest('updated_at')
            ->first();

        if ($publishTarget) {
            $wpPostIdFromTarget = trim((string) (
                $publishTarget->wp_post_id
                ?? data_get($publishTarget->meta, 'wp_post_id')
                ?? $publishTarget->target_identifier
                ?? ''
            ));

            if ($wpPostIdFromTarget !== '') {
                $this->persistWpPostId($content, $wpPostIdFromTarget, $draft, 'content_publish_targets');
                $this->logWpPostIdEnsure($content, 'set', [
                    'source' => 'content_publish_targets',
                    'wp_post_id' => $wpPostIdFromTarget,
                ]);

                return [
                    'ok' => true,
                    'wp_post_id' => $wpPostIdFromTarget,
                    'status' => 200,
                    'error' => null,
                    'source' => 'content_publish_targets',
                    'retryable' => false,
                ];
            }
        }

        $lookupWpPostId = $this->findWpPostByPublishLayerId($content, $draft);
        if ($lookupWpPostId === '') {
            $lookupWpPostId = $this->lookupWpPostIdByExternalKey($content, $draft);
        }

        if ($lookupWpPostId !== '') {
            $this->persistWpPostId($content, $lookupWpPostId, $draft, 'remote_lookup');
            $this->logWpPostIdEnsure($content, 'set', [
                'source' => 'remote_lookup',
                'wp_post_id' => $lookupWpPostId,
            ]);

            return [
                'ok' => true,
                'wp_post_id' => $lookupWpPostId,
                'status' => 200,
                'error' => null,
                'source' => 'remote_lookup',
                'retryable' => false,
            ];
        }

        if (! $draft) {
            $message = 'Cannot ensure wp_post_id because no draft exists for this content.';
            $this->logWpPostIdEnsure($content, 'missing', [
                'error' => $message,
            ], 'warning');

            return [
                'ok' => false,
                'wp_post_id' => null,
                'status' => null,
                'error' => $message,
                'source' => null,
                'retryable' => false,
            ];
        }

        if (
            in_array((string) ($draft->delivery_status ?? ''), ['pending', 'processing'], true)
            && in_array((string) ($content->publish_status ?? ''), ['scheduled', 'publishing'], true)
        ) {
            $message = 'wp_post_id is missing while WordPress publish is in progress.';
            $this->logWpPostIdEnsure($content, 'missing', [
                'error' => $message,
                'draft_id' => (string) $draft->id,
                'draft_delivery_status' => (string) ($draft->delivery_status ?? ''),
                'content_publish_status' => (string) ($content->publish_status ?? ''),
            ], 'warning');

            return [
                'ok' => false,
                'wp_post_id' => null,
                'status' => null,
                'error' => $message,
                'source' => null,
                'retryable' => true,
            ];
        }

        $deliveryResult = $this->deliver($draft->fresh());
        $content->refresh();
        $resolvedWpPostId = trim((string) ($content->wp_post_id ?? ''));

        if ($resolvedWpPostId !== '') {
            $this->logWpPostIdEnsure($content, 'set', [
                'source' => 'deliver',
                'wp_post_id' => $resolvedWpPostId,
                'http_status' => $deliveryResult['status'] ?? null,
            ]);

            return [
                'ok' => true,
                'wp_post_id' => $resolvedWpPostId,
                'status' => isset($deliveryResult['status']) ? (int) $deliveryResult['status'] : 200,
                'error' => null,
                'source' => 'deliver',
                'retryable' => false,
            ];
        }

        $message = trim((string) ($deliveryResult['error'] ?? 'Unable to create or resolve a WordPress post id.'));
        $this->logWpPostIdEnsure($content, 'missing', [
            'error' => $message,
            'http_status' => $deliveryResult['status'] ?? null,
            'source' => 'deliver',
        ], 'warning');

        return [
            'ok' => false,
            'wp_post_id' => null,
            'status' => isset($deliveryResult['status']) ? (int) $deliveryResult['status'] : null,
            'error' => $message,
            'source' => 'deliver',
            'retryable' => false,
        ];
    }

    /**
     * @param array<string,mixed> $clientRefs
     * @param array<string,mixed> $payload
     */
    private function pushViaWebhook(
        Draft $draft,
        array $clientRefs,
        array $payload,
        string $correlationId,
        string $remoteDraftId,
        string $url,
        string $secret
    ): array {
        $body = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $syncAction = $this->resolveSyncAction($draft, $clientRefs, $payload);

        try {
            $response = Http::timeout(20)
                ->withOptions([
                    'verify' => app()->environment('local') ? false : true,
                ])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-PublishLayer-Timestamp' => $ts,
                    'X-PublishLayer-Signature' => $sig,
                    'X-PublishLayer-Correlation-Id' => $correlationId,
                ])
                ->send('POST', $url, ['body' => $body]);

            // If WordPress deleted the linked post, drop the stale id and retry once as a create.
            if ($response->status() === 404 && trim((string) ($payload['wp_post_id'] ?? '')) !== '') {
                $remotePost = $this->checkRemoteWordPressPost($draft, trim((string) $payload['wp_post_id']));
                if (($remotePost['missing'] ?? false) === true) {
                    [$retryClientRefs, $retryPayload] = $this->prepareRecreatePayloadAfterMissingRemote(
                        $draft,
                        $clientRefs,
                        $payload,
                        trim((string) $payload['wp_post_id']),
                        array_replace($remotePost, ['channel' => 'webhook_404_retry'])
                    );

                    return $this->pushViaWebhook(
                        $draft,
                        $retryClientRefs,
                        $retryPayload,
                        $correlationId,
                        $this->resolveRemoteDraftId($draft, $retryClientRefs),
                        $url,
                        $secret
                    );
                }
            }

            // Try to sync identifiers even for error responses
            $this->syncLocalWordPressIdentifiersFromResponse(
                $draft,
                (string) $response->body(),
                trim((string) ($payload['wp_post_id'] ?? ''))
            );

            // Check for partial success scenario
            $partialSuccess = $this->detectPartialSuccessFromResponse($response->status(), (string) $response->body());

            $this->logSyncAttempt($draft, [
                'correlation_id' => $correlationId,
                'remote_draft_id' => $remoteDraftId,
                'action' => $syncAction,
                'channel' => 'webhook',
                'result' => $response->successful() ? 'ok' : ($partialSuccess['is_partial'] ? 'partial_success' : 'http_error'),
                'http_status' => $response->status(),
                'partial_success' => $partialSuccess['is_partial'],
                'extracted_wp_post_id' => $partialSuccess['wp_post_id'] ?? null,
            ]);

            // Handle partial success - post was created but post-processing failed
            if ($partialSuccess['is_partial']) {
                return [
                    'ok' => true,
                    'partial_success' => true,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'error' => null,
                    'warning' => $partialSuccess['message'] ?? 'Post published with warnings',
                    'wp_post_id' => $partialSuccess['wp_post_id'] ?? null,
                    'post_processing_errors' => $partialSuccess['errors'] ?? [],
                    'sync_action' => $syncAction,
                ];
            }

            // Handle complete failure but try to detect if post might have been created
            if (! $response->successful()) {
                $maybeCreated = $this->checkIfPostMayHaveBeenCreated($draft, $response->status(), (string) $response->body());
                if ($maybeCreated['likely_created']) {
                    return [
                        'ok' => true,
                        'needs_verification' => true,
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'error' => null,
                        'warning' => 'Delivery returned an error but the post may have been created. Verification needed.',
                        'wp_post_id' => $maybeCreated['wp_post_id'] ?? null,
                        'original_error' => $this->formatRemoteDeliveryError($response->status(), (string) $response->body(), 'WordPress webhook delivery failed.'),
                        'sync_action' => $syncAction,
                    ];
                }

                if ($syncAction === 'create') {
                    $recovered = $this->resolveExistingRemotePostForPayload($draft, $clientRefs, $payload);
                    if (($recovered['id'] ?? null) !== null) {
                        return [
                            'ok' => true,
                            'status' => $response->status(),
                            'body' => $response->body(),
                            'error' => null,
                            'wp_post_id' => (string) $recovered['id'],
                            'published_url' => $recovered['published_url'] ?? null,
                            'sync_action' => 'update',
                            'recovered_existing' => true,
                            'recovery_reason' => $recovered['reason'] ?? 'recovered_by_meta_lookup',
                        ];
                    }
                }
            }

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
                'error' => $response->successful()
                    ? null
                    : $this->formatRemoteDeliveryError($response->status(), (string) $response->body(), 'WordPress webhook delivery failed.'),
                'sync_action' => $syncAction,
            ];
        } catch (\Throwable $e) {
            if ($syncAction === 'create') {
                $recovered = $this->resolveExistingRemotePostForPayload($draft, $clientRefs, $payload);
                if (($recovered['id'] ?? null) !== null) {
                    return [
                        'ok' => true,
                        'status' => null,
                        'body' => null,
                        'error' => null,
                        'wp_post_id' => (string) $recovered['id'],
                        'published_url' => $recovered['published_url'] ?? null,
                        'sync_action' => 'update',
                        'recovered_existing' => true,
                        'recovery_reason' => $recovered['reason'] ?? 'recovered_by_meta_lookup',
                    ];
                }
            }

            $this->logSyncAttempt($draft, [
                'correlation_id' => $correlationId,
                'remote_draft_id' => $remoteDraftId,
                'action' => $syncAction,
                'channel' => 'webhook',
                'result' => 'exception',
                'error_code' => $e::class,
                'error' => $e->getMessage(),
            ], 'error');

            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => $e->getMessage(),
                'sync_action' => $syncAction,
            ];
        }
    }

    /**
     * @param array<string,mixed> $clientRefs
     * @param array<string,mixed> $payload
     */
    private function pushViaSiteTokenApi(
        Draft $draft,
        array $clientRefs,
        array $payload,
        string $correlationId,
        string $remoteDraftId
    ): array {
        $site = $draft->clientSite;
        $base = rtrim((string) ($site?->base_url ?: $site?->site_url), '/');
        $syncAction = $this->resolveSyncAction($draft, $clientRefs, $payload);
        if ($base === '') {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'Missing site URL for WordPress push.',
                'sync_action' => $syncAction,
            ];
        }

        $token = $this->resolveOutboundSiteToken($draft);
        if ($token === '') {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'Missing outbound site token. Regenerate the site key and reconnect the WordPress plugin.',
                'sync_action' => $syncAction,
            ];
        }

        $targetWpPostId = trim((string) ($payload['wp_post_id'] ?? ''));
        try {
            $post = $targetWpPostId !== ''
                ? $this->wordpressConnector()->forSite($base, $token)->updatePost($targetWpPostId, $payload)
                : $this->wordpressConnector()->forSite($base, $token)->createPost($payload);
        } catch (WordPressConnectorException $exception) {
            if ($syncAction === 'create') {
                $recovered = $this->resolveExistingRemotePostForPayload($draft, $clientRefs, $payload);
                if (($recovered['id'] ?? null) !== null) {
                    return [
                        'ok' => true,
                        'status' => $exception->statusCode(),
                        'body' => null,
                        'error' => null,
                        'wp_post_id' => (string) $recovered['id'],
                        'published_url' => $recovered['published_url'] ?? null,
                        'sync_action' => 'update',
                        'recovered_existing' => true,
                        'recovery_reason' => $recovered['reason'] ?? 'recovered_by_meta_lookup',
                    ];
                }
            }

            return [
                'ok' => false,
                'status' => $exception->statusCode(),
                'body' => null,
                'error' => $exception->getMessage(),
                'sync_action' => $syncAction,
            ];
        }

        $this->syncLocalWordPressIdentifiersFromConnectorPost($draft, $post, $targetWpPostId);
        $body = json_encode($post->toArray(), JSON_UNESCAPED_SLASHES);

        $this->logSyncAttempt($draft, [
            'correlation_id' => $correlationId,
            'remote_draft_id' => $remoteDraftId,
            'action' => $syncAction,
            'channel' => 'direct_api',
            'result' => 'ok',
            'http_status' => $post->httpStatus,
        ], 'info');

        return [
            'ok' => true,
            'status' => $post->httpStatus,
            'body' => $body !== false ? $body : null,
            'error' => null,
            'sync_action' => $syncAction,
        ];
    }

    public function markDelivered(Draft $draft, bool $acked = true): void
    {
        // Update draft delivery state (per-draft tracking)
        $draft->loadMissing('content', 'clientSite');

        $draft->delivery_status = 'delivered';
        $draft->delivered_at = now();

        if ($acked) {
            $draft->acked_at = now();
        }

        $draft->delivery_last_error = null;
        $draft->save();

        // When connector orchestration is active, ContentPublicationService handles Content updates
        if (! $this->suppressPublicationWrites && $draft->content) {
            $freshContent = Content::query()->find($draft->content->id);
            if ($freshContent) {
            // Legacy path: direct Content updates (for non-connector flows)
                $contentUpdates = [
                    'publish_error' => null,
                    'delivery_status' => 'delivered',
                    'status' => in_array($freshContent->status, ['brief', 'draft', 'review', 'approved', 'ready_to_deliver'], true)
                        ? 'published'
                        : $freshContent->status,
                ];

                $freshContent->forceFill($contentUpdates)->save();
                $draft->setRelation('content', $freshContent->fresh());
            }
        }

        // Record delivery event based on whether this was a create or update
        if ($this->currentPublication) {
            $eventType = $this->currentPublication->hasRemoteId()
                ? ContentDeliveryEvent::TYPE_UPDATE_REMOTE
                : ContentDeliveryEvent::TYPE_CREATE_REMOTE;

            $this->recordDeliveryEvent(
                $eventType,
                true,
                [],
                [],
                200,
                null,
                null
            );
        }

        ContentPushedToWordPress::dispatch((string) $draft->id);

        Event::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'client_site_id' => $draft->client_site_id,
            'type' => 'publish.synced',
            'occurred_at' => now(),
            'data' => [
                'draft_id' => $draft->id,
                'content_id' => $draft->content_id,
                'acked' => $acked,
                'publication_id' => $this->currentPublication?->id,
            ],
        ]);
    }

    public function markFailed(Draft $draft, string $error): void
    {
        // Separate status concerns:
        // - delivery_status: tracks remote delivery state (only this is set to 'failed')
        // - status: tracks PublishLayer content lifecycle (remains unchanged)
        //
        // Failed delivery should NOT corrupt the content's internal state.
        // The content remains valid in PublishLayer even when remote delivery fails.
        $draft->loadMissing('content');
        $draft->delivery_status = 'failed';
        $draft->delivery_last_error = $error;
        $draft->save();

        if ($draft->content) {
            // Only update delivery_status, not the content lifecycle status
            if (! $this->suppressPublicationWrites) {
                $draft->content->update([
                    'delivery_status' => 'failed',
                ]);
            }

            $match = [
                'content_id' => $draft->content->id,
                'client_site_id' => $draft->content->client_site_id ?: $draft->client_site_id,
                'target_type' => 'wp',
            ];
            $existingTarget = ContentPublishTarget::query()->where($match)->first();
            $existingMeta = is_array($existingTarget?->meta) ? $existingTarget->meta : [];
            $existingSeoSync = is_array(data_get($existingMeta, 'seo_sync')) ? data_get($existingMeta, 'seo_sync') : [];
            $existingSeoSync['status'] = 'failed';
            $existingSeoSync['error'] = Str::limit(trim((string) $error), 2000, '');
            $existingSeoSync['recorded_at'] = now()->toIso8601String();
            $existingMeta['seo_sync'] = $existingSeoSync;

            ContentPublishTarget::query()->updateOrCreate(
                $match,
                [
                    'target_identifier' => $existingTarget?->target_identifier
                        ?: $draft->content->wp_post_id
                        ?: $draft->content->external_key
                        ?: (string) $draft->content->id,
                    'wp_post_id' => $existingTarget?->wp_post_id ?: $draft->content->wp_post_id,
                    'sync_status' => 'failed',
                    'seo_sync_status' => 'failed',
                    'seo_synced_at' => null,
                    'seo_sync_mode' => $existingTarget?->seo_sync_mode,
                    'seo_sync_error' => Str::limit(trim((string) $error), 2000, ''),
                    'meta' => $existingMeta,
                ]
            );
        }

        // Record failure in publication model and event log
        $this->updatePublicationOnFailure('delivery_failed', $error);
        $this->recordDeliveryEvent(
            ContentDeliveryEvent::TYPE_FAIL_REMOTE,
            false,
            [],
            [],
            null,
            null,
            $error
        );

        DraftDeliveryFailed::dispatch((string) $draft->id, $error);
    }

    public function markPendingRetry(Draft $draft): void
    {
        // Handig als je retry policy hebt: zet terug naar pending
        $draft->delivery_status = 'pending';
        $draft->save();
    }

    /**
     * Mark a draft as partially successful - post was delivered but with post-processing warnings.
     *
     * @param array<string, mixed> $errors Post-processing errors that occurred
     */
    public function markPartialSuccess(Draft $draft, ?string $wpPostId = null, string $warning = '', array $errors = []): void
    {
        $draft->loadMissing('content');
        $draft->delivery_status = 'partial_success';
        $draft->delivered_at = now();
        $draft->acked_at = now();

        // Store the warning in a way that distinguishes it from a failure
        $warningMessage = $warning !== ''
            ? $warning
            : 'Post published successfully but some post-processing steps failed.';
        $draft->delivery_last_error = '[PARTIAL SUCCESS] ' . $warningMessage;

        $draft->save();

        // When connector orchestration is active, ContentPublicationService handles Content updates
        if (! $this->suppressPublicationWrites) {
            // Sync wp_post_id if provided (legacy path only)
            if ($wpPostId !== null && trim($wpPostId) !== '' && $draft->content) {
                $this->persistWpPostId($draft->content, $wpPostId, $draft, 'partial_success_sync');
            }

            if ($draft->content) {
                $contentUpdates = [
                    'delivery_status' => 'partial_success',
                    'status' => in_array($draft->content->status, ['brief', 'draft', 'review', 'approved', 'ready_to_deliver'], true)
                        ? 'published'
                        : $draft->content->status,
                ];

                $draft->content->update($contentUpdates);
            }
        }

        // Update publication record
        $this->updatePublicationOnPartialSuccess($wpPostId, $warningMessage, $errors);

        $this->recordDeliveryEvent(
            ContentDeliveryEvent::TYPE_CREATE_REMOTE,
            true,
            [],
            ['partial_success' => true, 'warning' => $warningMessage, 'errors' => $errors],
        );

        Log::info('draft_delivery.partial_success', [
            'draft_id' => (string) $draft->id,
            'content_id' => (string) ($draft->content_id ?? ''),
            'client_site_id' => (string) ($draft->client_site_id ?? ''),
            'wp_post_id' => $wpPostId,
            'warning' => $warningMessage,
            'errors' => $errors,
        ]);
    }

    /**
     * Mark a draft as needing verification - delivery result is uncertain.
     */
    public function markNeedsVerification(Draft $draft, ?string $wpPostId = null, string $reason = ''): void
    {
        $draft->loadMissing('content');
        $draft->delivery_status = 'needs_verification';

        $reasonMessage = $reason !== ''
            ? $reason
            : 'Delivery result uncertain. The post may or may not exist on WordPress.';
        $draft->delivery_last_error = '[NEEDS VERIFICATION] ' . $reasonMessage;

        $draft->save();

        // When connector orchestration is active, ContentPublicationService handles Content updates
        if (! $this->suppressPublicationWrites && $draft->content) {
            // Sync wp_post_id if provided (legacy path only)
            if ($wpPostId !== null && trim($wpPostId) !== '') {
                $this->persistWpPostId($draft->content, $wpPostId, $draft, 'needs_verification_sync');
            }

            $draft->content->update([
                'delivery_status' => 'needs_verification',
            ]);
        }

        $this->recordDeliveryEvent(
            ContentDeliveryEvent::TYPE_FAIL_REMOTE,
            false,
            [],
            ['needs_verification' => true, 'reason' => $reasonMessage, 'wp_post_id' => $wpPostId],
        );

        Log::warning('draft_delivery.needs_verification', [
            'draft_id' => (string) $draft->id,
            'content_id' => (string) ($draft->content_id ?? ''),
            'client_site_id' => (string) ($draft->client_site_id ?? ''),
            'wp_post_id' => $wpPostId,
            'reason' => $reasonMessage,
        ]);
    }

    /**
     * Update publication record on partial success.
     *
     * @param array<string, mixed> $errors
     */
    private function updatePublicationOnPartialSuccess(?string $wpPostId, string $warning, array $errors = []): void
    {
        if (! $this->shouldPersistPublication()) {
            return;
        }

        $meta = is_array($this->currentPublication->meta) ? $this->currentPublication->meta : [];
        $meta['partial_success'] = true;
        $meta['partial_success_warning'] = $warning;
        $meta['partial_success_errors'] = $errors;
        $meta['partial_success_at'] = now()->toIso8601String();

        $this->currentPublication->forceFill([
            'delivery_status' => 'partial_success',
            'remote_id' => $wpPostId ?? $this->currentPublication->remote_id,
            'last_delivered_at' => now(),
            'meta' => $meta,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $clientRefs
     * @param  array<string, mixed>  $payload
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function normalizePayloadForRemoteWordPressState(Draft $draft, array $clientRefs, array $payload): array
    {
        $targetWpPostId = trim((string) ($payload['wp_post_id'] ?? $clientRefs['wp_post_id'] ?? ''));
        if ($targetWpPostId === '') {
            return [$clientRefs, $payload];
        }

        // PublishLayer stays authoritative for the mapping: missing remote posts are recreated.
        $remotePost = $this->checkRemoteWordPressPost($draft, $targetWpPostId);
        if (($remotePost['missing'] ?? false) !== true) {
            return [$clientRefs, $payload];
        }

        return $this->prepareRecreatePayloadAfterMissingRemote(
            $draft,
            $clientRefs,
            $payload,
            $targetWpPostId,
            $remotePost
        );
    }

    /**
     * @param  array<string, mixed>  $clientRefs
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $remotePost
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function prepareRecreatePayloadAfterMissingRemote(
        Draft $draft,
        array $clientRefs,
        array $payload,
        string $missingWpPostId,
        array $remotePost = []
    ): array {
        $normalizedRefs = $this->forgetStaleWordPressIdentifiers($clientRefs, $missingWpPostId);
        $normalizedPayload = $payload;
        unset($normalizedPayload['wp_post_id']);

        $this->markMissingRemote(
            $draft,
            $missingWpPostId,
            isset($remotePost['status']) ? (int) $remotePost['status'] : null,
            $normalizedRefs
        );

        Log::notice('draft_delivery.remote_post_missing', [
            'draft_id' => (string) $draft->id,
            'content_id' => (string) ($draft->content_id ?? ''),
            'client_site_id' => (string) ($draft->client_site_id ?? ''),
            'missing_wp_post_id' => $missingWpPostId,
            'status' => $remotePost['status'] ?? null,
            'channel' => $remotePost['channel'] ?? null,
            'publication_id' => $this->currentPublication?->id,
        ]);

        // Store the previous remote ID for recreate event tracking
        if ($this->shouldPersistPublication()) {
            $meta = is_array($this->currentPublication->meta) ? $this->currentPublication->meta : [];
            $meta['pending_recreate_from'] = $missingWpPostId;
            $this->currentPublication->forceFill(['meta' => $meta])->save();
        }

        return [$normalizedRefs, $normalizedPayload];
    }

    /**
     * @return array{exists:bool,missing:bool,status:int|null,wp_post_id:?string,published_url:?string,channel:?string}
     */
    private function checkRemoteWordPressPost(Draft $draft, string $wpPostId): array
    {
        $site = $draft->clientSite;
        $base = rtrim((string) ($site?->base_url ?: $site?->site_url), '/');
        $token = $this->resolveOutboundSiteToken($draft);

        if ($base === '' || $token === '') {
            return [
                'exists' => false,
                'missing' => false,
                'status' => null,
                'wp_post_id' => null,
                'published_url' => null,
                'channel' => null,
            ];
        }

        try {
            $result = $this->wordpressConnector()
                ->forSite($base, $token)
                ->postExists($wpPostId)
                ->toArray();
        } catch (WordPressConnectorException $exception) {
            return [
                'exists' => false,
                'missing' => false,
                'status' => $exception->statusCode(),
                'wp_post_id' => null,
                'published_url' => null,
                'channel' => 'rest_api',
            ];
        }

        $result['channel'] = 'rest_api';

        return $result;
    }

    /**
     * @param  array<string, mixed>  $clientRefs
     * @return array<string, mixed>
     */
    private function forgetStaleWordPressIdentifiers(array $clientRefs, string $missingWpPostId): array
    {
        $missingWpPostId = trim($missingWpPostId);
        if ($missingWpPostId === '') {
            return $clientRefs;
        }

        unset($clientRefs['wp_post_id'], $clientRefs['wordpress_post_id']);

        foreach (['remote_draft_id', 'wp_draft_id'] as $key) {
            if (trim((string) ($clientRefs[$key] ?? '')) === $missingWpPostId) {
                unset($clientRefs[$key]);
            }
        }

        return $clientRefs;
    }

    /**
     * @param  array<string, mixed>  $normalizedRefs
     */
    public function markMissingRemote(Draft $draft, string $wpPostId, ?int $status = null, array $normalizedRefs = []): void
    {
        $draft->loadMissing('content');

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['client_refs'] = $normalizedRefs !== []
            ? $normalizedRefs
            : $this->forgetStaleWordPressIdentifiers(
                is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [],
                $wpPostId
            );

        $draft->meta = $meta;
        $draft->delivery_status = 'missing_remote';
        $draft->delivery_last_error = null;

        DB::transaction(function () use ($draft, $wpPostId, $status, $meta): void {
            $draft->forceFill([
                'meta' => $meta,
                'delivery_status' => 'missing_remote',
                'delivery_last_error' => null,
            ])->save();

            $content = $draft->content;
            if (! $content) {
                return;
            }

            if (! $this->suppressPublicationWrites) {
                $content->forceFill([
                    'delivery_status' => 'missing_remote',
                    'wp_post_id' => null,
                    'published_url' => null,
                ])->save();
            }

            $match = [
                'content_id' => $content->id,
                'client_site_id' => $content->client_site_id ?: $draft->client_site_id,
                'target_type' => 'wp',
            ];
            $existingTarget = ContentPublishTarget::query()->where($match)->first();
            $existingMeta = is_array($existingTarget?->meta) ? $existingTarget->meta : [];
            $previousWpPostIds = is_array($existingMeta['previous_wp_post_ids'] ?? null)
                ? $existingMeta['previous_wp_post_ids']
                : [];
            $previousWpPostIds[] = $wpPostId;
            $previousWpPostIds = array_values(array_unique(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                $previousWpPostIds
            ))));

            $existingMeta['previous_wp_post_ids'] = $previousWpPostIds;
            $existingMeta['remote_sync_status'] = 'missing_remote';
            $existingMeta['missing_remote_wp_post_id'] = $wpPostId;
            $existingMeta['missing_remote_detected_at'] = now()->toIso8601String();
            if ($status !== null) {
                $existingMeta['missing_remote_http_status'] = $status;
            }

            $targetIdentifier = trim((string) (
                $content->external_key
                ?: $content->id
                ?: $existingTarget?->target_identifier
            ));

            ContentPublishTarget::query()->updateOrCreate(
                $match,
                [
                    'target_identifier' => $targetIdentifier !== '' ? $targetIdentifier : null,
                    'wp_post_id' => null,
                    'sync_status' => 'missing_remote',
                    'seo_sync_status' => $existingTarget?->seo_sync_status,
                    'seo_synced_at' => $existingTarget?->seo_synced_at,
                    'seo_sync_mode' => $existingTarget?->seo_sync_mode,
                    'seo_sync_error' => $existingTarget?->seo_sync_error,
                    'seo_synced_fields' => $existingTarget?->seo_synced_fields,
                    'meta' => $existingMeta,
                ]
            );

            // Update publication record to reflect missing remote
            $this->updatePublicationOnMissingRemote($wpPostId);

            // Record verification failure event
            $this->recordDeliveryEvent(
                ContentDeliveryEvent::TYPE_VERIFY_REMOTE,
                false,
                [],
                ['http_status' => $status, 'missing_wp_post_id' => $wpPostId],
                $status,
                null,
                "Remote WordPress post {$wpPostId} not found"
            );
        });
    }

    /**
     * @param array<string, mixed> $clientRefs
     */
    private function resolveRemoteDraftId(Draft $draft, array $clientRefs): string
    {
        $wpPostId = trim((string) ($draft->content?->wp_post_id ?: ($clientRefs['wp_post_id'] ?? '')));
        if ($wpPostId !== '') {
            return $wpPostId;
        }

        $wpDraftId = trim((string) ($clientRefs['wp_draft_id'] ?? ''));
        if ($wpDraftId !== '') {
            return $wpDraftId;
        }

        $remoteDraftId = trim((string) ($clientRefs['remote_draft_id'] ?? ''));
        if ($remoteDraftId !== '') {
            return $remoteDraftId;
        }

        $contentId = trim((string) ($draft->content_id ?? ''));
        if ($contentId !== '') {
            return $contentId;
        }

        return (string) $draft->id;
    }

    /**
     * @param array<string,mixed> $clientRefs
     * @return array{0:?string,1:string}
     */
    private function resolveWebhookCredentials(Draft $draft, array $clientRefs): array
    {
        $url = trim((string) ($clientRefs['draft_webhook_url'] ?? $draft->clientSite?->draft_webhook_url ?? ''));
        $secret = trim((string) ($clientRefs['draft_webhook_secret'] ?? $draft->clientSite?->draft_webhook_secret ?? ''));
        $defaultSecret = trim((string) config('publishlayer.webhooks.secret', ''));

        if ($url !== '' && $secret === '' && $defaultSecret !== '') {
            $secret = $defaultSecret;
            Log::debug('draft_delivery.webhook_secret_fallback', [
                'draft_id' => (string) $draft->id,
                'site_id' => (string) $draft->client_site_id,
            ]);
        }

        if ($url !== '' && $secret !== '') {
            return [$url, $secret];
        }

        $endpoint = WebhookEndpoint::query()
            ->where('client_site_id', $draft->client_site_id)
            ->where('is_active', true)
            ->where('event_type', 'draft.ready')
            ->latest('created_at')
            ->first();

        if (! $endpoint) {
            $endpoint = WebhookEndpoint::query()
                ->where('client_site_id', $draft->client_site_id)
                ->where('is_active', true)
                ->latest('created_at')
                ->first();
        }

        if ($endpoint) {
            $url = $url !== '' ? $url : trim((string) $endpoint->url);
            $secret = $secret !== '' ? $secret : trim((string) $endpoint->secret);
        }

        if ($url !== '' && $secret === '' && $defaultSecret !== '') {
            $secret = $defaultSecret;
            Log::debug('draft_delivery.webhook_secret_fallback', [
                'draft_id' => (string) $draft->id,
                'site_id' => (string) $draft->client_site_id,
            ]);
        }

        return [$url !== '' ? $url : null, $secret];
    }

    private function resolveOutboundSiteToken(Draft $draft): string
    {
        return $this->resolveOutboundSiteTokenByClientSiteId((string) $draft->client_site_id);
    }

    private function resolveOutboundSiteTokenByClientSiteId(string $clientSiteId): string
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
     * Resolve the WordPress post type for this draft's content.
     *
     * Resolution order:
     * 1. Content's series content_type (if in a series)
     * 2. Content.type field mapping
     * 3. Draft output_type mapping
     * 4. Default to POST
     */
    private function resolveWordPressPostType(Draft $draft): WordPressPostType
    {
        // If content is loaded and has a series, use series post type
        if ($draft->content) {
            return $draft->content->wordPressPostType();
        }

        // Fall back to output_type mapping
        $outputType = trim((string) ($draft->output_type ?? ''));
        if ($outputType !== '') {
            return WordPressPostType::fromOutputType($outputType);
        }

        return WordPressPostType::POST;
    }

    private function wordpressConnector(): WordPressConnector
    {
        return $this->wordPressConnector ?? app(WordPressConnector::class);
    }

    private function resolveWordPressConnectorForContent(Content $content): ?WordPressConnector
    {
        $site = $content->clientSite;
        $base = rtrim((string) ($site?->base_url ?: $site?->site_url), '/');
        $token = $this->resolveOutboundSiteTokenByClientSiteId((string) $content->client_site_id);

        if ($base === '' || $token === '') {
            return null;
        }

        return $this->wordpressConnector()->forSite($base, $token);
    }

    private function formatRemoteDeliveryError(int $status, string $responseBody, string $fallback): string
    {
        $details = $this->extractRemoteErrorMessage($responseBody);

        return match ($status) {
            401 => 'WordPress rejected the request as unauthorized.'
                . ($details !== '' ? ' ' . $details : ''),
            403 => 'WordPress rejected the request as forbidden.'
                . ($details !== '' ? ' ' . $details : ''),
            404 => 'WordPress connector endpoint was not found.'
                . ($details !== '' ? ' ' . $details : ''),
            422 => 'WordPress rejected the payload as invalid.'
                . ($details !== '' ? ' ' . $details : ''),
            default => $details !== ''
                ? $fallback . ' ' . $details
                : $fallback . ' HTTP ' . $status . '.',
        };
    }

    private function extractRemoteErrorMessage(string $responseBody): string
    {
        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            $plain = trim(html_entity_decode(strip_tags($responseBody), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $plain = preg_replace('/\s+/', ' ', $plain) ?: $plain;

            return $plain !== '' ? Str::limit($plain, 500, '') : '';
        }

        foreach (['message', 'error', 'data.message', 'data.error'] as $path) {
            $value = trim((string) data_get($decoded, $path, ''));
            if ($value !== '') {
                return Str::limit($value, 500, '');
            }
        }

        $errors = data_get($decoded, 'errors');
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_string($error) && trim($error) !== '') {
                    return Str::limit(trim($error), 500, '');
                }

                if (is_array($error)) {
                    foreach ($error as $message) {
                        if (is_string($message) && trim($message) !== '') {
                            return Str::limit(trim($message), 500, '');
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Detect if the response indicates a partial success (post created but post-processing failed).
     *
     * @return array{is_partial: bool, wp_post_id: ?string, message: ?string, errors: array}
     */
    private function detectPartialSuccessFromResponse(int $status, string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            return ['is_partial' => false, 'wp_post_id' => null, 'message' => null, 'errors' => []];
        }

        // Check for explicit partial_success flag
        if (($decoded['partial_success'] ?? false) === true && ($decoded['ok'] ?? false) === true) {
            return [
                'is_partial' => true,
                'wp_post_id' => trim((string) ($decoded['wp_post_id'] ?? $decoded['post_id'] ?? '')),
                'message' => (string) ($decoded['message'] ?? 'Post published with warnings'),
                'errors' => is_array($decoded['post_processing_errors'] ?? null) ? $decoded['post_processing_errors'] : [],
            ];
        }

        // Check for 500 status with wp_post_id in response (post created, but something failed afterward)
        if ($status >= 500 && $status < 600) {
            $wpPostId = trim((string) ($decoded['wp_post_id'] ?? $decoded['post_id'] ?? ''));
            if ($wpPostId !== '' && is_numeric($wpPostId)) {
                return [
                    'is_partial' => true,
                    'wp_post_id' => $wpPostId,
                    'message' => 'WordPress returned an error after creating the post.',
                    'errors' => [['step' => 'post_processing', 'error' => $this->extractRemoteErrorMessage($responseBody)]],
                ];
            }
        }

        return ['is_partial' => false, 'wp_post_id' => null, 'message' => null, 'errors' => []];
    }

    /**
     * Check if a post may have been created despite an error response.
     * This attempts to extract wp_post_id from error responses including HTML.
     *
     * @return array{likely_created: bool, wp_post_id: ?string}
     */
    private function checkIfPostMayHaveBeenCreated(Draft $draft, int $status, string $responseBody): array
    {
        // Only check 5xx errors - these are server errors that might indicate partial success
        if ($status < 500 || $status >= 600) {
            return ['likely_created' => false, 'wp_post_id' => null];
        }

        // Try to extract wp_post_id from JSON response
        $decoded = json_decode($responseBody, true);
        if (is_array($decoded)) {
            $wpPostId = trim((string) ($decoded['wp_post_id'] ?? $decoded['post_id'] ?? ''));
            if ($wpPostId !== '' && is_numeric($wpPostId)) {
                return ['likely_created' => true, 'wp_post_id' => $wpPostId];
            }
        }

        // Try to extract wp_post_id from HTML error response
        // WordPress sometimes includes post ID in error pages (e.g., in debugging info)
        $extractedId = $this->extractWpPostIdFromHtmlError($responseBody);
        if ($extractedId !== null) {
            return ['likely_created' => true, 'wp_post_id' => $extractedId];
        }

        // Check if we have a known wp_post_id for this content and can verify it exists
        $draft->loadMissing('content');
        $existingWpPostId = trim((string) ($draft->content?->wp_post_id ?? ''));
        if ($existingWpPostId !== '') {
            // We already had a post - this might have been an update that partially succeeded
            // Mark as needs_verification so we can check the remote state
            return ['likely_created' => true, 'wp_post_id' => $existingWpPostId];
        }

        return ['likely_created' => false, 'wp_post_id' => null];
    }

    /**
     * Try to extract wp_post_id from an HTML error page.
     * WordPress error pages sometimes contain debugging info with the post ID.
     */
    private function extractWpPostIdFromHtmlError(string $html): ?string
    {
        // Look for patterns like "post_id=123" or "wp_post_id: 123" in the HTML
        $patterns = [
            '/post_id["\']?\s*[=:]\s*["\']?(\d+)/i',
            '/wp_post_id["\']?\s*[=:]\s*["\']?(\d+)/i',
            '/"post_id"\s*:\s*"?(\d+)"?/i',
            '/"wp_post_id"\s*:\s*"?(\d+)"?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $postId = $matches[1];
                if ((int) $postId > 0) {
                    return $postId;
                }
            }
        }

        return null;
    }

    private function syncLocalWordPressIdentifiersFromResponse(Draft $draft, string $responseBody, string $expectedWpPostId = ''): void
    {
        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            // Try to extract from HTML error response as fallback
            $extractedId = $this->extractWpPostIdFromHtmlError($responseBody);
            if ($extractedId !== null) {
                $this->syncLocalWordPressIdentifiers($draft, $extractedId, null, [], $expectedWpPostId);
            }

            return;
        }

        $wpPostId = trim((string) ($decoded['wp_post_id'] ?? $decoded['post_id'] ?? ''));
        if ($wpPostId === '') {
            return;
        }

        $this->syncLocalWordPressIdentifiers(
            $draft,
            $wpPostId,
            $this->extractPublishedUrlFromResponsePayload($decoded),
            $decoded,
            $expectedWpPostId
        );
    }

    private function syncLocalWordPressIdentifiersFromConnectorPost(
        Draft $draft,
        WordPressPost $post,
        string $expectedWpPostId = ''
    ): void {
        $this->syncLocalWordPressIdentifiers(
            $draft,
            $post->id,
            $post->publishedUrl,
            $post->raw,
            $expectedWpPostId
        );
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    private function syncLocalWordPressIdentifiers(
        Draft $draft,
        string $wpPostId,
        ?string $publishedUrl,
        array $responsePayload,
        string $expectedWpPostId = ''
    ): void {
        $expectedWpPostId = trim($expectedWpPostId);
        $isRecreate = false;
        $previousRemoteId = null;

        // Detect if this is a recreate scenario
        if ($this->shouldPersistPublication()) {
            $meta = is_array($this->currentPublication->meta) ? $this->currentPublication->meta : [];
            $previousRemoteId = $meta['pending_recreate_from'] ?? null;
            if ($previousRemoteId) {
                $isRecreate = true;
                // Clear the pending recreate marker
                unset($meta['pending_recreate_from']);
                $this->currentPublication->forceFill(['meta' => $meta])->save();
            }
        }

        if ($expectedWpPostId !== '' && $expectedWpPostId !== $wpPostId) {
            Log::warning('wp_post_id_changed_after_publish', [
                'draft_id' => (string) $draft->id,
                'content_id' => (string) ($draft->content_id ?? ''),
                'expected_wp_post_id' => $expectedWpPostId,
                'returned_wp_post_id' => $wpPostId,
                'is_recreate' => $isRecreate,
            ]);
        }

        $content = $draft->content()->first();
        if (! $content) {
            return;
        }

        $this->persistWpPostId($content, $wpPostId, $draft, 'direct_api_or_webhook', $publishedUrl);

        // Update the publication record with the new remote ID and post type
        $postType = $content->wordPressPostType()->value;
        $this->updatePublicationOnSuccess($wpPostId, $publishedUrl, null, $postType);

        // Record the appropriate event type
        if ($isRecreate && $previousRemoteId) {
            $this->recordDeliveryEvent(
                ContentDeliveryEvent::TYPE_RECREATE_REMOTE,
                true,
                [],
                $responsePayload,
                null,
                null,
                null,
                $previousRemoteId
            );
        }
    }

    private function persistWpPostId(
        Content $content,
        string $wpPostId,
        ?Draft $draft = null,
        ?string $source = null,
        ?string $publishedUrl = null
    ): void
    {
        $wpPostId = trim((string) $wpPostId);
        if ($wpPostId === '') {
            return;
        }

        DB::transaction(function () use ($content, $wpPostId, $draft, $source, $publishedUrl): void {
            if ($draft) {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
                $refs['wp_post_id'] = $wpPostId;
                $meta['client_refs'] = $refs;
                $draft->forceFill(['meta' => $meta])->save();
            }

            $normalizedPublishedUrl = trim((string) ($publishedUrl ?? ''));
            $contentUpdates = [
                'wp_post_id' => $wpPostId,
            ];
            if ($normalizedPublishedUrl !== '') {
                $contentUpdates['published_url'] = $normalizedPublishedUrl;
            }

            if (! $this->suppressPublicationWrites) {
                Content::query()
                    ->whereKey($content->id)
                    ->update($contentUpdates);
            }

            $match = [
                'content_id' => $content->id,
                'client_site_id' => $content->client_site_id,
                'target_type' => 'wp',
            ];
            $existingTarget = ContentPublishTarget::query()->where($match)->first();
            $existingMeta = is_array($existingTarget?->meta) ? $existingTarget->meta : [];
            $nextMeta = array_replace(
                $existingMeta,
                array_filter([
                    'wp_post_id' => $wpPostId,
                    'remote_sync_status' => 'synced',
                    'source' => $source,
                    'published_url' => $normalizedPublishedUrl !== '' ? $normalizedPublishedUrl : null,
                ], static fn ($value) => $value !== null && $value !== '')
            );

            ContentPublishTarget::query()->updateOrCreate(
                $match,
                [
                    'target_identifier' => $wpPostId,
                    'wp_post_id' => $wpPostId,
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                    'meta' => $nextMeta,
                ]
            );
        });

        $content->refresh();
    }

    private function lookupWpPostIdByExternalKey(Content $content, ?Draft $draft): string
    {
        $candidateKeys = array_values(array_unique(array_filter([
            trim((string) ($content->external_key ?? '')),
            trim((string) $content->id),
            trim((string) ($draft?->id ?? '')),
            trim((string) data_get($draft?->meta, 'client_refs.remote_draft_id', '')),
        ], static fn ($value) => $value !== '')));

        $connector = $this->resolveWordPressConnectorForContent($content);
        if (! $connector || $candidateKeys === []) {
            return '';
        }

        foreach ($candidateKeys as $candidateKey) {
            try {
                $post = $connector->findPostByMeta([
                    'external_key' => $candidateKey,
                    'publishlayer_draft_id' => (string) ($draft?->id ?? ''),
                    'pl_draft_id' => (string) ($draft?->id ?? ''),
                    'content_id' => (string) $content->id,
                    'pl_content_id' => (string) $content->id,
                ]);
            } catch (WordPressConnectorException) {
                continue;
            }

            if ($post) {
                return $post->id;
            }
        }

        return '';
    }

    private function findWpPostByPublishLayerId(Content $content, ?Draft $draft): string
    {
        $draftId = trim((string) ($draft?->id ?? ''));
        if ($draftId === '') {
            return '';
        }

        $connector = $this->resolveWordPressConnectorForContent($content);
        if (! $connector) {
            return '';
        }

        try {
            $post = $connector->findPostByMeta([
                'publishlayer_draft_id' => $draftId,
                'pl_draft_id' => $draftId,
                'draft_id' => $draftId,
                'content_id' => (string) $content->id,
                'pl_content_id' => (string) $content->id,
                'meta_key' => 'publishlayer_draft_id',
                'meta_value' => $draftId,
            ]);
        } catch (WordPressConnectorException) {
            return '';
        }

        return $post?->id ?? '';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logWpPostIdEnsure(Content $content, string $event, array $context = [], string $level = 'info'): void
    {
        Log::channel(config('logging.default'))->log($level, 'wp_post_id_ensure', array_merge([
            'event' => $event,
            'content_id' => (string) $content->id,
            'client_site_id' => (string) ($content->client_site_id ?? ''),
        ], $context));
    }

    /**
     * @param array<string,mixed>|mixed $payloadMeta
     * @param array<string,mixed> $resolvedSeo
     * @return array<string,mixed>
     */
    private function withPublishLayerMeta(mixed $payloadMeta, Draft $draft, array $resolvedSeo = []): array
    {
        $meta = is_array($payloadMeta) ? $payloadMeta : [];
        $seo = $resolvedSeo !== [] ? $resolvedSeo : SeoMetadata::resolveForDraftContext($draft, $meta);
        $contentMeta = $this->publishLayerContentMeta($draft);

        $meta['publishlayer_draft_id'] = (string) $draft->id;
        $meta['publishlayer_content_id'] = (string) ($draft->content_id ?? '');
        $meta['publishlayer_brief_id'] = (string) ($draft->brief_id ?? '');
        $meta['publishlayer_publication_id'] = (string) ($this->currentPublication?->id ?? '');
        $meta['publishlayer_origin'] = 'publishlayer';
        $meta['publishlayer_language'] = $draft->language->value;
        $meta['publishlayer_locale'] = $draft->language->value;
        $meta['publishlayer_destination_id'] = $this->publicationDestinationIdForDraft($draft);
        $meta['publishlayer_is_translation'] = $draft->isTranslation();
        $meta['publishlayer_source_draft_id'] = $draft->source_draft_id ? (string) $draft->source_draft_id : null;
        $meta['_publishlayer_content_id'] = (string) ($draft->content_id ?? '');
        $meta['_publishlayer_locale'] = $draft->language->value;
        $meta['_publishlayer_destination_id'] = $this->publicationDestinationIdForDraft($draft);
        $meta = array_merge($meta, $contentMeta);

        $nested = is_array($meta['publishlayer'] ?? null) ? $meta['publishlayer'] : [];
        $nested['draft_id'] = (string) $draft->id;
        $nested['content_id'] = (string) ($draft->content_id ?? '');
        $nested['brief_id'] = (string) ($draft->brief_id ?? '');
        $nested['publication_id'] = (string) ($this->currentPublication?->id ?? '');
        $nested['origin'] = 'publishlayer';
        $nested['language'] = $draft->language->value;
        $nested['locale'] = $draft->language->value;
        $nested['destination_id'] = $this->publicationDestinationIdForDraft($draft);
        $nested['is_translation'] = $draft->isTranslation();
        $nested['source_draft_id'] = $draft->source_draft_id ? (string) $draft->source_draft_id : null;
        $nested = array_merge($nested, $this->publishLayerNestedContentMeta($draft));
        $nested['seo'] = array_filter([
            'primary_keyword' => $seo['primary_keyword'] ?? null,
            'robots_index' => $seo['robots_index'] ?? null,
            'robots_follow' => $seo['robots_follow'] ?? null,
            'schema_type' => $seo['schema_type'] ?? null,
            'title' => $seo['seo_title'] ?? null,
            'meta_description' => $seo['seo_meta_description'] ?? null,
            'h1' => $seo['seo_h1'] ?? null,
            'canonical' => $seo['seo_canonical'] ?? null,
            'og_title' => $seo['seo_og_title'] ?? null,
            'og_description' => $seo['seo_og_description'] ?? null,
            'og_image' => $seo['seo_og_image'] ?? null,
            'twitter_title' => $seo['seo_twitter_title'] ?? null,
            'twitter_description' => $seo['seo_twitter_description'] ?? null,
        ], fn ($value) => $this->hasSeoValue($value));
        $meta['publishlayer'] = $nested;

        return $meta;
    }

    /**
     * Keep PublishLayer identifiers in WordPress post meta for stable lookup.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPublishLayerRemoteMeta(array $payload, Draft $draft): array
    {
        $publishLayerMeta = array_merge([
            'publishlayer_content_id' => (string) ($draft->content_id ?? ''),
            'publishlayer_publication_id' => (string) ($this->currentPublication?->id ?? ''),
            'publishlayer_origin' => 'publishlayer',
            'publishlayer_language' => $draft->language->value,
            'publishlayer_locale' => $draft->language->value,
            'publishlayer_destination_id' => $this->publicationDestinationIdForDraft($draft),
            'publishlayer_is_translation' => $draft->isTranslation() ? '1' : '0',
            'publishlayer_source_draft_id' => $draft->source_draft_id ? (string) $draft->source_draft_id : '',
            '_publishlayer_content_id' => (string) ($draft->content_id ?? ''),
            '_publishlayer_locale' => $draft->language->value,
            '_publishlayer_destination_id' => $this->publicationDestinationIdForDraft($draft),
        ], $this->publishLayerContentMeta($draft));

        $payload['meta_input'] = array_replace(
            is_array($payload['meta_input'] ?? null) ? $payload['meta_input'] : [],
            $publishLayerMeta
        );
        $payload['wp_post_meta'] = array_replace(
            is_array($payload['wp_post_meta'] ?? null) ? $payload['wp_post_meta'] : [],
            $publishLayerMeta
        );

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function publishLayerContentMeta(Draft $draft): array
    {
        $content = $draft->content;

        return [
            'publishlayer_external_key' => $this->publishLayerMetaString($content?->external_key),
            'publishlayer_origin_type' => $this->publishLayerMetaString($content?->origin_type?->value ?? $content?->origin_type),
            'publishlayer_source' => $this->publishLayerMetaString($content?->source?->value ?? $content?->source),
            'publishlayer_generation_mode' => $this->publishLayerMetaString($content?->generation_mode),
            'publishlayer_automation_id' => $this->publishLayerMetaString($content?->automation_id),
            'publishlayer_automation_run_id' => $this->publishLayerMetaString($content?->automation_run_id),
            'publishlayer_family_id' => $this->publishLayerMetaString($content?->family_id),
            'publishlayer_translation_source_content_id' => $this->publishLayerMetaString($content?->translation_source_content_id),
            'publishlayer_translation_source_locale' => $this->publishLayerMetaString($content?->translation_source_locale),
            'publishlayer_is_source_locale' => (bool) ($content?->is_source_locale ?? false) ? '1' : '0',
            'publishlayer_publish_url_key' => $this->publishLayerMetaString($content?->publish_url_key),
            'publishlayer_canonical_url_key' => $this->publishLayerMetaString($content?->canonical_url_key),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishLayerNestedContentMeta(Draft $draft): array
    {
        $content = $draft->content;

        return [
            'external_key' => $this->publishLayerNullableString($content?->external_key),
            'origin_type' => $this->publishLayerNullableString($content?->origin_type?->value ?? $content?->origin_type),
            'source' => $this->publishLayerNullableString($content?->source?->value ?? $content?->source),
            'generation_mode' => $this->publishLayerNullableString($content?->generation_mode),
            'automation_id' => $this->publishLayerNullableString($content?->automation_id),
            'automation_run_id' => $this->publishLayerNullableString($content?->automation_run_id),
            'family_id' => $this->publishLayerNullableString($content?->family_id),
            'translation_source_content_id' => $this->publishLayerNullableString($content?->translation_source_content_id),
            'translation_source_locale' => $this->publishLayerNullableString($content?->translation_source_locale),
            'is_source_locale' => (bool) ($content?->is_source_locale ?? false),
            'publish_url_key' => $this->publishLayerNullableString($content?->publish_url_key),
            'canonical_url_key' => $this->publishLayerNullableString($content?->canonical_url_key),
        ];
    }

    private function publishLayerMetaString(mixed $value): string
    {
        return $this->publishLayerNullableString($value) ?? '';
    }

    private function publishLayerNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveTargetWpStatus(Draft $draft): string
    {
        $publishStatus = strtolower(trim((string) ($draft->content?->publish_status ?? '')));
        if (in_array($publishStatus, ['scheduled', 'publishing', 'published'], true)) {
            return 'publish';
        }

        $contentStatus = strtolower(trim((string) ($draft->content?->status ?? '')));
        if ($contentStatus === 'published') {
            return 'publish';
        }

        return 'draft';
    }

    /**
     * @param array<string,mixed>|mixed $payloadMeta
     * @return array<string,mixed>
     */
    private function resolveSeoPayload(Draft $draft, mixed $payloadMeta, string $payloadHtml): array
    {
        $meta = is_array($payloadMeta) ? $payloadMeta : [];
        $content = $draft->content;

        $seo = SeoMetadata::resolveForDraftContext(
            $draft,
            $meta,
            [
                'seo_og_image' => $this->resolveOgImageUrl($draft),
                'seo_canonical' => $content?->published_url,
            ],
        );

        if (trim((string) ($seo['seo_title'] ?? '')) === '') {
            $seo['seo_title'] = $draft->title ?: ($content?->title ?: null);
        }

        if (trim((string) ($seo['seo_h1'] ?? '')) === '') {
            $seo['seo_h1'] = $seo['seo_title'];
        }

        if (trim((string) ($seo['seo_meta_description'] ?? '')) === '') {
            $seo['seo_meta_description'] = Str::limit(trim(strip_tags($payloadHtml)), 320, '');
        }

        return $seo;
    }

    /**
     * @param array<string,mixed>|mixed $payloadMeta
     */
    private function resolvePayloadSlug(Draft $draft, mixed $payloadMeta): string
    {
        $meta = is_array($payloadMeta) ? $payloadMeta : [];
        $slug = trim((string) SeoMetadata::firstNonEmpty([
            data_get($meta, 'slug'),
            data_get($meta, 'seo.slug'),
            data_get($meta, 'client_refs.slug'),
            $draft->content?->external_key,
            Str::slug((string) $draft->title),
            (string) $draft->id,
        ]));

        return Str::slug($slug !== '' ? $slug : (string) $draft->id);
    }

    /**
     * @param array<string,mixed>|mixed $payloadMeta
     */
    private function resolvePayloadExcerpt(mixed $payloadMeta, string $payloadHtml): string
    {
        $meta = is_array($payloadMeta) ? $payloadMeta : [];

        $excerpt = SeoMetadata::firstNonEmpty([
            data_get($meta, 'excerpt'),
            data_get($meta, 'summary'),
            data_get($meta, 'meta_description'),
        ]);
        if ($excerpt !== null && $excerpt !== '') {
            return Str::limit($excerpt, 320, '');
        }

        return Str::limit(trim(strip_tags($payloadHtml)), 220, '');
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $seo
     * @return array<string,mixed>
     */
    private function applySeoSyncPayload(Draft $draft, array $payload, array $seo): array
    {
        $site = $draft->clientSite;
        if (! $site || ClientSite::normalizeType((string) $site->type) !== ClientSite::TYPE_WORDPRESS) {
            return $payload;
        }

        $provider = app(SeoProviderRegistry::class)->resolve((string) ($site->seo_provider ?? 'none'));
        $providerSupportsSync = $provider->supportsMetaTitle()
            || $provider->supportsMetaDescription()
            || $provider->supportsCanonical()
            || $provider->supportsOgTags();
        $supportsSync = (bool) (
            $site->supports_meta_title
            || $site->supports_meta_description
            || $site->supports_canonical
            || $site->supports_og_tags
            || $providerSupportsSync
        );
        $syncableFields = $provider->syncableFieldKeys();

        if (! $supportsSync) {
            $payload['seo_recommendations'] = array_filter($seo, fn ($value) => $this->hasSeoValue($value));
            foreach ([
                'seo_title',
                'seo_meta_description',
                'seo_h1',
                'seo_canonical',
                'seo_og_title',
                'seo_og_description',
                'seo_og_image',
                'seo_twitter_title',
                'seo_twitter_description',
                'primary_keyword',
                'robots_index',
                'robots_follow',
                'schema_type',
                'meta_title',
                'meta_description',
                'canonical_url',
                'og_image',
            ] as $key) {
                unset($payload[$key]);
            }
            $payload['seo_sync'] = [
                'mode' => 'advisory',
                'provider' => $provider->key(),
                'supports_meta_title' => (bool) $site->supports_meta_title,
                'supports_meta_description' => (bool) $site->supports_meta_description,
                'supports_canonical' => (bool) $site->supports_canonical,
                'supports_og_tags' => (bool) $site->supports_og_tags,
                'syncable_fields' => $syncableFields,
                'reason' => 'seo_plugin_not_supported',
            ];

            return $payload;
        }

        $mappedMeta = $provider->mapToWordPressMeta($seo);
        if ($mappedMeta === []) {
            $payload['seo_recommendations'] = array_filter($seo, fn ($value) => $this->hasSeoValue($value));
            foreach ([
                'seo_title',
                'seo_meta_description',
                'seo_h1',
                'seo_canonical',
                'seo_og_title',
                'seo_og_description',
                'seo_og_image',
                'seo_twitter_title',
                'seo_twitter_description',
                'primary_keyword',
                'robots_index',
                'robots_follow',
                'schema_type',
                'meta_title',
                'meta_description',
                'canonical_url',
                'og_image',
            ] as $key) {
                unset($payload[$key]);
            }
            $payload['seo_sync'] = [
                'mode' => 'advisory',
                'provider' => $provider->key(),
                'supports_meta_title' => (bool) $site->supports_meta_title,
                'supports_meta_description' => (bool) $site->supports_meta_description,
                'supports_canonical' => (bool) $site->supports_canonical,
                'supports_og_tags' => (bool) $site->supports_og_tags,
                'syncable_fields' => $syncableFields,
                'reason' => 'provider_mapping_not_available',
            ];

            return $payload;
        }

        $payload['meta_input'] = $mappedMeta;
        $payload['wp_post_meta'] = $mappedMeta;
        $payload['seo_sync'] = [
            'mode' => 'sync',
            'provider' => $provider->key(),
            'supports_meta_title' => (bool) $site->supports_meta_title,
            'supports_meta_description' => (bool) $site->supports_meta_description,
            'supports_canonical' => (bool) $site->supports_canonical,
            'supports_og_tags' => (bool) $site->supports_og_tags,
            'syncable_fields' => $syncableFields,
            'mapped_fields' => array_keys($mappedMeta),
        ];

        return $payload;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function extractPublishedUrlFromResponsePayload(array $decoded): ?string
    {
        foreach (['published_url', 'url', 'permalink', 'link', 'data.url'] as $path) {
            $value = trim((string) data_get($decoded, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeOutgoingHtml(string $html): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($html));

        return preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
    }

    /**
     * @param array<string,mixed> $clientRefs
     */
    private function resolveSyncAction(Draft $draft, array $clientRefs, array $payload = []): string
    {
        $hasRemoteId = trim((string) ($clientRefs['remote_draft_id'] ?? '')) !== ''
            || trim((string) ($payload['wp_post_id'] ?? '')) !== ''
            || trim((string) ($this->currentPublication?->remote_id ?? '')) !== '';
        $hasWpIds = trim((string) ($clientRefs['wp_draft_id'] ?? '')) !== ''
            || trim((string) ($draft->content?->wp_post_id ?? ($clientRefs['wp_post_id'] ?? ''))) !== '';

        return ($hasRemoteId || $hasWpIds) ? 'update' : 'create';
    }

    /**
     * @param  array<string,mixed>  $clientRefs
     * @param  array<string,mixed>  $payload
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array{id:?string,published_url:?string,reason:?string}}
     */
    private function alignPayloadWithResolvedRemotePost(Draft $draft, array $clientRefs, array $payload): array
    {
        $resolved = $this->resolveExistingRemotePostForPayload($draft, $clientRefs, $payload);
        if (($resolved['id'] ?? null) === null) {
            return [$clientRefs, $payload, $resolved];
        }

        $resolvedId = (string) $resolved['id'];
        $clientRefs['wp_post_id'] = $resolvedId;
        $clientRefs['remote_draft_id'] = $resolvedId;
        $payload['wp_post_id'] = $resolvedId;
        $payload['id'] = $resolvedId;

        Log::info('publication.wordpress.remote_resolved', [
            'publication_id' => (string) ($this->currentPublication?->id ?? ''),
            'draft_id' => (string) $draft->id,
            'content_id' => (string) ($draft->content_id ?? ''),
            'destination_id' => $this->publicationDestinationIdForDraft($draft),
            'locale' => $this->publicationLocaleValue($draft),
            'remote_post_id' => $resolvedId,
            'reason' => $resolved['reason'] ?? null,
        ]);

        return [$clientRefs, $payload, $resolved];
    }

    /**
     * @param  array<string,mixed>  $clientRefs
     * @param  array<string,mixed>  $payload
     * @return array{id:?string,published_url:?string,reason:?string}
     */
    private function resolveExistingRemotePostForPayload(Draft $draft, array $clientRefs, array $payload): array
    {
        $content = $draft->content ?: $draft->content()->first();
        if (! $content) {
            return ['id' => null, 'published_url' => null, 'reason' => null];
        }

        $connector = $this->resolveWordPressConnectorForContent($content);
        if (! $connector) {
            return ['id' => null, 'published_url' => null, 'reason' => null];
        }

        foreach ($this->candidateRemotePostIds($draft, $clientRefs, $payload) as $candidateId) {
            try {
                $post = $connector->getPost($candidateId);

                return [
                    'id' => $post->id,
                    'published_url' => $post->publishedUrl,
                    'reason' => 'existing_mapping',
                ];
            } catch (WordPressConnectorException) {
                continue;
            }
        }

        try {
            $post = $connector->findPostByMeta($this->stableRemoteLookupCriteria($draft));
        } catch (WordPressConnectorException) {
            $post = null;
        }

        if ($post) {
            return [
                'id' => $post->id,
                'published_url' => $post->publishedUrl,
                'reason' => 'recovered_by_meta_lookup',
            ];
        }

        return ['id' => null, 'published_url' => null, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $clientRefs
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function candidateRemotePostIds(Draft $draft, array $clientRefs, array $payload): array
    {
        $candidates = [
            trim((string) ($this->currentPublication?->remote_id ?? '')),
            trim((string) ($payload['wp_post_id'] ?? '')),
            trim((string) ($draft->content?->wp_post_id ?? '')),
            trim((string) ($clientRefs['wp_post_id'] ?? '')),
            trim((string) ($clientRefs['remote_draft_id'] ?? '')),
            trim((string) ($clientRefs['wp_draft_id'] ?? '')),
        ];

        return array_values(array_unique(array_filter($candidates, static fn ($value) => $value !== '')));
    }

    /**
     * @return array<string,string>
     */
    private function stableRemoteLookupCriteria(Draft $draft): array
    {
        $criteria = [
            'publishlayer_content_id' => (string) ($draft->content_id ?? ''),
            'publishlayer_locale' => $this->publicationLocaleValue($draft),
            'publishlayer_destination_id' => $this->publicationDestinationIdForDraft($draft),
        ];

        if ($this->currentPublication) {
            $criteria['publishlayer_publication_id'] = (string) $this->currentPublication->id;
        }

        if ($draft->content?->external_key) {
            $criteria['external_key'] = (string) $draft->content->external_key;
        }

        return array_filter($criteria, static fn ($value) => trim((string) $value) !== '');
    }

    private function publicationLocaleValue(Draft $draft): string
    {
        return strtolower(trim((string) ($draft->language->value ?? $draft->content?->language ?? '')));
    }

    private function publicationDestinationIdForDraft(Draft $draft): string
    {
        return trim((string) (
            $this->currentPublication?->destination_id
            ?? $draft->content?->content_destination_id
            ?? $draft->client_site_id
            ?? ''
        ));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logSyncAttempt(Draft $draft, array $context, string $level = 'info'): void
    {
        if (! (bool) config('publishlayer.wp_connector.sync_debug', false)) {
            return;
        }

        $siteFilter = trim((string) config('publishlayer.wp_connector.sync_debug_site_id', ''));
        $siteId = (string) ($draft->client_site_id ?? '');
        if ($siteFilter !== '' && $siteFilter !== $siteId) {
            return;
        }

        $payload = array_merge([
            'local_draft_id' => (string) $draft->id,
            'local_content_id' => (string) ($draft->content_id ?? ''),
            'site_id' => $siteId,
        ], $context);

        Log::channel(config('logging.default'))->log($level, 'wp_sync_attempt', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $result
     */
    private function recordSeoSyncForPublishTarget(Draft $draft, array $payload, array $result): void
    {
        $content = $draft->content ?: $draft->content()->first();
        if (! $content) {
            return;
        }

        $match = [
            'content_id' => $content->id,
            'client_site_id' => $content->client_site_id ?: $draft->client_site_id,
            'target_type' => 'wp',
        ];
        if (trim((string) ($match['client_site_id'] ?? '')) === '') {
            return;
        }

        $existingTarget = ContentPublishTarget::query()->where($match)->first();
        $existingMeta = is_array($existingTarget?->meta) ? $existingTarget->meta : [];
        $seoSync = is_array($payload['seo_sync'] ?? null) ? $payload['seo_sync'] : [];
        $mode = $this->normalizeSeoSyncMode((string) ($seoSync['mode'] ?? ''));
        $provider = trim((string) ($seoSync['provider'] ?? $draft->clientSite?->seo_provider ?? 'none')) ?: 'none';
        $mappedFields = $this->normalizeSeoSyncedFields((array) ($seoSync['mapped_fields'] ?? []));
        $syncableFields = $this->normalizeSeoSyncedFields((array) ($seoSync['syncable_fields'] ?? []));
        $reason = trim((string) ($seoSync['reason'] ?? ''));
        $ok = (bool) ($result['ok'] ?? false);
        $httpStatus = isset($result['status']) ? (int) $result['status'] : null;
        $resultError = trim((string) ($result['error'] ?? ''));
        $status = $this->resolveSeoSyncStatus($mode, $ok);
        $syncError = $this->resolveSeoSyncError($status, $mode, $reason, $resultError, $httpStatus);
        $now = now();

        $attempt = array_filter([
            'status' => $status,
            'mode' => $mode,
            'provider' => $provider,
            'mapped_fields' => $mappedFields,
            'syncable_fields' => $syncableFields,
            'reason' => $reason !== '' ? $reason : null,
            'error' => $syncError,
            'http_status' => $httpStatus,
            'ok' => $ok,
            'recorded_at' => $now->toIso8601String(),
        ], static fn ($value) => $value !== null && (! is_array($value) || $value !== []));

        $attempts = data_get($existingMeta, 'seo_sync_attempts', []);
        if (! is_array($attempts)) {
            $attempts = [];
        }
        $attempts[] = $attempt;
        if (count($attempts) > 25) {
            $attempts = array_slice($attempts, -25);
        }

        $existingMeta['seo_sync'] = array_filter([
            'status' => $status,
            'mode' => $mode,
            'provider' => $provider,
            'mapped_fields' => $mappedFields,
            'syncable_fields' => $syncableFields,
            'reason' => $reason !== '' ? $reason : null,
            'error' => $syncError,
            'http_status' => $httpStatus,
            'recorded_at' => $now->toIso8601String(),
        ], static fn ($value) => $value !== null && (! is_array($value) || $value !== []));
        $existingMeta['seo_sync_attempts'] = $attempts;

        $targetIdentifier = trim((string) (
            $existingTarget?->target_identifier
            ?: $content->wp_post_id
            ?: data_get($payload, 'wp_post_id')
            ?: $content->external_key
            ?: $content->id
        ));
        $wpPostId = trim((string) (
            $existingTarget?->wp_post_id
            ?: $content->wp_post_id
            ?: data_get($payload, 'wp_post_id')
        ));

        ContentPublishTarget::query()->updateOrCreate(
            $match,
            [
                'target_identifier' => $targetIdentifier !== '' ? $targetIdentifier : null,
                'wp_post_id' => $wpPostId !== '' ? $wpPostId : null,
                'sync_status' => $ok ? 'synced' : 'failed',
                'last_synced_at' => $ok ? $now : $existingTarget?->last_synced_at,
                'seo_sync_status' => $status,
                'seo_synced_at' => $status === 'failed' ? null : $now,
                'seo_sync_mode' => $mode,
                'seo_sync_error' => $syncError,
                'seo_synced_fields' => $mappedFields !== [] ? $mappedFields : null,
                'meta' => $existingMeta,
            ]
        );
    }

    private function normalizeSeoSyncMode(string $mode): string
    {
        $normalized = trim(strtolower($mode));

        return in_array($normalized, ['sync', 'advisory', 'skipped'], true)
            ? $normalized
            : 'skipped';
    }

    private function resolveSeoSyncStatus(string $mode, bool $deliveryOk): string
    {
        if (! $deliveryOk) {
            return 'failed';
        }

        return match ($mode) {
            'sync' => 'synced',
            'advisory' => 'advisory',
            default => 'skipped',
        };
    }

    private function resolveSeoSyncError(
        string $status,
        string $mode,
        string $reason,
        string $resultError,
        ?int $httpStatus
    ): ?string {
        if ($status === 'failed') {
            if ($resultError !== '') {
                return Str::limit($resultError, 2000, '');
            }

            if ($reason !== '') {
                return Str::limit($reason, 2000, '');
            }

            return $httpStatus !== null ? ('HTTP ' . $httpStatus) : 'unknown_error';
        }

        if (in_array($mode, ['advisory', 'skipped'], true) && $reason !== '') {
            return Str::limit($reason, 2000, '');
        }

        return null;
    }

    /**
     * @param array<int,mixed> $fields
     * @return array<int,string>
     */
    private function normalizeSeoSyncedFields(array $fields): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(
            static fn ($field) => trim((string) $field),
            $fields
        ), static fn ($field) => $field !== ''))));
    }

    private function resolveFeaturedImageUrl(Draft $draft): ?string
    {
        $image = $draft->content?->featuredImage;
        if (! $image || $image->status !== 'ready') {
            return null;
        }

        $url = $image->getWordPressUploadUrl($draft->clientSite);

        return $url !== '' ? $url : null;
    }

    private function resolveOgImageUrl(Draft $draft): ?string
    {
        $image = $draft->content?->ogImage;
        if (! $image || $image->status !== 'ready') {
            return null;
        }

        $url = $image->getWordPressUploadUrl($draft->clientSite);

        return $url !== '' ? $url : null;
    }

    private function hasSeoValue(mixed $value): bool
    {
        return is_bool($value) || trim((string) $value) !== '';
    }

    // =========================================================================
    // Publication Management Methods
    // =========================================================================

    /**
     * Resolve or create the ContentPublication record for a draft's delivery.
     */
    private function resolvePublicationForDraft(Draft $draft): ?ContentPublication
    {
        $content = $draft->content;
        if (! $content) {
            return null;
        }

        $destinationId = $content->content_destination_id;
        $clientSiteId = $draft->client_site_id ?: $content->client_site_id;

        return ContentPublication::resolveForDelivery(
            $content->id,
            $destinationId,
            $clientSiteId,
            ContentPublication::PROVIDER_WORDPRESS,
            $this->publicationLocaleValue($draft),
        );
    }

    /**
     * Calculate delivery duration in milliseconds.
     */
    private function getDeliveryDurationMs(): ?int
    {
        if ($this->deliveryStartTime === null) {
            return null;
        }

        return (int) round((microtime(true) - $this->deliveryStartTime) * 1000);
    }

    /**
     * Record a delivery event for the current publication.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    private function recordDeliveryEvent(
        string $eventType,
        bool $success,
        array $payload = [],
        array $response = [],
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?string $errorMessage = null,
        ?string $previousRemoteId = null
    ): void {
        if (! $this->shouldPersistPublication()) {
            return;
        }

        $durationMs = $this->getDeliveryDurationMs();

        match ($eventType) {
            ContentDeliveryEvent::TYPE_CREATE_REMOTE => ContentDeliveryEvent::recordCreate(
                $this->currentPublication,
                $payload,
                $response,
                $httpStatus,
                $correlationId,
                $durationMs
            ),
            ContentDeliveryEvent::TYPE_UPDATE_REMOTE => ContentDeliveryEvent::recordUpdate(
                $this->currentPublication,
                $payload,
                $response,
                $httpStatus,
                $correlationId,
                $durationMs
            ),
            ContentDeliveryEvent::TYPE_RECREATE_REMOTE => ContentDeliveryEvent::recordRecreate(
                $this->currentPublication,
                $previousRemoteId ?? '',
                $payload,
                $response,
                $httpStatus,
                $correlationId,
                $durationMs
            ),
            ContentDeliveryEvent::TYPE_VERIFY_REMOTE => ContentDeliveryEvent::recordVerify(
                $this->currentPublication,
                $success,
                $response !== [] ? $response : null,
                $httpStatus,
                $correlationId,
                $durationMs
            ),
            ContentDeliveryEvent::TYPE_FAIL_REMOTE => ContentDeliveryEvent::recordFailure(
                $this->currentPublication,
                $errorMessage ?? 'Unknown error',
                $httpStatus !== null ? (string) $httpStatus : null,
                $payload,
                $response,
                $httpStatus,
                $correlationId,
                $durationMs
            ),
            default => null,
        };
    }

    /**
     * Update the publication record after successful delivery.
     */
    private function updatePublicationOnSuccess(
        string $wpPostId,
        ?string $publishedUrl = null,
        ?string $payloadChecksum = null,
        ?string $postType = null
    ): void {
        if (! $this->shouldPersistPublication()) {
            return;
        }

        $this->currentPublication->markDelivered($wpPostId, $publishedUrl, $postType);

        if ($payloadChecksum) {
            $this->currentPublication->updatePayloadChecksum($payloadChecksum);
        }
    }

    /**
     * Update the publication record after failed delivery.
     */
    private function updatePublicationOnFailure(string $errorCode, string $errorMessage): void
    {
        if (! $this->shouldPersistPublication()) {
            return;
        }

        $this->currentPublication->markFailed($errorCode, $errorMessage);
    }

    /**
     * Update the publication record when remote is missing.
     */
    private function updatePublicationOnMissingRemote(string $previousRemoteId): void
    {
        if (! $this->currentPublication) {
            return;
        }

        $this->currentPublication->markMissingRemote($previousRemoteId);
    }

    /**
     * Get the current publication's remote ID if available.
     */
    public function getCurrentPublicationRemoteId(): ?string
    {
        return $this->currentPublication?->getWpPostId();
    }

    public function beginConnectorPublicationSession(ContentPublication $publication): void
    {
        $this->currentPublication = $publication;
        $this->suppressPublicationWrites = true;
    }

    public function endConnectorPublicationSession(): void
    {
        $this->suppressPublicationWrites = false;
        $this->currentPublication = null;
    }

    private function shouldPersistPublication(): bool
    {
        return $this->currentPublication !== null && ! $this->suppressPublicationWrites;
    }

    /**
     * Get the current publication record.
     */
    public function getCurrentPublication(): ?ContentPublication
    {
        return $this->currentPublication;
    }

    // =========================================================================
    // Concurrency Control Methods
    // =========================================================================

    /**
     * Acquire a delivery lock for the given draft.
     * Returns the lock on success, null if lock is unavailable.
     */
    private function acquireDeliveryLockForDraft(Draft $draft): ?Lock
    {
        $contentId = (string) ($draft->content_id ?? $draft->id);
        $destinationId = $this->publicationDestinationIdForDraft($draft) ?: 'unknown';
        $locale = $this->publicationLocaleValue($draft) ?: 'unknown';

        $lockKey = "delivery_lock:{$contentId}:{$destinationId}:{$locale}";
        $lock = Cache::lock($lockKey, self::DELIVERY_LOCK_TIMEOUT);

        if ($lock->get()) {
            $this->currentLock = $lock;
            $this->logDelivery('lock_acquired', $draft, [
                'lock_key' => $lockKey,
                'timeout_seconds' => self::DELIVERY_LOCK_TIMEOUT,
            ]);

            return $lock;
        }

        $this->logDelivery('lock_failed', $draft, [
            'lock_key' => $lockKey,
            'reason' => 'Lock already held by another process',
        ], 'warning');

        return null;
    }

    /**
     * Release the current delivery lock.
     */
    private function releaseDeliveryLock(Draft $draft): void
    {
        if ($this->currentLock) {
            $this->currentLock->release();
            $this->logDelivery('lock_released', $draft, [
                'lock_key' => sprintf(
                    'delivery_lock:%s:%s:%s',
                    (string) ($draft->content_id ?? $draft->id),
                    $this->publicationDestinationIdForDraft($draft) ?: 'unknown',
                    $this->publicationLocaleValue($draft) ?: 'unknown',
                ),
            ]);
            $this->currentLock = null;
        }
    }

    /**
     * Get the checksum service instance.
     */
    private function checksumService(): PayloadChecksumService
    {
        return $this->checksumService ?? app(PayloadChecksumService::class);
    }

    private function answerBlockInjector(): AnswerBlockInjectorService
    {
        return $this->answerBlockInjector ?? app(AnswerBlockInjectorService::class);
    }

    private function answerBlockSchema(): AnswerBlockSchemaService
    {
        return $this->answerBlockSchema ?? app(AnswerBlockSchemaService::class);
    }

    /**
     * Calculate checksum for a delivery payload.
     */
    private function calculatePayloadChecksum(array $payload): string
    {
        return $this->checksumService()->calculateChecksum($payload);
    }

    /**
     * Check if delivery should be skipped based on checksum.
     *
     * @return array{skip: bool, reason: string, current_checksum: string}
     */
    private function shouldSkipDeliveryByChecksum(
        array $payload,
        ?string $storedChecksum,
        bool $forceDelivery = false
    ): array {
        return $this->checksumService()->shouldSkipDelivery($payload, $storedChecksum, $forceDelivery);
    }

    // =========================================================================
    // Structured Delivery Logging
    // =========================================================================

    /**
     * Log a structured delivery event.
     *
     * @param array<string, mixed> $context
     */
    private function logDelivery(string $event, Draft $draft, array $context = [], string $level = 'info'): void
    {
        $baseContext = [
            'event' => $event,
            'draft_id' => (string) $draft->id,
            'content_id' => (string) ($draft->content_id ?? ''),
            'client_site_id' => (string) ($draft->client_site_id ?? ''),
            'publication_id' => $this->currentPublication?->id,
            'correlation_id' => $this->currentCorrelationId,
        ];

        Log::channel('delivery')->log($level, "delivery.{$event}", array_merge($baseContext, $context));
    }

    /**
     * Generate a correlation ID for delivery tracking.
     */
    private function generateCorrelationId(): string
    {
        $this->currentCorrelationId = (string) Str::uuid();

        return $this->currentCorrelationId;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveConnectorPolicyPayload(Draft $draft): array
    {
        $publicationMeta = is_array($this->currentPublication?->meta) ? $this->currentPublication->meta : [];
        $draftPolicy = is_array(data_get($draft->meta, 'agentic_policy')) ? data_get($draft->meta, 'agentic_policy') : [];
        $policy = array_replace(
            is_array($publicationMeta['agentic_policy'] ?? null) ? $publicationMeta['agentic_policy'] : [],
            $draftPolicy,
        );

        $executionMode = strtolower(trim((string) ($policy['execution_mode'] ?? 'guided')));
        if (! in_array($executionMode, ['guided', 'autonomous'], true)) {
            $executionMode = 'guided';
        }

        return array_replace([
            'execution_mode' => $executionMode,
            'action_run_id' => data_get($policy, 'action_run_id'),
            'approval_status' => data_get($policy, 'approval_status', 'approved'),
            'approved_by' => data_get($policy, 'approved_by'),
            'approved_at' => data_get($policy, 'approved_at'),
            'autonomous_policy_snapshot' => data_get($policy, 'autonomous_policy_snapshot', []),
            'safety_check_status' => data_get($policy, 'safety_check_status', 'pass'),
            'safety_check_issues' => data_get($policy, 'safety_check_issues', []),
            'max_allowed_operation' => data_get($policy, 'max_allowed_operation', 'draft'),
            'dry_run' => (bool) data_get($policy, 'dry_run', false),
            'idempotency_key' => data_get($policy, 'idempotency_key') ?: hash('sha256', implode('|', [
                (string) ($this->currentPublication?->id ?? ''),
                (string) $draft->id,
                (string) ($draft->updated_at?->timestamp ?? ''),
            ])),
            'publishing_site_id' => (string) ($draft->client_site_id ?? ''),
        ], $policy);
    }

    /**
     * Log the delivery result and update publication checksum.
     *
     * @param array<string, mixed> $result
     */
    private function logDeliveryResult(Draft $draft, array $result, string $checksum): void
    {
        $durationMs = $this->getDeliveryDurationMs();

        if ($result['ok'] ?? false) {
            $this->logDelivery('completed', $draft, [
                'status' => $result['status'] ?? null,
                'duration_ms' => $durationMs,
                'checksum' => $checksum,
            ]);

            // Store the checksum for future skip-delivery optimization
            if ($this->shouldPersistPublication()) {
                $this->currentPublication->updatePayloadChecksum($checksum);
            }
        } else {
            $this->logDelivery('failed', $draft, [
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? 'Unknown error',
                'duration_ms' => $durationMs,
            ], 'error');
        }
    }
}
