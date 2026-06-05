<?php

namespace App\Services\ContentChain;

use App\Enums\ContentOriginType;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentChainSuggestion;
use App\Models\User;
use App\Services\Brief\BriefDefaultBuilder;
use App\Services\Content\ContentDeduplicationService;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\TitleSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChainedContentCreationService
{
    public function __construct(
        private readonly BriefDefaultBuilder $briefDefaultBuilder,
        private readonly ContentDeduplicationService $contentDeduplicationService,
    ) {}

    public function createFromSuggestion(ContentChainSuggestion $suggestion, User $user): Content
    {
        $suggestion->loadMissing('sourceContent.clientSite.workspace');

        $sourceContent = $suggestion->sourceContent;
        $site = $sourceContent?->clientSite;
        $workspace = $site?->workspace;

        if (! $sourceContent || ! $site || ! $workspace) {
            throw new \RuntimeException('Source content is not linked to an active workspace site.');
        }

        $rawTitle = trim((string) ($suggestion->title ?: data_get($suggestion->meta, 'target_keyword', '')));
        $titleResult = TitleSanitizer::normalizeWithMetadata($rawTitle, fallback: 'Untitled chained article');
        $title = $titleResult['title'];
        if ($title === '') {
            throw new \RuntimeException('Suggestion is missing a usable title.');
        }

        if ($titleResult['was_shortened']) {
            Log::notice('content_chain.title_shortened', [
                'suggestion_id' => (string) $suggestion->id,
                'source_content_id' => (string) ($sourceContent->id ?? ''),
                'original_length' => $titleResult['original_length'],
                'persisted_length' => $titleResult['persisted_length'],
                'max_length' => $titleResult['max_length'],
            ]);
        }

        $primaryKeyword = trim((string) data_get($suggestion->meta, 'target_keyword', $title));
        $briefDefaults = $this->briefDefaultBuilder->build($title, $primaryKeyword);
        $requestedLocale = $sourceContent->localeCode();

        return DB::transaction(function () use ($suggestion, $sourceContent, $site, $workspace, $user, $title, $titleResult, $primaryKeyword, $briefDefaults, $requestedLocale): Content {
            $externalKey = 'content-chain-' . $suggestion->id;

            $contentPayload = ContentPersistencePayloadNormalizer::normalize([
                'id' => (string) Str::uuid(),
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $site->id,
                'series_id' => $sourceContent->series_id,
                'title' => $title,
                'language' => $requestedLocale,
                'translation_source_locale' => null,
                'is_source_locale' => true,
                'primary_keyword' => $primaryKeyword,
                'type' => 'article',
                'status' => 'brief',
                'source' => 'content_chain',
                'origin_type' => ContentOriginType::CHAINED->value,
                'source_chain_suggestion_id' => (string) $suggestion->id,
                'external_key' => $externalKey,
                'publish_status' => 'draft',
                'generation_mode' => 'balanced',
                'preferred_length' => 'medium',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $content = $this->contentDeduplicationService->createOrReuse($contentPayload, [
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $site->id,
                'source_content_id' => (string) $sourceContent->id,
                'source_chain_suggestion_id' => (string) $suggestion->id,
                'language' => $requestedLocale,
                'type' => 'article',
                'external_key' => $externalKey,
            ]);

            $brief = Brief::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            if (! $brief) {
                Brief::query()->create(ContentPersistencePayloadNormalizer::normalizeBrief([
                'client_site_id' => (string) $site->id,
                'created_by_user_id' => $user->id,
                'content_id' => (string) $content->id,
                'status' => 'draft',
                'source' => 'content_chain',
                'title' => $title,
                'language' => $requestedLocale,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
                'primary_keyword' => $primaryKeyword,
                'intent' => (string) data_get($suggestion->meta, 'target_intent', $briefDefaults['intent']['type']),
                'audience' => (string) data_get($suggestion->meta, 'target_audience', $briefDefaults['audience']['persona']),
                'funnel_stage' => $briefDefaults['search_context']['stage'],
                'search_intent' => 'informational',
                'progress' => 0,
                'client_refs' => [
                    'client_type' => 'content_chain',
                    'site_url' => (string) ($site->site_url ?? ''),
                    'chain_source_content_id' => (string) $sourceContent->id,
                    'chain_suggestion_id' => (string) $suggestion->id,
                    'original_title' => $titleResult['was_shortened'] ? $titleResult['original_title'] : null,
                    'title_shortened' => $titleResult['was_shortened'],
                ],
                'wp_site_id' => (string) $site->id,
                ]));
            }

            $suggestion->update([
                'status' => ContentChainSuggestion::STATUS_CONVERTED,
                'generated_content_id' => (string) $content->id,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
            ]);

            return $content;
        });
    }
}
