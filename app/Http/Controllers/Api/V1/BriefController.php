<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Briefs\CreateBriefAction;
use App\Actions\Briefs\UpdateBriefAction;
use App\Actions\Drafts\QueueDraftGenerationAction;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AsyncOperationResource;
use App\Http\Resources\Api\V1\BriefResource;
use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\TaxonomyItem;
use App\Models\TeamMember;
use App\Services\Api\ApiScopes;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\Content\ContentLifecycleService;
use App\Services\Credits\CreditQuoteService;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Support\ContentIntentCatalog;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\EditorialTaxonomyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BriefController extends Controller
{
    use RespondsWithApi;

    /**
     * Create a new brief
     */
    public function store(
        Request $request,
        EditorialTaxonomyService $taxonomyService,
        ContentLifecycleService $contentLifecycleService,
        BriefToDraftService $briefToDraftService,
        WorkspaceEntitlementsService $entitlements,
        CreditQuoteService $creditQuotes,
        CreateBriefAction $createBriefAction,
    ) {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if ($apiKey && $workspace) {
            if (! $apiKey->hasScope(ApiScopes::BRIEFS_WRITE)) {
                return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
            }

            $validator = Validator::make($request->all(), [
                'title' => ['required', 'string', 'max:255'],
                'language' => ['nullable', 'string', 'max:10'],
                'content_type' => ['nullable', 'string', 'max:60'],
                'output_type' => ['nullable', 'string', 'max:60'],
                'intent' => ['nullable', 'string', 'max:80'],
                'primary_keyword' => ['nullable', 'string', 'max:255'],
                'secondary_keywords' => ['nullable', 'array'],
                'secondary_keywords.*' => ['string', 'max:255'],
                'audience' => ['nullable', 'string', 'max:2000'],
                'audience_details' => ['nullable', 'string', 'max:5000'],
                'target_audience' => ['nullable', 'string', 'max:5000'],
                'funnel_stage' => ['nullable', 'string', 'max:50'],
                'search_intent' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string'],
                'tone_of_voice' => ['nullable', 'string'],
                'unique_angle' => ['nullable', 'string'],
                'key_points' => ['nullable', 'array'],
                'key_points.*' => ['string', 'max:500'],
                'call_to_action' => ['nullable', 'string', 'max:500'],
                'desired_length_min' => ['nullable', 'integer', 'min:100', 'max:12000'],
                'desired_length_max' => ['nullable', 'integer', 'min:100', 'max:12000'],
                'content_destination_id' => ['nullable', 'uuid'],
                'generate_draft' => ['nullable', 'boolean'],
                'requested_max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:64000'],
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'The given data was invalid.',
                    $validator->errors()->toArray(),
                    'VALIDATION_ERROR',
                    422
                );
            }

            $result = $createBriefAction->execute(
                workspace: $workspace,
                payload: $validator->validated(),
                apiKey: $apiKey,
                createdBy: optional($request->user())->id,
            );

            $brief = $result['brief'];
            $draft = $result['draft'];
            $operation = $result['operation'];

            return $this->success(
                data: (new BriefResource($brief))->resolve(),
                meta: array_filter([
                    'draft' => $draft ? ['id' => (string) $draft->id, 'status' => (string) $draft->status] : null,
                    'operation' => $operation ? (new AsyncOperationResource($operation))->resolve() : null,
                ], static fn ($value): bool => $value !== null),
                status: 201
            );
        }

        $siteToken = $request->attributes->get('siteToken');

        if (! $siteToken || ! $siteToken->hasScope('briefs:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');

        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $payload = $this->normalizeIncomingBriefPayload($request->all(), $taxonomyService);

        $data = Validator::make($payload, [
            'client.type' => ['required', 'string'],
            'client.site_url' => ['required', 'string'],
            'client.wp_brief_id' => ['nullable', 'string'],
            'client.wp_post_id' => ['nullable', 'string'],
            'client.wp_site_id' => ['nullable', 'string'],
            'client.wp_remote_ref' => ['nullable', 'string'],

            'brief.title' => ['required', 'string'],
            'brief.language' => ['required', 'string', 'max:10'],
            'brief.intent' => ['nullable', 'array'],
            'brief.intent.keys' => ['required_without:brief.intent_keys', 'array', 'min:1'],
            'brief.intent.keys.*' => ['string', 'max:50'],
            'brief.intent_keys' => ['required_without:brief.intent.keys', 'array', 'min:1'],
            'brief.intent_keys.*' => ['string', 'max:50'],
            'brief.audience' => ['nullable', 'array'],
            'brief.audience.keys' => ['nullable', 'array', 'min:1'],
            'brief.audience.keys.*' => ['string', 'max:50'],
            'brief.audience_details' => ['nullable', 'string', 'max:5000'],
            'brief.primary_keyword' => ['nullable', 'string'],
            'brief.robots_index' => ['nullable', 'boolean'],
            'brief.robots_follow' => ['nullable', 'boolean'],
            'brief.schema_type' => ['nullable', 'string', 'max:120'],
            'brief.audience_keys' => ['required_without:brief.audience.keys', 'array', 'min:1'],
            'brief.audience_keys.*' => ['string', 'max:50'],
            'brief.brand_voice_id' => ['nullable', 'uuid'],
            'brief.team_member_id' => ['nullable', 'integer'],
            'brief.preferred_length' => ['nullable', 'in:short,medium,long,pillar'],
            'brief.notes' => ['nullable', 'string'],
            'brief.output_type' => ['required', 'string'],
            'brief.content_type' => ['nullable', 'in:blog,landing,linkedin,email,other'],
            'brief.secondary_keywords' => ['nullable'],
            'brief.target_audience' => ['nullable', 'string', 'max:5000'],
            'brief.funnel_stage' => ['nullable', 'in:awareness,consideration,decision,retention'],
            'brief.search_intent' => ['nullable', 'string', 'max:100'],
            'brief.tone_of_voice' => ['nullable', 'string'],
            'brief.unique_angle' => ['nullable', 'string'],
            'brief.key_points' => ['nullable'],
            'brief.call_to_action' => ['nullable', 'string'],
            'brief.desired_length_min' => ['nullable', 'integer', 'min:300', 'max:10000'],
            'brief.desired_length_max' => ['nullable', 'integer', 'min:300', 'max:10000'],

            'webhook.draft_url' => ['nullable', 'string'],
            'webhook.secret' => ['nullable', 'string'],
        ])->validate();

        $organizationId = (int) $clientSite->workspace?->organization_id;
        if (! $organizationId) {
            return response()->json(['error' => 'Organization not resolved'], 422);
        }

        $taxonomyService->ensureDefaults($organizationId);

        $brandVoiceId = $this->resolveBrandVoiceId(
            $clientSite->workspace_id,
            $organizationId,
            (string) ($data['brief']['brand_voice_id'] ?? '')
        );
        $teamMemberId = $this->resolveTeamMemberId(
            $organizationId,
            isset($data['brief']['team_member_id']) ? (int) $data['brief']['team_member_id'] : null
        );
        $preferredLength = $this->resolvePreferredLength((string) ($data['brief']['preferred_length'] ?? ''));

        $data['brief']['brand_voice_id'] = $brandVoiceId;
        $data['brief']['team_member_id'] = $teamMemberId;
        $data['brief']['preferred_length'] = $preferredLength;
        $wpBriefId = trim((string) ($data['client']['wp_brief_id'] ?? ''));
        $wpPostId = trim((string) ($data['client']['wp_post_id'] ?? ''));

        $existingBrief = $this->findExistingBriefReplay($clientSite->id, $wpBriefId);
        if ($existingBrief) {
            $existingDraft = $existingBrief->drafts()->latest('created_at')->first();

            return response()->json([
                'id' => $existingBrief->id,
                'status' => $existingBrief->status,
                'created_at' => $existingBrief->created_at?->toIso8601String(),
                'content_id' => $existingBrief->content_id,
                'draft_id' => $existingDraft?->id,
                'idempotent_replay' => true,
            ], 200);
        }

        $requiredCredits = $creditQuotes->requiredCreditsForAction('draft_generate', (array) ($data['brief'] ?? []));
        $creditSnapshot = $creditQuotes->walletSnapshot((string) $clientSite->id);
        $availableCredits = (int) ($creditSnapshot['available_credits'] ?? 0);

        if ($requiredCredits > 0 && $availableCredits < $requiredCredits) {
            return response()->json($creditQuotes->insufficientPayload('draft_generate', $requiredCredits, $availableCredits), 422);
        }

        try {
            $entitlements->consumeBriefQuota($clientSite->workspace);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $content = $contentLifecycleService->findOrCreateFromWpPayload(
            clientSite: $clientSite,
            briefData: (array) ($data['brief'] ?? []),
            clientData: (array) ($data['client'] ?? []),
        );

        $allowedIntentKeys = $taxonomyService
            ->activeItemsByTenantAndType($organizationId, TaxonomyItem::TYPE_INTENT)
            ->pluck('slug')
            ->map(fn ($key) => strtolower((string) $key))
            ->values()
            ->all();
        $allowedIntentKeys = array_values(array_unique(array_merge(
            $allowedIntentKeys,
            ContentIntentCatalog::allowedKeys()
        )));

        $allowedAudienceKeys = $taxonomyService
            ->activeItemsByTenantAndType($organizationId, TaxonomyItem::TYPE_AUDIENCE)
            ->pluck('slug')
            ->map(fn ($key) => strtolower((string) $key))
            ->values()
            ->all();

        $intentKeys = $this->resolveIntentKeys($data, $taxonomyService);
        $audienceKeys = $this->resolveAudienceKeys($data, $taxonomyService);
        $searchIntent = $taxonomyService->normalizeKey((string) data_get($data, 'brief.search_intent', ''));

        $invalidIntent = collect($intentKeys)->first(fn (string $key) => ! in_array($key, $allowedIntentKeys, true));
        if ($invalidIntent) {
            throw ValidationException::withMessages([
                $this->intentErrorField($data) => ["Unknown intent key: {$invalidIntent}"],
            ]);
        }

        if ($searchIntent !== '' && ! in_array($searchIntent, $allowedIntentKeys, true)) {
            throw ValidationException::withMessages([
                'brief.search_intent' => ["Unknown search intent key: {$searchIntent}"],
            ]);
        }

        $invalidAudience = collect($audienceKeys)->first(fn (string $key) => ! in_array($key, $allowedAudienceKeys, true));
        if ($invalidAudience) {
            throw ValidationException::withMessages([
                'brief.audience_keys' => ["Unknown audience key: {$invalidAudience}"],
            ]);
        }

        $clientSite->update([
            'draft_webhook_url' => $data['webhook']['draft_url'] ?? $clientSite->draft_webhook_url,
            'draft_webhook_secret' => $data['webhook']['secret'] ?? $clientSite->draft_webhook_secret,
        ]);

        $primaryIntent = $intentKeys[0] ?? null;

        $brief = DB::transaction(function () use (
            $clientSite,
            $wpBriefId,
            $wpPostId,
            $content,
            $data,
            $primaryIntent,
            $intentKeys,
            $audienceKeys,
            $searchIntent,
            $brandVoiceId,
            $teamMemberId,
            $preferredLength
        ) {
            ClientSite::query()->whereKey($clientSite->id)->lockForUpdate()->first();

            $replayed = $this->findExistingBriefReplay($clientSite->id, $wpBriefId);
            if ($replayed) {
                return $replayed;
            }

            return Brief::create(ContentPersistencePayloadNormalizer::normalizeBrief([
                'client_site_id' => $clientSite->id,
                'wp_brief_id' => $wpBriefId !== '' ? $wpBriefId : null,
                'wp_post_id' => $wpPostId !== '' ? $wpPostId : null,
                'wp_site_id' => trim((string) ($data['client']['wp_site_id'] ?? $clientSite->id)) ?: null,
                'wp_remote_ref' => trim((string) ($data['client']['wp_remote_ref'] ?? $wpBriefId ?: $wpPostId)) ?: null,
                'content_id' => $content->id,
                'status' => 'queued',
                'source' => 'wp_plugin',
                'progress' => 0,

                'title' => $data['brief']['title'],
                'language' => $data['brief']['language'],
                'content_type' => (string) ($data['brief']['content_type'] ?? $this->mapOutputTypeToContentType((string) ($data['brief']['output_type'] ?? ''))),
                'intent' => $primaryIntent,
                'primary_keyword' => $data['brief']['primary_keyword'] ?? null,
                'secondary_keywords' => $this->normalizeStringList($data['brief']['secondary_keywords'] ?? null),
                'audience' => implode(',', $audienceKeys),
                'audience_details' => $data['brief']['audience_details'] ?? null,
                'target_audience' => $data['brief']['target_audience'] ?? implode(',', $audienceKeys),
                'funnel_stage' => $data['brief']['funnel_stage'] ?? null,
                'search_intent' => $searchIntent !== '' ? $searchIntent : null,
                'output_type' => $data['brief']['output_type'],
                'notes' => $data['brief']['notes'] ?? null,
                'tone_of_voice' => $data['brief']['tone_of_voice'] ?? null,
                'unique_angle' => $data['brief']['unique_angle'] ?? null,
                'key_points' => $this->normalizeStringList($data['brief']['key_points'] ?? null),
                'call_to_action' => $data['brief']['call_to_action'] ?? null,
                'desired_length_min' => $data['brief']['desired_length_min'] ?? null,
                'desired_length_max' => $data['brief']['desired_length_max'] ?? null,

                'client_refs' => [
                    'client_type' => $data['client']['type'],
                    'site_url' => $data['client']['site_url'],
                    'wp_brief_id' => $wpBriefId !== '' ? $wpBriefId : null,
                    'wp_post_id' => $wpPostId !== '' ? $wpPostId : null,
                    'draft_webhook_url' => $data['webhook']['draft_url'] ?? null,
                    'draft_webhook_secret' => $data['webhook']['secret'] ?? null,
                    'taxonomy' => [
                        'intent_keys' => $intentKeys,
                        'audience_keys' => $audienceKeys,
                    ],
                    'brand_voice_id' => $brandVoiceId,
                    'team_member_id' => $teamMemberId,
                    'preferred_length' => $preferredLength,
                    'robots_index' => data_get($data, 'brief.robots_index'),
                    'robots_follow' => data_get($data, 'brief.robots_follow'),
                    'schema_type' => trim((string) data_get($data, 'brief.schema_type', '')) ?: null,
                ],
            ]));
        });

        $contentLifecycleService->attachBriefToContent($brief, $content);

        $draft = $briefToDraftService->claimAndCreateDraft((string) $brief->id);
        if ($draft) {
            $contentLifecycleService->ensureRevisionFromDraft($draft);
            $content->refresh();
        }

        return response()->json([
            'id' => $brief->id,
            'status' => $brief->status,
            'created_at' => $brief->created_at?->toIso8601String(),
            'content_id' => $content->id,
            'draft_id' => $draft?->id,
            'taxonomy' => [
                'intent_keys' => $intentKeys,
                'audience_keys' => $audienceKeys,
            ],
            'generation' => [
                'brand_voice_id' => $brandVoiceId,
                'team_member_id' => $teamMemberId,
                'preferred_length' => $preferredLength,
            ],
        ], 201);
    }

    private function findExistingBriefReplay(string $clientSiteId, string $wpBriefId): ?Brief
    {
        if ($wpBriefId === '') {
            return null;
        }

        $existing = Brief::query()
            ->where('client_site_id', $clientSiteId)
            ->where('wp_brief_id', $wpBriefId)
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $legacy = Brief::query()
            ->where('client_site_id', $clientSiteId)
            ->whereNull('wp_brief_id')
            ->where('client_refs->wp_brief_id', $wpBriefId)
            ->latest('created_at')
            ->first();

        if ($legacy) {
            $legacy->wp_brief_id = $wpBriefId;
            $legacy->save();
        }

        return $legacy;
    }

    /**
     * List briefs (headless mode).
     */
    public function index(Request $request)
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (! $apiKey->hasScope(ApiScopes::BRIEFS_READ)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $status = trim((string) $request->query('status', ''));

        $items = Brief::query()
            ->with(['contentDestination', 'drafts' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success(BriefResource::collection($items)->resolve(), meta: [
            'limit' => $limit,
        ]);
    }

    /**
     * Get brief status/details.
     */
    public function show(Request $request, string $id)
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');
        if ($apiKey && $workspace) {
            if (! $apiKey->hasScope(ApiScopes::BRIEFS_READ)) {
                return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
            }

            $brief = Brief::query()
                ->with(['contentDestination', 'drafts' => fn ($query) => $query->latest('created_at')->limit(3)])
                ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->where('id', $id)
                ->firstOrFail();

            return $this->success((new BriefResource($brief))->resolve(), meta: [
                'latest_draft_id' => optional($brief->drafts->first())->id,
            ]);
        }

        $siteToken = $request->attributes->get('siteToken');

        if (! $siteToken || ! $siteToken->hasScope('briefs:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');

        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $brief = Brief::where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'id' => $brief->id,
            'status' => $brief->status,
            'progress' => $brief->progress,
            'updated_at' => $brief->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Update brief (headless mode).
     */
    public function update(Request $request, string $id, UpdateBriefAction $updateBriefAction)
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $apiKey->hasScope(ApiScopes::BRIEFS_WRITE)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:40'],
            'language' => ['sometimes', 'string', 'max:10'],
            'content_type' => ['sometimes', 'string', 'max:60'],
            'output_type' => ['sometimes', 'string', 'max:60'],
            'intent' => ['sometimes', 'nullable', 'string', 'max:80'],
            'primary_keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secondary_keywords' => ['sometimes', 'nullable', 'array'],
            'secondary_keywords.*' => ['string', 'max:255'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_audience' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'funnel_stage' => ['sometimes', 'nullable', 'string', 'max:50'],
            'search_intent' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tone_of_voice' => ['sometimes', 'nullable', 'string'],
            'unique_angle' => ['sometimes', 'nullable', 'string'],
            'key_points' => ['sometimes', 'nullable', 'array'],
            'key_points.*' => ['string', 'max:500'],
            'call_to_action' => ['sometimes', 'nullable', 'string', 'max:500'],
            'desired_length_min' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:12000'],
            'desired_length_max' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:12000'],
            'content_destination_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return $this->error(
                'The given data was invalid.',
                $validator->errors()->toArray(),
                'VALIDATION_ERROR',
                422
            );
        }

        $brief = Brief::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('id', $id)
            ->firstOrFail();

        $updated = $updateBriefAction->execute($brief, $validator->validated());

        return $this->success((new BriefResource($updated))->resolve());
    }

    /**
     * Queue draft generation for brief (headless mode).
     */
    public function generateDraft(Request $request, string $id, QueueDraftGenerationAction $queueDraftGenerationAction)
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! $apiKey->hasScope(ApiScopes::BRIEFS_WRITE)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $validator = Validator::make($request->all(), [
            'requested_max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:64000'],
        ]);
        if ($validator->fails()) {
            return $this->error('The given data was invalid.', $validator->errors()->toArray(), 'VALIDATION_ERROR', 422);
        }

        $brief = Brief::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('id', $id)
            ->firstOrFail();

        $queued = $queueDraftGenerationAction->execute(
            brief: $brief,
            apiKey: $apiKey,
            requestPayload: $validator->validated(),
        );

        return $this->success(
            data: [
                'brief_id' => (string) $brief->id,
                'draft_id' => (string) $queued['draft']->id,
                'status' => (string) $queued['draft']->status,
            ],
            meta: [
                'operation' => (new AsyncOperationResource($queued['operation']))->resolve(),
            ],
            status: 202,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function resolveIntentKeys(array $data, EditorialTaxonomyService $taxonomyService): array
    {
        $fromArray = collect((array) data_get($data, 'brief.intent_keys', []))
            ->merge((array) data_get($data, 'brief.intent.keys', []))
            ->map(fn ($value) => $taxonomyService->normalizeKey((string) $value));

        return $fromArray
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function resolveAudienceKeys(array $data, EditorialTaxonomyService $taxonomyService): array
    {
        $fromArray = collect((array) data_get($data, 'brief.audience_keys', []))
            ->merge((array) data_get($data, 'brief.audience.keys', []))
            ->map(fn ($value) => $taxonomyService->normalizeKey((string) $value));

        return $fromArray
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intentErrorField(array $data): string
    {
        return data_get($data, 'brief.intent.keys') !== null
            ? 'brief.intent.keys'
            : 'brief.intent_keys';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeIncomingBriefPayload(array $payload, EditorialTaxonomyService $taxonomyService): array
    {
        $brief = is_array($payload['brief'] ?? null) ? $payload['brief'] : [];

        $normalizedIntentKeys = $this->normalizePayloadStringList(
            value: $brief['intent_keys'] ?? data_get($brief, 'intent.keys') ?? $brief['intent_key'] ?? $brief['intent'] ?? null,
            taxonomyService: $taxonomyService,
        );

        if ($normalizedIntentKeys === []) {
            $outputType = strtolower(trim((string) ($brief['output_type'] ?? '')));
            $normalizedIntentKeys = ContentIntentCatalog::defaultsForOutputType($outputType);
        }

        data_set($brief, 'intent.keys', $normalizedIntentKeys);

        if (! array_key_exists('intent_keys', $brief)) {
            $brief['intent_keys'] = $normalizedIntentKeys;
        }

        $normalizedAudienceKeys = $this->normalizePayloadStringList(
            value: $brief['audience_keys'] ?? data_get($brief, 'audience.keys') ?? $brief['audience_key'] ?? $brief['target_audience'] ?? null,
            taxonomyService: $taxonomyService,
        );

        if ($normalizedAudienceKeys === []) {
            $normalizedAudienceKeys = ['operations'];
        }

        data_set($brief, 'audience.keys', $normalizedAudienceKeys);

        if (! array_key_exists('audience_keys', $brief)) {
            $brief['audience_keys'] = $normalizedAudienceKeys;
        }

        $payload['brief'] = $brief;

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function normalizePayloadStringList(mixed $value, EditorialTaxonomyService $taxonomyService): array
    {
        if (is_array($value) && array_key_exists('keys', $value)) {
            $value = $value['keys'];
        }

        if (is_string($value)) {
            $value = preg_split('/[,;\n|]+/', $value) ?: [];
        }

        return collect((array) $value)
            ->map(fn ($item): string => $taxonomyService->normalizeKey((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resolveBrandVoiceId(string $workspaceId, int $organizationId, string $brandVoiceId): ?string
    {
        $brandVoiceId = trim($brandVoiceId);
        if ($brandVoiceId === '') {
            return null;
        }

        $voice = BrandVoice::query()->find($brandVoiceId);
        if (! $voice) {
            throw ValidationException::withMessages([
                'brief.brand_voice_id' => ['Selected brand voice does not exist.'],
            ]);
        }

        $allowed = (string) ($voice->workspace_id ?? '') === (string) $workspaceId
            || (int) ($voice->organization_id ?? 0) === $organizationId;

        if (! $allowed) {
            throw ValidationException::withMessages([
                'brief.brand_voice_id' => ['Selected brand voice is not available for this workspace.'],
            ]);
        }

        return (string) $voice->id;
    }

    private function resolveTeamMemberId(int $organizationId, ?int $teamMemberId): ?int
    {
        if (! $teamMemberId) {
            return null;
        }

        $member = TeamMember::query()->find($teamMemberId);
        if (! $member || (int) $member->organization_id !== $organizationId || ! $member->is_active) {
            throw ValidationException::withMessages([
                'brief.team_member_id' => ['Selected team member is not available for this organization.'],
            ]);
        }

        return (int) $member->id;
    }

    private function resolvePreferredLength(string $preferredLength): string
    {
        $preferredLength = strtolower(trim($preferredLength));

        return in_array($preferredLength, ['short', 'medium', 'long', 'pillar'], true)
            ? $preferredLength
            : 'medium';
    }

    private function mapOutputTypeToContentType(string $outputType): string
    {
        return match (strtolower(trim($outputType))) {
            'seo_page', 'landing', 'landing_page' => 'landing',
            'linkedin_post', 'linkedin' => 'linkedin',
            'email', 'email_sequence' => 'email',
            default => 'blog',
        };
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value)));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $string) ?: [])));
    }
}
