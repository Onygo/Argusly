<?php

namespace App\Services\Content;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Event;
use App\Support\SeoMetadata;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentLifecycleService
{
    public function findOrCreateFromWpPayload(ClientSite $clientSite, array $briefData, array $clientData): Content
    {
        $externalId = (string) ($clientData['wp_brief_id'] ?? '');
        $wpPostId = trim((string) ($clientData['wp_post_id'] ?? ''));
        $externalKey = $wpPostId !== '' ? $wpPostId : ($externalId !== '' ? $externalId : (string) Str::uuid());

        return DB::transaction(function () use ($clientSite, $briefData, $externalId, $externalKey, $wpPostId): Content {
            $content = Content::query()
                ->where('client_site_id', $clientSite->id)
                ->where('external_key', $externalKey)
                ->first();

            if (! $content) {
                $content = Content::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $clientSite->workspace_id,
                    'client_site_id' => $clientSite->id,
                    'title' => (string) ($briefData['title'] ?? 'Untitled'),
                    'primary_keyword' => (string) ($briefData['primary_keyword'] ?? ''),
                    'robots_index' => data_get($briefData, 'robots_index'),
                    'robots_follow' => data_get($briefData, 'robots_follow'),
                    'schema_type' => data_get($briefData, 'schema_type'),
                    'type' => $this->mapOutputTypeToContentType((string) ($briefData['output_type'] ?? 'article')),
                    'status' => 'brief',
                    'source' => 'wp',
                    'external_id' => $externalId !== '' ? $externalId : null,
                    'external_key' => $externalKey,
                    'wp_post_id' => $wpPostId !== '' ? $wpPostId : null,
                    'delivery_status' => 'pending',
                    'generation_mode' => 'balanced',
                    'brand_voice_id' => $briefData['brand_voice_id'] ?? null,
                    'buyer_persona_id' => $briefData['buyer_persona_id'] ?? null,
                    'team_member_id' => $briefData['team_member_id'] ?? null,
                    'preferred_length' => $briefData['preferred_length'] ?? 'medium',
                ]);
            } else {
                $updates = [
                    'title' => (string) ($briefData['title'] ?? $content->title),
                    'primary_keyword' => (string) ($briefData['primary_keyword'] ?? $content->primary_keyword),
                    'robots_index' => data_get($briefData, 'robots_index', $content->robots_index),
                    'robots_follow' => data_get($briefData, 'robots_follow', $content->robots_follow),
                    'schema_type' => ((string) data_get($briefData, 'schema_type', $content->schema_type)) ?: null,
                    'type' => $this->mapOutputTypeToContentType((string) ($briefData['output_type'] ?? $content->type)),
                    'status' => $content->current_version_id ? $content->status : 'brief',
                    'external_key' => $content->external_key ?: $externalKey,
                    'wp_post_id' => $content->wp_post_id ?: ($wpPostId !== '' ? $wpPostId : null),
                    'client_site_id' => $content->client_site_id ?: $clientSite->id,
                ];

                if (array_key_exists('brand_voice_id', $briefData)) {
                    $updates['brand_voice_id'] = $briefData['brand_voice_id'] ?: null;
                }
                if (array_key_exists('buyer_persona_id', $briefData)) {
                    $updates['buyer_persona_id'] = $briefData['buyer_persona_id'] ?: null;
                }
                if (array_key_exists('team_member_id', $briefData)) {
                    $updates['team_member_id'] = $briefData['team_member_id'] ?: null;
                }
                if (array_key_exists('preferred_length', $briefData)) {
                    $updates['preferred_length'] = $briefData['preferred_length'] ?: 'medium';
                }

                $content->update($updates);
            }

            $briefVersion = ContentVersion::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $content->id,
                'type' => 'brief',
                'body' => json_encode($briefData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'meta' => [
                    'payload' => $briefData,
                ],
                'source' => 'wp',
            ]);

            Event::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => $clientSite->id,
                'type' => 'content.brief.received',
                'occurred_at' => now(),
                'data' => [
                    'content_id' => $content->id,
                    'version_id' => $briefVersion->id,
                    'external_key' => $externalKey,
                ],
            ]);

            ContentPublishTarget::query()->updateOrCreate(
                [
                    'content_id' => $content->id,
                    'client_site_id' => $clientSite->id,
                    'target_type' => 'wp',
                ],
                [
                    'target_identifier' => $content->external_key,
                    'wp_post_id' => $content->wp_post_id,
                    'sync_status' => 'pending',
                ],
            );

            return $content;
        });
    }

    public function attachBriefToContent(Brief $brief, Content $content): void
    {
        $brief->update(['content_id' => $content->id]);
    }

    public function ensureRevisionFromDraft(Draft $draft, ?int $createdByUserId = null): ContentRevision
    {
        if (! $draft->content_id) {
            throw new \RuntimeException('Draft has no content_id.');
        }

        return DB::transaction(function () use ($draft, $createdByUserId): ContentRevision {
            $content = Content::query()->lockForUpdate()->findOrFail($draft->content_id);
            $content->loadMissing('seo');

            $latestNumber = (int) ContentRevision::query()
                ->where('content_id', $content->id)
                ->max('revision_number');

            $revision = ContentRevision::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $content->id,
                'draft_id' => $draft->id,
                'revision_number' => $latestNumber + 1,
                'label' => 'R' . ($latestNumber + 1),
                'content_html' => $this->cleanDraftContentHtml($draft),
                'meta' => (array) ($draft->meta ?? []),
                'is_active' => true,
                'created_by_user_id' => $createdByUserId,
            ]);

            ContentRevision::query()
                ->where('content_id', $content->id)
                ->where('id', '!=', $revision->id)
                ->update(['is_active' => false]);

            $draftMeta = is_array($draft->meta) ? $draft->meta : [];
            $seoFields = SeoMetadata::merge(
                [
                    'seo_title' => $draft->seo_title ?: $draft->title,
                    'seo_meta_description' => $draft->seo_meta_description,
                    'seo_h1' => $draft->seo_h1,
                    'seo_canonical' => $draft->seo_canonical,
                    'seo_og_title' => $draft->seo_og_title,
                    'seo_og_description' => $draft->seo_og_description,
                    'seo_og_image' => $draft->seo_og_image,
                    'seo_twitter_title' => $draft->seo_twitter_title,
                    'seo_twitter_description' => $draft->seo_twitter_description,
                    'robots_index' => $draft->robots_index,
                    'robots_follow' => $draft->robots_follow,
                    'schema_type' => $draft->schema_type,
                ],
                $draftMeta,
            );
            if (trim((string) ($seoFields['seo_h1'] ?? '')) === '') {
                $seoFields['seo_h1'] = $draft->title ?: $content->title;
            }

            $content->update([
                'current_revision_id' => $revision->id,
                'status' => 'draft',
                'delivery_status' => $draft->delivery_status ?: $content->delivery_status,
                'title' => $draft->title ?: $content->title,
                'seo_title' => $seoFields['seo_title'] ?: ($draft->title ?: $content->title),
                'seo_meta_description' => $seoFields['seo_meta_description'] ?: $content->seo_meta_description,
                'seo_h1' => $seoFields['seo_h1'] ?: $content->seo_h1,
                'seo_canonical' => $seoFields['seo_canonical'] ?: $content->seo_canonical,
                'seo_og_title' => $seoFields['seo_og_title'] ?: $content->seo_og_title,
                'seo_og_description' => $seoFields['seo_og_description'] ?: $content->seo_og_description,
                'seo_og_image' => $seoFields['seo_og_image'] ?: $content->seo_og_image,
                'seo_twitter_title' => $seoFields['seo_twitter_title'] ?: $content->seo_twitter_title,
                'seo_twitter_description' => $seoFields['seo_twitter_description'] ?: $content->seo_twitter_description,
                'robots_index' => $seoFields['robots_index'] ?? $content->robots_index,
                'robots_follow' => $seoFields['robots_follow'] ?? $content->robots_follow,
                'schema_type' => $seoFields['schema_type'] ?: $content->schema_type,
                'updated_by' => $createdByUserId,
            ]);

            // Transitional mirror: write legacy content_seo from canonical typed SEO values.
            $canonicalSeo = SeoMetadata::resolveForContentContext($content);
            ContentSeo::query()->updateOrCreate(
                ['content_id' => $content->id],
                [
                    'meta_title' => $canonicalSeo['seo_title'] ?: ($draft->title ?: $content->title),
                    'meta_description' => $canonicalSeo['seo_meta_description'] ?: null,
                    'primary_keyword' => $canonicalSeo['primary_keyword'] ?: null,
                    'robots_index' => $canonicalSeo['robots_index'],
                    'robots_follow' => $canonicalSeo['robots_follow'],
                    'schema_type' => $canonicalSeo['schema_type'] ?: null,
                ],
            );

            $this->createVersionFromDraft($content, $draft, $createdByUserId);

            try {
                app(LocaleMismatchService::class)->autoCorrectFromDraft(
                    $draft->fresh(['content.currentVersion', 'content.brief', 'content.drafts']) ?? $draft
                );
            } catch (\Throwable $exception) {
                Log::warning('content.lifecycle.locale_autocorrect_failed', [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) $content->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $revision;
        });
    }

    /**
     * @return array{revision:ContentRevision,version:ContentVersion,dirty_state_reason:string,editor_hash:string,saved_hash:string}
     */
    public function synchronizePublishedSnapshotFromDraft(Draft $draft, ?int $createdByUserId = null): array
    {
        if (! $draft->content_id) {
            throw new \RuntimeException('Draft has no content_id.');
        }

        return DB::transaction(function () use ($draft, $createdByUserId): array {
            $content = Content::query()
                ->with(['currentVersion', 'currentRevision', 'seo'])
                ->lockForUpdate()
                ->findOrFail($draft->content_id);

            $draftHash = $this->draftStateHash($draft);
            $savedHash = trim((string) data_get($content->currentVersion?->meta, 'draft_hash', ''));
            $currentVersionDraftId = trim((string) data_get($content->currentVersion?->meta, 'draft_id', ''));

            if ($currentVersionDraftId === (string) $draft->id && $savedHash !== '' && hash_equals($savedHash, $draftHash)) {
                $revision = $content->currentRevision ?: $this->createPublishedRevisionFromDraft($content, $draft, $createdByUserId);
                $version = $content->currentVersion ?: $this->createPublishedVersionFromDraft($content, $draft, $createdByUserId, $draftHash);

                Log::info('content.publish_snapshot_sync', [
                    'content_id' => (string) $content->id,
                    'draft_id' => (string) $draft->id,
                    'dirty_state_reason' => 'already_synchronized',
                    'editor_hash' => $draftHash,
                    'saved_hash' => $savedHash,
                    'published_revision_id' => (string) $revision->id,
                    'latest_draft_revision_id' => (string) $draft->id,
                    'pending_mutation_count' => 0,
                ]);

                return [
                    'revision' => $revision,
                    'version' => $version,
                    'dirty_state_reason' => 'already_synchronized',
                    'editor_hash' => $draftHash,
                    'saved_hash' => $savedHash,
                ];
            }

            $revision = $this->createPublishedRevisionFromDraft($content, $draft, $createdByUserId);
            $version = $this->createPublishedVersionFromDraft($content, $draft, $createdByUserId, $draftHash);
            $seoFields = $this->resolvedSeoFieldsFromDraft($draft, $content);

            $content->forceFill([
                'current_revision_id' => (string) $revision->id,
                'current_version_id' => (string) $version->id,
                'title' => $draft->title ?: $content->title,
                'seo_title' => $seoFields['seo_title'] ?: ($draft->title ?: $content->title),
                'seo_meta_description' => $seoFields['seo_meta_description'] ?: $content->seo_meta_description,
                'seo_h1' => $seoFields['seo_h1'] ?: $content->seo_h1,
                'seo_canonical' => $seoFields['seo_canonical'] ?: $content->seo_canonical,
                'seo_og_title' => $seoFields['seo_og_title'] ?: $content->seo_og_title,
                'seo_og_description' => $seoFields['seo_og_description'] ?: $content->seo_og_description,
                'seo_og_image' => $seoFields['seo_og_image'] ?: $content->seo_og_image,
                'seo_twitter_title' => $seoFields['seo_twitter_title'] ?: $content->seo_twitter_title,
                'seo_twitter_description' => $seoFields['seo_twitter_description'] ?: $content->seo_twitter_description,
                'robots_index' => $seoFields['robots_index'] ?? $content->robots_index,
                'robots_follow' => $seoFields['robots_follow'] ?? $content->robots_follow,
                'schema_type' => $seoFields['schema_type'] ?: $content->schema_type,
                'updated_by' => $createdByUserId,
            ])->save();

            $canonicalSeo = SeoMetadata::resolveForContentContext($content);
            ContentSeo::query()->updateOrCreate(
                ['content_id' => $content->id],
                [
                    'meta_title' => $canonicalSeo['seo_title'] ?: ($draft->title ?: $content->title),
                    'meta_description' => $canonicalSeo['seo_meta_description'] ?: null,
                    'primary_keyword' => $canonicalSeo['primary_keyword'] ?: null,
                    'robots_index' => $canonicalSeo['robots_index'],
                    'robots_follow' => $canonicalSeo['robots_follow'],
                    'schema_type' => $canonicalSeo['schema_type'] ?: null,
                ],
            );

            Log::info('content.publish_snapshot_sync', [
                'content_id' => (string) $content->id,
                'draft_id' => (string) $draft->id,
                'dirty_state_reason' => 'published_snapshot_synchronized',
                'editor_hash' => $draftHash,
                'saved_hash' => $draftHash,
                'published_revision_id' => (string) $revision->id,
                'latest_draft_revision_id' => (string) $draft->id,
                'pending_mutation_count' => 0,
            ]);

            return [
                'revision' => $revision,
                'version' => $version,
                'dirty_state_reason' => 'published_snapshot_synchronized',
                'editor_hash' => $draftHash,
                'saved_hash' => $draftHash,
            ];
        });
    }

    public function setActiveRevision(Content $content, ContentRevision $revision): void
    {
        if ((string) $revision->content_id !== (string) $content->id) {
            throw new \RuntimeException('Revision does not belong to content.');
        }

        DB::transaction(function () use ($content, $revision): void {
            ContentRevision::query()->where('content_id', $content->id)->update(['is_active' => false]);
            $revision->update(['is_active' => true]);
            $content->update(['current_revision_id' => $revision->id, 'status' => 'draft']);
        });
    }

    private function createVersionFromDraft(Content $content, Draft $draft, ?int $createdByUserId = null): ContentVersion
    {
        $priorVersionId = $content->current_version_id ?: optional($content->briefVersion()->latest('created_at')->first())->id;
        $type = $content->current_version_id ? 'revision' : 'draft';

        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'type' => $type,
            'parent_version_id' => $priorVersionId,
            'body' => $this->cleanDraftContentHtml($draft),
            'meta' => [
                'draft_id' => $draft->id,
                'provider' => (string) data_get($draft->meta, 'generation.provider', ''),
                'model' => (string) data_get($draft->meta, 'generation.model', ''),
                'tokens' => data_get($draft->meta, 'generation.tokens'),
                'input_tokens' => data_get($draft->meta, 'generation.input_tokens'),
                'output_tokens' => data_get($draft->meta, 'generation.output_tokens'),
                'request_id' => (string) data_get($draft->meta, 'generation.request_id', ''),
                'credits' => $draft->credit_cost,
                'draft_meta' => $draft->meta,
            ],
            'source' => 'pl',
            'created_by' => $createdByUserId,
        ]);

        $content->update([
            'current_version_id' => $version->id,
            'status' => 'draft',
        ]);

        return $version;
    }

    private function createPublishedRevisionFromDraft(Content $content, Draft $draft, ?int $createdByUserId = null): ContentRevision
    {
        $latestNumber = (int) ContentRevision::query()
            ->where('content_id', $content->id)
            ->max('revision_number');

        ContentRevision::query()
            ->where('content_id', $content->id)
            ->update(['is_active' => false]);

        return ContentRevision::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'draft_id' => $draft->id,
            'revision_number' => $latestNumber + 1,
            'label' => 'R' . ($latestNumber + 1),
            'content_html' => $this->cleanDraftContentHtml($draft),
            'meta' => array_merge((array) ($draft->meta ?? []), [
                'publish_synchronized' => true,
                'draft_hash' => $this->draftStateHash($draft),
            ]),
            'is_active' => true,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    private function createPublishedVersionFromDraft(Content $content, Draft $draft, ?int $createdByUserId, string $draftHash): ContentVersion
    {
        return ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_PUBLISHED_SNAPSHOT,
            'parent_version_id' => $content->current_version_id ?: optional($content->briefVersion()->latest('created_at')->first())->id,
            'body' => $this->cleanDraftContentHtml($draft),
            'meta' => [
                'draft_id' => (string) $draft->id,
                'draft_hash' => $draftHash,
                'published_snapshot' => true,
                'provider' => (string) data_get($draft->meta, 'generation.provider', ''),
                'model' => (string) data_get($draft->meta, 'generation.model', ''),
                'request_id' => (string) data_get($draft->meta, 'generation.request_id', ''),
                'draft_meta' => $draft->meta,
            ],
            'source' => ContentVersion::SOURCE_ARGUSLY,
            'created_by' => $createdByUserId,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolvedSeoFieldsFromDraft(Draft $draft, Content $content): array
    {
        $draftMeta = is_array($draft->meta) ? $draft->meta : [];
        $seoFields = SeoMetadata::merge(
            [
                'seo_title' => $draft->seo_title ?: $draft->title,
                'seo_meta_description' => $draft->seo_meta_description,
                'seo_h1' => $draft->seo_h1,
                'seo_canonical' => $draft->seo_canonical,
                'seo_og_title' => $draft->seo_og_title,
                'seo_og_description' => $draft->seo_og_description,
                'seo_og_image' => $draft->seo_og_image,
                'seo_twitter_title' => $draft->seo_twitter_title,
                'seo_twitter_description' => $draft->seo_twitter_description,
                'robots_index' => $draft->robots_index,
                'robots_follow' => $draft->robots_follow,
                'schema_type' => $draft->schema_type,
            ],
            $draftMeta,
        );

        if (trim((string) ($seoFields['seo_h1'] ?? '')) === '') {
            $seoFields['seo_h1'] = $draft->title ?: $content->title;
        }

        return $seoFields;
    }

    private function draftStateHash(Draft $draft): string
    {
        return hash('sha256', json_encode([
            'content_html' => $this->cleanDraftContentHtml($draft),
            'title' => trim((string) ($draft->title ?? '')),
            'seo_title' => trim((string) ($draft->seo_title ?? '')),
            'seo_meta_description' => trim((string) ($draft->seo_meta_description ?? '')),
            'seo_h1' => trim((string) ($draft->seo_h1 ?? '')),
            'seo_canonical' => trim((string) ($draft->seo_canonical ?? '')),
            'language' => (string) ($draft->language->value ?? $draft->language ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function cleanDraftContentHtml(Draft $draft): string
    {
        return (string) app(ContentRenderNormalizer::class)
            ->removeLegacyPlaceholderResources((string) ($draft->content_html ?? ''))['html'];
    }

    public function restoreVersion(Content $content, ContentVersion $version, ?int $userId = null): ContentVersion
    {
        if ((string) $version->content_id !== (string) $content->id) {
            throw new \RuntimeException('Version does not belong to content.');
        }

        return DB::transaction(function () use ($content, $version, $userId): ContentVersion {
            $restored = ContentVersion::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $content->id,
                'type' => 'revision',
                'parent_version_id' => $content->current_version_id,
                'body' => (string) ($version->body ?? ''),
                'meta' => array_merge((array) ($version->meta ?? []), ['restored_from' => $version->id]),
                'source' => 'pl',
                'created_by' => $userId,
            ]);

            $content->update([
                'current_version_id' => $restored->id,
                'status' => 'draft',
                'updated_by' => $userId,
            ]);

            try {
                app(LocaleMismatchService::class)->autoCorrectSourceLocale(
                    $content->fresh(['currentVersion', 'brief', 'drafts']) ?? $content
                );
            } catch (\Throwable $exception) {
                Log::warning('content.lifecycle.version_restore_locale_autocorrect_failed', [
                    'content_id' => (string) $content->id,
                    'version_id' => (string) $version->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $restored;
        });
    }

    public function mapOutputTypeToContentType(string $outputType): string
    {
        return match (strtolower($outputType)) {
            'kb_article', 'knowledge_base' => 'knowledge_base',
            'seo_page' => 'seo_page',
            'press_release' => 'press_release',
            default => 'article',
        };
    }
}
