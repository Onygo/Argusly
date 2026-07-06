<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Http\Controllers;

use Argusly\LaravelConnector\Models\ArguslyArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Onygo\ArguslyConnector\ActivityState;

final class ConnectorSyncController extends Controller
{
    public function __invoke(Request $request, ActivityState $activity): JsonResponse
    {
        if (! (bool) config('argusly-connector.webhooks.enabled', true)) {
            return response()->json([
                'accepted' => false,
                'rejected' => true,
                'message' => 'Argusly connector webhooks are disabled.',
            ], 403);
        }

        $tokenResponse = $this->validateToken($request);
        if ($tokenResponse instanceof JsonResponse) {
            return $tokenResponse;
        }

        $workspaceResponse = $this->validateSite($request);
        if ($workspaceResponse instanceof JsonResponse) {
            return $workspaceResponse;
        }

        $policyResponse = $this->validatePolicy($request);
        if ($policyResponse instanceof JsonResponse) {
            return $policyResponse;
        }

        $article = (array) $request->input('article', []);
        $sourceId = trim((string) ($article['id'] ?? $request->input('content_id', '')));
        $title = trim((string) ($article['title'] ?? ''));

        if ($sourceId === '' || $title === '') {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'article.id' => $sourceId === '' ? ['The article.id field is required.'] : [],
                    'article.title' => $title === '' ? ['The article.title field is required.'] : [],
                ],
            ], 422);
        }

        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey !== '' && ! Cache::add($this->idempotencyCacheKey($idempotencyKey), true, $this->idempotencyTtl())) {
            return response()->json([
                'accepted' => false,
                'rejected' => true,
                'duplicate' => true,
                'processed_idempotency_key' => $idempotencyKey,
                'message' => 'This Argusly sync payload has already been processed.',
            ], 409);
        }

        $model = ArguslyArticle::query()->updateOrCreate(
            ['source_argusly_id' => $sourceId],
            $this->articleAttributes($article, $request, $sourceId)
        );

        $activity->record('webhook', [
            'last_processed_at' => Carbon::now()->toIso8601String(),
            'last_status_code' => 200,
            'last_content_id' => $sourceId,
        ]);

        return response()->json([
            'accepted' => true,
            'draft_created' => (string) $model->status === 'draft',
            'preview_ready' => true,
            'article_id' => $model->getKey(),
            'source_argusly_id' => $sourceId,
            'processed_idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
        ]);
    }

    private function validateToken(Request $request): ?JsonResponse
    {
        $configuredToken = $this->configuredToken();
        $incomingToken = $this->incomingToken($request);

        if ($incomingToken === '') {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'api_key' => ['An Argusly API key is required.'],
                ],
            ], 422);
        }

        if ($configuredToken === '' || ! hash_equals($configuredToken, $incomingToken)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'api_key' => ['The selected Argusly API key is invalid.'],
                ],
            ], 422);
        }

        return null;
    }

    private function validateSite(Request $request): ?JsonResponse
    {
        $configuredSite = $this->configuredSite();
        $incomingSite = trim((string) ($request->input('site_id') ?: $request->header('X-Argusly-Site')));

        if ($configuredSite !== '' && $incomingSite !== '' && $configuredSite !== $incomingSite) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'site_id' => ['The site_id does not match this connector.'],
                ],
            ], 422);
        }

        return null;
    }

    private function validatePolicy(Request $request): ?JsonResponse
    {
        $policy = (array) $request->input('policy', []);
        $executionMode = trim((string) ($policy['execution_mode'] ?? 'guided'));
        $safetyStatus = trim((string) ($policy['safety_check_status'] ?? 'pass'));
        $maxOperation = trim((string) ($policy['max_allowed_operation'] ?? 'draft'));
        $allowedOperations = $this->allowedOperations();

        if ($executionMode === 'autonomous' && ! (bool) config('argusly-connector.policy.autonomous_allowed', config('argusly.autonomous_allowed', false))) {
            return response()->json([
                'accepted' => false,
                'blocked' => true,
                'rejected' => true,
                'message' => 'Autonomous Argusly sync is not enabled for this connector.',
            ], 403);
        }

        if (in_array($safetyStatus, ['block', 'blocked', 'fail', 'failed'], true)) {
            return response()->json([
                'accepted' => false,
                'blocked' => true,
                'rejected' => true,
                'message' => 'Argusly safety policy blocked this sync.',
            ], 423);
        }

        if ($maxOperation !== '' && $allowedOperations !== [] && ! in_array($maxOperation, $allowedOperations, true)) {
            return response()->json([
                'accepted' => false,
                'blocked' => true,
                'rejected' => true,
                'message' => 'This Argusly operation is not allowed for this connector.',
            ], 403);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $article
     * @return array<string, mixed>
     */
    private function articleAttributes(array $article, Request $request, string $sourceId): array
    {
        $baseSlug = Str::slug((string) ($article['slug'] ?? $article['title'] ?? $article['id']));
        $slug = $this->uniqueSlug($baseSlug !== '' ? $baseSlug : Str::uuid()->toString(), $sourceId);
        $now = Carbon::now();
        $metadata = [
            'argusly' => $article['argusly'] ?? null,
            'policy' => $request->input('policy', []),
            'taxonomy' => $article['taxonomy'] ?? null,
            'categories' => $article['categories'] ?? null,
            'topics' => $article['topics'] ?? null,
            'idempotency_key' => $this->idempotencyKey($request) ?: null,
            'received_at' => $now->toIso8601String(),
        ];

        return array_filter([
            'title' => (string) $article['title'],
            'slug' => $slug,
            'summary' => $this->nullableString($article['summary'] ?? null),
            'content_html' => (string) ($article['content_html'] ?? $article['html'] ?? ''),
            'seo_title' => $this->nullableString($article['seo_title'] ?? null),
            'seo_description' => $this->nullableString($article['seo_description'] ?? null),
            'featured_image_url' => $this->nullableString($article['featured_image_url'] ?? null),
            'locale' => $this->nullableString($article['locale'] ?? $article['language'] ?? null),
            'source_locale' => $this->nullableString($article['source_locale'] ?? null),
            'canonical_url' => $this->nullableString($article['canonical_url'] ?? null),
            'canonical_content_id' => $this->nullableString($article['canonical_content_id'] ?? null),
            'hreflang_alternates' => $this->arrayOrNull($article['hreflang_alternates'] ?? null),
            'x_default_url' => $this->nullableString($article['x_default_url'] ?? null),
            'translation_group_id' => $this->nullableString($article['translation_group_id'] ?? null),
            'family_id' => $this->nullableString($article['family_id'] ?? null),
            'answer_blocks' => $this->arrayOrNull($article['answer_blocks'] ?? null),
            'structured_output' => $this->arrayOrNull($article['structured_output'] ?? null),
            'schema_data' => $this->arrayOrNull($article['schema'] ?? $article['schema_data'] ?? null),
            'ai_visibility' => $this->arrayOrNull($article['ai_visibility'] ?? null),
            'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null && $value !== []),
            'status' => (string) ($article['status'] ?? 'draft'),
            'published_at' => $this->carbonOrNull($article['published_at'] ?? null),
            'source_updated_at' => $this->carbonOrNull($article['source_updated_at'] ?? null),
        ], fn ($value, string $key): bool => $this->columnExists($key) && $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function configuredToken(): string
    {
        return trim((string) (
            config('argusly-connector.api.token')
            ?: config('argusly.api_key')
            ?: config('argusly_connector.api_key')
        ));
    }

    private function configuredSite(): string
    {
        return trim((string) (
            config('argusly-connector.site.id')
            ?: config('argusly.site_id')
            ?: config('argusly_connector.site_id')
        ));
    }

    private function incomingToken(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization'));

        if (str_starts_with(strtolower($authorization), 'bearer ')) {
            return trim(substr($authorization, 7));
        }

        return trim((string) (
            $request->header('X-Argusly-API-Key')
            ?: $request->header('X-Argusly-Api-Key')
            ?: $request->input('api_key')
            ?: $request->input('site_key')
            ?: $request->input('site_token')
        ));
    }

    /**
     * @return array<int, string>
     */
    private function allowedOperations(): array
    {
        $operations = config('argusly-connector.policy.allowed_operations', config('argusly.allowed_operations', ['create', 'update', 'draft']));

        return array_values(array_filter(array_map(
            static fn ($operation): string => trim((string) $operation),
            is_array($operations) ? $operations : explode(',', (string) $operations)
        )));
    }

    private function idempotencyKey(Request $request): string
    {
        return trim((string) (
            $request->header('X-Argusly-Idempotency-Key')
            ?: $request->input('policy.idempotency_key')
            ?: $request->input('idempotency_key')
        ));
    }

    private function idempotencyCacheKey(string $idempotencyKey): string
    {
        return 'argusly_connector.sync.idempotency.' . hash('sha256', $idempotencyKey);
    }

    private function idempotencyTtl(): Carbon
    {
        $seconds = max(60, (int) config('argusly-connector.webhooks.idempotency_ttl_seconds', 86400));

        return Carbon::now()->addSeconds($seconds);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function carbonOrNull(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function columnExists(string $column): bool
    {
        static $columns = null;

        $columns ??= Schema::getColumnListing('argusly_articles');

        return in_array($column, $columns, true);
    }

    private function uniqueSlug(string $slug, string $sourceId): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (ArguslyArticle::query()
            ->where('slug', $candidate)
            ->where('source_argusly_id', '!=', $sourceId)
            ->exists()) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
