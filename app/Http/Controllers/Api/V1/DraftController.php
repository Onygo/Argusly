<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Drafts\ExportDraftAction;
use App\Actions\Drafts\RegenerateDraftAction;
use App\Actions\Drafts\TranslateDraftAction;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AsyncOperationResource;
use App\Http\Resources\Api\V1\DraftAnalysisResource;
use App\Http\Resources\Api\V1\DraftResource;
use App\Jobs\AnalyzeDraftJob;
use App\Jobs\GenerateDraftJob;
use App\Models\ContentFeedback;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Services\Api\ApiScopes;
use App\Services\Credits\CreditQuoteService;
use App\Support\SeoMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DraftController extends Controller
{
    use RespondsWithApi;

    /**
     * GET /v1/drafts?status=ready
     */
    public function index(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if ($apiKey && $workspace) {
            if (! $apiKey->hasScope(ApiScopes::DRAFTS_READ)) {
                return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
            }

            $status = trim((string) $request->query('status', ''));
            $limit = (int) $request->query('limit', 50);
            $limit = max(1, min(200, $limit));

            $items = Draft::query()
                ->with(['contentDestination', 'brief'])
                ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $this->success(DraftResource::collection($items)->resolve(), meta: [
                'limit' => $limit,
            ]);
        }

        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $status = (string) $request->query('status', 'ready');
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(200, $limit));

        $items = Draft::query()
            ->with(['content.featuredImage', 'content.ogImage', 'content.seo'])
            ->where('client_site_id', $clientSite->id)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Draft $draft) => $this->mapDraftForConnector($draft, $clientSite));

        return response()->json(['items' => $items]);
    }

    /**
     * GET /v1/drafts/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if ($apiKey && $workspace) {
            if (! $apiKey->hasScope(ApiScopes::DRAFTS_READ)) {
                return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
            }

            $draft = Draft::query()
                ->with(['contentDestination', 'brief'])
                ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->where('id', $id)
                ->firstOrFail();

            return $this->success((new DraftResource($draft))->resolve());
        }

        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $d = Draft::query()
            ->with(['content.featuredImage', 'content.ogImage', 'content.seo'])
            ->where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        $payload = $this->mapDraftForConnector($d, $clientSite);
        $payload['last_error'] = $d->last_error;
        $payload['attempts'] = $d->attempts;

        return response()->json($payload);
    }

    /**
     * POST /v1/drafts/{id}/analyze
     */
    public function analyze(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if ($apiKey && $workspace) {
            if (! $apiKey->hasScope(ApiScopes::DRAFTS_WRITE)) {
                return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
            }

            $draft = Draft::query()
                ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->where('id', $id)
                ->firstOrFail();

            AnalyzeDraftJob::dispatch((string) $draft->id, true, null, (string) \Illuminate\Support\Str::uuid())
                ->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
                ->afterCommit();

            return $this->success([
                'draft_id' => (string) $draft->id,
                'status' => 'queued',
                'message' => 'Draft analysis queued.',
            ], status: 202);
        }

        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $draft = Draft::query()
            ->where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        AnalyzeDraftJob::dispatch((string) $draft->id, true, null, (string) \Illuminate\Support\Str::uuid())
            ->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
            ->afterCommit();

        return response()->json([
            'ok' => true,
            'draft_id' => (string) $draft->id,
            'status' => 'queued',
            'message' => 'Draft analysis queued.',
        ], 202);
    }

    /**
     * GET /v1/drafts/{id}/analysis
     */
    public function analysis(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if ($apiKey && $workspace) {
            if (! $apiKey->hasScope(ApiScopes::DRAFTS_READ)) {
                return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
            }

            $draft = Draft::query()
                ->with('analysis')
                ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->where('id', $id)
                ->firstOrFail();

            return $this->success(
                $draft->analysis ? (new DraftAnalysisResource($draft->analysis))->resolve() : null
            );
        }

        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:read')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $draft = Draft::query()
            ->with('analysis')
            ->where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'item' => $draft->analysis ? (new DraftAnalysisResource($draft->analysis))->resolve() : null,
        ]);
    }

    /**
     * POST /v1/drafts/{id}/ack
     * Client confirms draft is stored successfully.
     */
    public function ack(Request $request, string $id): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (
            ! $siteToken ||
            ! ($siteToken->hasScope('drafts:write') || $siteToken->hasScope('drafts:ack'))
        ) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $request->validate([
            'client.type' => ['nullable', 'string'],
            'client.site_url' => ['nullable', 'string'],
            'received_at' => ['nullable', 'string'],
            'data.wp_draft_id' => ['nullable', 'string'],
            'data.wp_post_id' => ['nullable', 'string'],
        ]);

        $draft = Draft::query()
            ->where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        $wpPostId = trim((string) $request->input('data.wp_post_id', ''));
        $wpDraftId = trim((string) $request->input('data.wp_draft_id', ''));

        // Idempotent
        if ($draft->acked_at) {
            $this->syncWordPressIdentifiers($draft, $wpPostId, $wpDraftId);

            return response()->json([
                'ok' => true,
                'already_acked' => true,
                'status' => $draft->status,
                'acked_at' => $draft->acked_at?->toIso8601String(),
            ]);
        }

        $draft->status = 'acked';
        $draft->acked_at = now();
        $draft->last_error = null;

        $draft->save();
        $this->syncWordPressIdentifiers($draft, $wpPostId, $wpDraftId);

        return response()->json([
            'ok' => true,
            'status' => $draft->status,
            'acked_at' => $draft->acked_at?->toIso8601String(),
        ]);
    }

    private function syncWordPressIdentifiers(Draft $draft, string $wpPostId, string $wpDraftId): void
    {
        if ($wpPostId === '' && $wpDraftId === '') {
            return;
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $clientRefs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];

        if ($wpPostId !== '') {
            $clientRefs['wp_post_id'] = $wpPostId;
        }

        if ($wpDraftId !== '') {
            $clientRefs['wp_draft_id'] = $wpDraftId;
        }

        $meta['client_refs'] = $clientRefs;
        $draft->meta = $meta;
        $draft->save();

        if ($wpPostId === '' || ! $draft->content_id) {
            return;
        }

        $content = $draft->content()->first();
        if (! $content) {
            return;
        }

        $content->update([
            'wp_post_id' => $wpPostId,
        ]);

        ContentPublishTarget::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'client_site_id' => $content->client_site_id,
                'target_type' => 'wp',
                'language' => $draft->language->value,
            ],
            [
                'target_identifier' => $wpPostId,
                'language' => $draft->language->value,
                'wp_post_id' => $wpPostId,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'meta' => array_filter([
                    'wp_post_id' => $wpPostId,
                    'wp_draft_id' => $wpDraftId !== '' ? $wpDraftId : null,
                ]),
            ]
        );
    }

    /**
     * POST /v1/drafts/{id}/feedback
     * action: revise | accept | reject
     */
    public function feedback(Request $request, string $id): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $data = $request->validate([
            'action' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'requested_by' => ['nullable', 'array'],
            'requested_by.wp_user_id' => ['nullable'],
            'requested_by.name' => ['nullable', 'string'],
        ]);

        $draft = Draft::query()
            ->where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        $action = strtolower(trim((string) $data['action']));

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['feedback'] = [
            'action' => $action,
            'notes' => $data['notes'] ?? null,
            'requested_by' => $data['requested_by'] ?? null,
            'requested_at' => now()->toIso8601String(),
        ];
        $draft->meta = $meta;

        if ($action === 'accept') {
            $draft->status = 'delivered';
            $draft->delivered_at = $draft->delivered_at ?: now();
        } else {
            // revise + reject + unknown
            $draft->status = 'revise_requested';
        }

        $draft->save();

        if ($draft->content_id) {
            ContentFeedback::query()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'content_id' => $draft->content_id,
                'type' => 'client',
                'message' => (string) ($data['notes'] ?? ('Client action: '.$action)),
                'context' => [
                    'action' => $action,
                    'requested_by' => $data['requested_by'] ?? null,
                    'draft_id' => $draft->id,
                ],
            ]);

            $draft->content()->update([
                'last_feedback_at' => now(),
                'status' => $action === 'accept' ? 'approved' : 'review',
            ]);
        }

        return response()->json([
            'ok' => true,
            'status' => $draft->status,
        ]);
    }

    /**
     * POST /v1/drafts/{id}/generate
     *
     * Queues generation for a draft.
     *
     * Notes:
     * - If you auto-dispatch on draft creation (recommended when drafts start as queued),
     *   this endpoint is mainly for retries / manual re-generation.
     *
     * Idempotent behavior:
     * - status generating: 202
     * - status generated or acked: 200
     * - status failed: 409 unless force=true
     * - status queued: 202 unless force=true (force will re-dispatch)
     * - otherwise: set queued + dispatch + 202
     */
    public function generate(
        Request $request,
        string $id,
        CreditQuoteService $creditQuotes
    ): JsonResponse {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $draft = Draft::query()
            ->where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOL);
        $resetAttempts = filter_var($request->input('reset_attempts', false), FILTER_VALIDATE_BOOL);

        if ($draft->status === 'generating') {
            return response()->json([
                'ok' => true,
                'id' => $draft->id,
                'status' => $draft->status,
                'message' => 'Draft generation already in progress.',
            ], 202);
        }

        if (in_array($draft->status, ['generated', 'acked'], true)) {
            return response()->json([
                'ok' => true,
                'id' => $draft->id,
                'status' => $draft->status,
                'message' => 'Draft already generated.',
            ], 200);
        }

        if ($draft->status === 'failed' && ! $force) {
            return response()->json([
                'ok' => false,
                'id' => $draft->id,
                'status' => $draft->status,
                'message' => 'Draft is failed. Pass force=true to retry generation.',
            ], 409);
        }

        if ($draft->status === 'queued' && ! $force) {
            return response()->json([
                'ok' => true,
                'id' => $draft->id,
                'status' => $draft->status,
                'message' => 'Draft is queued. Pass force=true to re-dispatch generation.',
            ], 202);
        }

        $requiredCredits = max(
            (int) ($draft->credit_cost ?? 0),
            $creditQuotes->requiredCreditsForAction('draft_generate', [
                'output_type' => (string) ($draft->output_type ?? ''),
            ])
        );
        $creditSnapshot = $creditQuotes->walletSnapshot((string) $clientSite->id);
        $availableCredits = (int) ($creditSnapshot['available_credits'] ?? 0);

        if ($requiredCredits > 0 && $availableCredits < $requiredCredits) {
            return response()->json($creditQuotes->insufficientPayload('draft_generate', $requiredCredits, $availableCredits), 422);
        }

        if ($resetAttempts) {
            $draft->attempts = 0;
        }

        $draft->fill([
            'status' => 'queued',
            'last_error' => null,
            'delivered_at' => $force ? null : $draft->delivered_at,
            'acked_at' => $force ? null : $draft->acked_at,
        ]);

        $draft->save();

        GenerateDraftJob::dispatch($draft->id);

        return response()->json([
            'ok' => true,
            'id' => $draft->id,
            'status' => $draft->status,
            'message' => 'Draft generation queued.',
        ], 202);
    }

    /**
     * POST /v1/drafts/{id}/regenerate (headless mode)
     */
    public function regenerate(Request $request, string $id, RegenerateDraftAction $action): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if (! $apiKey || ! $workspace) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $apiKey->hasScope(ApiScopes::DRAFTS_WRITE)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $validator = Validator::make($request->all(), [
            'requested_max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:64000'],
        ]);
        if ($validator->fails()) {
            return $this->error('The given data was invalid.', $validator->errors()->toArray(), 'VALIDATION_ERROR', 422);
        }

        $draft = Draft::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('id', $id)
            ->firstOrFail();

        $operation = $action->execute(
            draft: $draft,
            apiKey: $apiKey,
            requestPayload: $validator->validated(),
        );

        return $this->success(
            data: [
                'draft_id' => (string) $draft->id,
                'status' => 'queued',
            ],
            meta: [
                'operation' => (new AsyncOperationResource($operation))->resolve(),
            ],
            status: 202,
        );
    }

    /**
     * POST /v1/drafts/{id}/translate (headless mode)
     */
    public function translate(Request $request, string $id, TranslateDraftAction $action): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if (! $apiKey || ! $workspace) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $apiKey->hasScope(ApiScopes::TRANSLATIONS_WRITE)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $validator = Validator::make($request->all(), [
            'target_language' => ['required', 'string', 'max:10'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);
        if ($validator->fails()) {
            return $this->error('The given data was invalid.', $validator->errors()->toArray(), 'VALIDATION_ERROR', 422);
        }

        $draft = Draft::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('id', $id)
            ->firstOrFail();

        $operation = $action->execute(
            draft: $draft,
            targetLanguage: (string) $validator->validated()['target_language'],
            apiKey: $apiKey,
            model: $validator->validated()['model'] ?? null,
        );

        return $this->success((new AsyncOperationResource($operation))->resolve(), status: 202);
    }

    /**
     * GET /v1/drafts/{id}/export (headless mode)
     */
    public function export(Request $request, string $id, ExportDraftAction $action): JsonResponse
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if (! $apiKey || ! $workspace) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $apiKey->hasScope(ApiScopes::CONTENT_READ) && ! $apiKey->hasScope(ApiScopes::DRAFTS_READ)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $validator = Validator::make($request->all(), [
            'format' => ['nullable', 'in:json,html,markdown,text'],
        ]);
        if ($validator->fails()) {
            return $this->error('The given data was invalid.', $validator->errors()->toArray(), 'VALIDATION_ERROR', 422);
        }

        $draft = Draft::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('id', $id)
            ->firstOrFail();

        $format = (string) ($validator->validated()['format'] ?? 'json');
        $result = $action->execute($draft, $format);

        if (is_string($result)) {
            return $this->success([
                'format' => $format,
                'value' => $result,
            ]);
        }

        return $this->success($result);
    }

    private function resolveFeaturedImageUrl(Draft $draft, mixed $clientSite): ?string
    {
        $image = $draft->content?->featuredImage;
        if (! $image || $image->status !== 'ready') {
            return null;
        }

        $url = $image->getWordPressUploadUrl($clientSite);

        return $url !== '' ? $url : null;
    }

    private function resolveOgImageUrl(Draft $draft, mixed $clientSite): ?string
    {
        $image = $draft->content?->ogImage;
        if (! $image || $image->status !== 'ready') {
            return null;
        }

        $url = $image->getWordPressUploadUrl($clientSite);

        return $url !== '' ? $url : null;
    }

    /**
     * Connector payload contract for draft retrieval.
     *
     * Backwards compatibility:
     * - Legacy flat fields remain available (seo_title, seo_meta_description, ...)
     * - A normalized `seo` object is included for pull-based connector consumers.
     */
    private function mapDraftForConnector(Draft $draft, mixed $clientSite): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $seo = SeoMetadata::resolveForDraftContext($draft, $meta);
        $featuredImageUrl = $this->resolveFeaturedImageUrl($draft, $clientSite);
        $ogImageUrl = $this->resolveOgImageUrl($draft, $clientSite);
        $canonicalUrl = $seo['seo_canonical'];
        $ogImage = $seo['seo_og_image'] ?: $ogImageUrl;

        return [
            'id' => $draft->id,
            'brief_id' => $draft->brief_id,
            'content_id' => $draft->content_id,
            'status' => $draft->status,
            'title' => $draft->title,
            'seo_title' => $draft->seo_title,
            'seo_meta_description' => $draft->seo_meta_description,
            'seo_h1' => $draft->seo_h1,
            'seo_canonical' => $draft->seo_canonical,
            'seo_og_title' => $draft->seo_og_title,
            'seo_og_description' => $draft->seo_og_description,
            'seo_og_image' => $draft->seo_og_image,
            'seo_twitter_title' => $draft->seo_twitter_title,
            'seo_twitter_description' => $draft->seo_twitter_description,
            'primary_keyword' => $seo['primary_keyword'],
            'focus_keyword' => $seo['primary_keyword'],
            'meta_title' => $seo['seo_title'],
            'meta_description' => $seo['seo_meta_description'],
            'canonical_url' => $canonicalUrl,
            'og_image' => $ogImage,
            'robots_index' => $draft->robots_index,
            'robots_follow' => $draft->robots_follow,
            'schema_type' => $draft->schema_type,
            'seo' => [
                'primary_keyword' => $seo['primary_keyword'],
                'focus_keyword' => $seo['primary_keyword'],
                'meta_title' => $seo['seo_title'],
                'meta_description' => $seo['seo_meta_description'],
                'canonical_url' => $canonicalUrl,
                'og_image' => $ogImage,
                'seo_title' => $seo['seo_title'],
                'seo_meta_description' => $seo['seo_meta_description'],
                'seo_h1' => $seo['seo_h1'],
                'seo_canonical' => $seo['seo_canonical'],
                'seo_og_title' => $seo['seo_og_title'],
                'seo_og_description' => $seo['seo_og_description'],
                'seo_og_image' => $seo['seo_og_image'],
                'seo_twitter_title' => $seo['seo_twitter_title'],
                'seo_twitter_description' => $seo['seo_twitter_description'],
                'robots_index' => $seo['robots_index'],
                'robots_follow' => $seo['robots_follow'],
                'schema_type' => $seo['schema_type'],
            ],
            'output_type' => $draft->output_type,
            'content_html' => $draft->content_html,
            'meta' => $draft->meta,
            'links' => $draft->links,
            'featured_image_url' => $featuredImageUrl,
            'og_image_url' => $ogImageUrl,
            'delivered_at' => $draft->delivered_at?->toIso8601String(),
            'acked_at' => $draft->acked_at?->toIso8601String(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }
}
