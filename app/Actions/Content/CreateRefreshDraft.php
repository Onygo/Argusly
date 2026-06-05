<?php

namespace App\Actions\Content;

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Enums\DraftType;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreateRefreshDraft
{
    public function execute(Content $content, AgentRun $run, User $user): Draft
    {
        if ((string) $run->agent_key !== ContentRefreshAgent::KEY || (string) $run->content_id !== (string) $content->id) {
            throw new RuntimeException('The selected refresh recommendation does not belong to this content item.');
        }

        return DB::transaction(function () use ($content, $run, $user): Draft {
            $content = Content::query()
                ->lockForUpdate()
                ->with([
                    'brief',
                    'currentRevision',
                    'currentVersion',
                    'translationSourceContent.drafts' => fn ($query) => $query->latest('created_at'),
                    'drafts' => fn ($query) => $query->latest('created_at'),
                ])
                ->findOrFail($content->id);

            $latestDraft = $content->drafts->first();
            $refreshSnapshot = $this->refreshSnapshot($content);
            $existing = $this->findExistingRefreshDraft($content, $run, $refreshSnapshot);

            if ($existing) {
                return $existing;
            }

            $brief = $content->brief;
            if (! $brief) {
                $brief = Brief::query()->create([
                    'id' => (string) Str::uuid(),
                    'client_site_id' => (string) $content->client_site_id,
                    'content_destination_id' => $content->content_destination_id,
                    'created_by_user_id' => $user->id,
                    'content_id' => (string) $content->id,
                    'status' => 'draft',
                    'source' => 'content_refresh_agent',
                    'progress' => 0,
                    'title' => (string) ($content->title ?: 'Untitled content'),
                    'language' => $content->language?->value ?? 'en',
                    'content_type' => 'blog',
                    'output_type' => 'kb_article',
                    'primary_keyword' => $content->primary_keyword,
                    'client_refs' => [
                        'source' => 'content_refresh_agent',
                        'auto_created_from_content' => true,
                    ],
                ]);
            }

            $body = trim((string) (
                $content->currentRevision?->content_html
                ?: $content->currentVersion?->body
                ?: $latestDraft?->content_html
                ?: ''
            ));
            $lineage = $this->resolveDraftLineage($content, $latestDraft);

            return Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $brief->id,
                'content_id' => (string) $content->id,
                'client_site_id' => (string) $content->client_site_id,
                'content_destination_id' => $content->content_destination_id,
                'status' => 'generated',
                'title' => (string) ($content->title ?: $brief->title ?: 'Untitled content'),
                'seo_title' => (string) ($content->seo_title ?: $content->title ?: $brief->title ?: 'Untitled content'),
                'seo_meta_description' => $content->seo_meta_description,
                'seo_h1' => (string) ($content->seo_h1 ?: $content->title ?: $brief->title ?: 'Untitled content'),
                'seo_canonical' => $content->seo_canonical,
                'seo_og_title' => $content->seo_og_title,
                'seo_og_description' => $content->seo_og_description,
                'seo_og_image' => $content->seo_og_image,
                'seo_twitter_title' => $content->seo_twitter_title,
                'seo_twitter_description' => $content->seo_twitter_description,
                'robots_index' => $content->robots_index,
                'robots_follow' => $content->robots_follow,
                'schema_type' => $content->schema_type,
                'output_type' => (string) ($brief->output_type ?: 'kb_article'),
                'language' => $content->language?->value ?? 'en',
                'draft_type' => $lineage['draft_type']->value,
                'source_draft_id' => $lineage['source_draft_id'],
                'translation_source_language' => $lineage['translation_source_language'],
                'content_html' => $body,
                'delivery_status' => 'pending',
                'meta' => array_filter([
                    'source' => 'content_refresh_agent',
                    'refresh' => [
                        'agent_run_id' => (string) $run->id,
                        'created_from_content_id' => (string) $content->id,
                        'source_current_revision_id' => $refreshSnapshot['source_current_revision_id'],
                        'source_current_version_id' => $refreshSnapshot['source_current_version_id'],
                        'source_content_updated_at' => $refreshSnapshot['source_content_updated_at'],
                        'refresh_score' => data_get($run->output_payload, 'raw_payload.refresh_score'),
                        'urgency_level' => data_get($run->output_payload, 'raw_payload.urgency_level'),
                        'reasons' => data_get($run->output_payload, 'raw_payload.reasons', []),
                        'suggested_actions' => data_get($run->output_payload, 'raw_payload.suggested_actions', []),
                    ],
                ]),
            ]);
        });
    }

    /**
     * @param  array{source_current_revision_id:?string,source_current_version_id:?string,source_content_updated_at:?string}  $refreshSnapshot
     */
    private function findExistingRefreshDraft(Content $content, AgentRun $run, array $refreshSnapshot): ?Draft
    {
        return $content->drafts->first(function (Draft $draft) use ($content, $run, $refreshSnapshot): bool {
            if ((string) data_get($draft->meta, 'refresh.agent_run_id') === (string) $run->id) {
                return true;
            }

            if ((string) data_get($draft->meta, 'refresh.created_from_content_id') !== (string) $content->id) {
                return false;
            }

            if ((string) ($draft->delivery_status ?? '') === 'delivered') {
                return false;
            }

            return (string) data_get($draft->meta, 'refresh.source_current_revision_id') === (string) ($refreshSnapshot['source_current_revision_id'] ?? '')
                && (string) data_get($draft->meta, 'refresh.source_current_version_id') === (string) ($refreshSnapshot['source_current_version_id'] ?? '')
                && (string) data_get($draft->meta, 'refresh.source_content_updated_at') === (string) ($refreshSnapshot['source_content_updated_at'] ?? '');
        });
    }

    /**
     * @return array{source_current_revision_id:?string,source_current_version_id:?string,source_content_updated_at:?string}
     */
    private function refreshSnapshot(Content $content): array
    {
        return [
            'source_current_revision_id' => $content->current_revision_id ? (string) $content->current_revision_id : null,
            'source_current_version_id' => $content->current_version_id ? (string) $content->current_version_id : null,
            'source_content_updated_at' => $content->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{draft_type:DraftType,source_draft_id:?string,translation_source_language:?string}
     */
    private function resolveDraftLineage(Content $content, ?Draft $latestDraft): array
    {
        if ($latestDraft?->isTranslation()) {
            return [
                'draft_type' => DraftType::TRANSLATION,
                'source_draft_id' => $latestDraft->source_draft_id ? (string) $latestDraft->source_draft_id : null,
                'translation_source_language' => filled($latestDraft->translation_source_language)
                    ? (string) $latestDraft->translation_source_language
                    : null,
            ];
        }

        if (! $content->isTranslationVariant()) {
            return [
                'draft_type' => DraftType::ORIGINAL,
                'source_draft_id' => null,
                'translation_source_language' => null,
            ];
        }

        $sourceDraftId = $latestDraft?->source_draft_id
            ? (string) $latestDraft->source_draft_id
            : $this->resolveTranslationSourceDraftId($content);
        $translationSourceLanguage = trim((string) (
            $latestDraft?->translation_source_language
            ?: $content->translation_source_locale
            ?: $content->translationSourceContent?->localeCode()
            ?: ''
        ));

        if ($sourceDraftId === '' || $translationSourceLanguage === '') {
            throw new RuntimeException('Cannot create a refresh draft for translated content without translation lineage.');
        }

        return [
            'draft_type' => DraftType::TRANSLATION,
            'source_draft_id' => $sourceDraftId,
            'translation_source_language' => $translationSourceLanguage,
        ];
    }

    private function resolveTranslationSourceDraftId(Content $content): ?string
    {
        $sourceContent = $content->translationSourceContent;
        if (! $sourceContent) {
            return null;
        }

        $sourceDraft = $sourceContent->drafts->first();
        if (! $sourceDraft) {
            return null;
        }

        return (string) ($sourceDraft->getOriginalSourceDraft()?->id ?? $sourceDraft->id);
    }
}
