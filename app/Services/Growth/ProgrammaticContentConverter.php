<?php

namespace App\Services\Growth;

use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Enums\GrowthAssetType;
use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticDraftReview;
use App\Services\Content\ContentLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ProgrammaticContentConverter
{
    public function __construct(private readonly ContentLifecycleService $lifecycle)
    {
    }

    public function convertReview(ProgrammaticDraftReview $review, ?int $createdByUserId = null): Content
    {
        $review->loadMissing(['request.blueprint', 'draft', 'brief.clientSite', 'cluster', 'item', 'growthProgram']);

        if ($existing = $this->existingContentFor($review)) {
            $this->restoreLinks($review, $existing);

            return $existing->refresh();
        }

        if ($review->status !== ProgrammaticDraftReview::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved programmatic draft reviews can be converted to content.');
        }

        if (! $review->draft instanceof Draft) {
            throw new RuntimeException('Programmatic draft review has no linked Draft.');
        }

        if (ContentPublication::query()->where('content_id', $review->draft->content_id)->exists()) {
            throw new InvalidArgumentException('Programmatic content conversion does not operate on already published content.');
        }

        return DB::transaction(function () use ($review, $createdByUserId): Content {
            $content = $this->createContent($review, $createdByUserId);
            $this->restoreLinks($review, $content);

            $this->lifecycle->ensureRevisionFromDraft($review->draft->refresh(), $createdByUserId);

            $review->forceFill([
                'metadata' => array_replace_recursive((array) $review->metadata, [
                    'converted_content_id' => (string) $content->id,
                    'converted_at' => now()->toIso8601String(),
                    'content_external_key' => $this->externalKey($review),
                ]),
            ])->save();

            return $content->refresh();
        });
    }

    public function convertApprovedReviewsForCluster(ProgrammaticCluster $cluster, ?int $createdByUserId = null): int
    {
        return ProgrammaticDraftReview::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->where('status', ProgrammaticDraftReview::STATUS_APPROVED)
            ->get()
            ->reduce(function (int $count, ProgrammaticDraftReview $review) use ($createdByUserId): int {
                $this->convertReview($review, $createdByUserId);

                return $count + 1;
            }, 0);
    }

    public function convertApprovedReviewsForProgram(GrowthProgram $program, ?int $createdByUserId = null): int
    {
        return ProgrammaticDraftReview::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftReview::STATUS_APPROVED)
            ->get()
            ->reduce(function (int $count, ProgrammaticDraftReview $review) use ($createdByUserId): int {
                $this->convertReview($review, $createdByUserId);

                return $count + 1;
            }, 0);
    }

    public function existingContentFor(ProgrammaticDraftReview $review): ?Content
    {
        $contentId = (string) data_get($review->metadata, 'converted_content_id', '');
        if ($contentId !== '') {
            $content = Content::query()->whereKey($contentId)->first();
            if ($content) {
                return $content;
            }
        }

        if ($review->draft?->content_id) {
            $content = Content::query()->whereKey($review->draft->content_id)->first();
            if ($content && (string) $content->external_key === $this->externalKey($review)) {
                return $content;
            }
        }

        return Content::query()
            ->where('workspace_id', $review->workspace_id)
            ->where('external_key', $this->externalKey($review))
            ->first();
    }

    private function createContent(ProgrammaticDraftReview $review, ?int $createdByUserId): Content
    {
        $draft = $review->draft;
        $brief = $review->brief ?: $review->request?->brief;
        $assetType = $review->growth_asset_type instanceof GrowthAssetType
            ? $review->growth_asset_type
            : GrowthAssetType::tryFrom((string) $review->growth_asset_type);
        $schema = collect((array) data_get($draft?->meta, 'schema_recommendations', []))->first()
            ?: $draft?->schema_type
            ?: collect((array) data_get($brief?->client_refs, 'schema_recommendations', []))->first();

        return Content::query()->create([
            'workspace_id' => (string) $review->workspace_id,
            'client_site_id' => $draft?->client_site_id ?: $brief?->client_site_id,
            'content_destination_id' => $draft?->content_destination_id ?: $brief?->content_destination_id,
            'title' => $draft?->title ?: $review->request?->title ?: $brief?->title ?: 'Untitled programmatic content',
            'language' => $this->language($draft, $brief),
            'seo_title' => $draft?->seo_title ?: $draft?->title ?: $brief?->title,
            'seo_meta_description' => $draft?->seo_meta_description ?: $this->excerpt((string) $draft?->content_html),
            'public_blog_excerpt' => $this->excerpt((string) $draft?->content_html),
            'seo_h1' => $draft?->seo_h1 ?: $draft?->title ?: $brief?->title,
            'seo_og_title' => $draft?->seo_og_title ?: $draft?->seo_title ?: $draft?->title,
            'seo_og_description' => $draft?->seo_og_description ?: $draft?->seo_meta_description,
            'seo_twitter_title' => $draft?->seo_twitter_title ?: $draft?->seo_title ?: $draft?->title,
            'seo_twitter_description' => $draft?->seo_twitter_description ?: $draft?->seo_meta_description,
            'robots_index' => $draft?->robots_index,
            'robots_follow' => $draft?->robots_follow,
            'schema_type' => $schema ?: null,
            'primary_keyword' => data_get($draft?->meta, 'primary_keyword') ?: $brief?->primary_keyword,
            'intent_keys' => array_values(array_filter([(string) (data_get($draft?->meta, 'intent') ?: $brief?->intent)])),
            'internal_links_meta' => [
                'plan' => data_get($draft?->meta, 'internal_linking_plan', []),
                'role' => $review->item?->internal_linking_role,
                'source' => 'programmatic_content_conversion',
            ],
            'type' => $this->contentTypeFor($assetType, (string) ($draft?->output_type ?: $brief?->output_type ?: $brief?->content_type)),
            'status' => 'draft',
            'source' => ContentSource::API->value,
            'origin_type' => ContentOriginType::AUTOMATION->value,
            'external_id' => $this->externalId($review),
            'external_key' => $this->externalKey($review),
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
            'canonical_url_key' => $review->request?->slug ?: data_get($brief?->client_refs, 'slug'),
            'generation_mode' => $review->request?->generation_mode ?: 'programmatic',
            'preferred_length' => $this->preferredLength($assetType),
            'actual_word_count' => str_word_count(strip_tags((string) $draft?->content_html)),
            'ai_visibility_score' => (int) round((float) $review->ai_visibility_score),
            'content_health_score' => (int) round((float) $review->overall_score),
            'semantic_coverage_score' => (int) round((float) $review->completeness_score),
            'internal_link_score' => (int) round((float) $review->internal_linking_score),
            'aeo_breakdown' => [
                'growth_asset_type' => $assetType?->value ?? (string) $review->growth_asset_type,
                'schema_recommendations' => data_get($draft?->meta, 'schema_recommendations', []),
                'seo_requirements' => data_get($draft?->meta, 'seo_requirements', []),
                'ai_visibility_requirements' => data_get($draft?->meta, 'ai_visibility_requirements', []),
                'cta_recommendation' => data_get($draft?->meta, 'call_to_action') ?: $brief?->call_to_action,
                'programmatic_metadata' => $this->programmaticReferences($review),
            ],
            'created_by' => $createdByUserId,
            'updated_by' => $createdByUserId,
        ]);
    }

    private function restoreLinks(ProgrammaticDraftReview $review, Content $content): void
    {
        $review->loadMissing(['draft', 'brief', 'request.blueprint']);

        if ($review->draft && (string) $review->draft->content_id !== (string) $content->id) {
            $review->draft->forceFill(['content_id' => (string) $content->id])->save();
        }

        if ($review->brief && (string) $review->brief->content_id !== (string) $content->id) {
            $refs = is_array($review->brief->client_refs) ? $review->brief->client_refs : [];
            $refs['converted_content_id'] = (string) $content->id;
            $refs['programmatic_draft_review_id'] = (string) $review->id;
            $review->brief->forceFill([
                'content_id' => (string) $content->id,
                'client_refs' => $refs,
            ])->save();
        }

        $review->forceFill([
            'metadata' => array_replace_recursive((array) $review->metadata, [
                'converted_content_id' => (string) $content->id,
                'content_external_key' => $this->externalKey($review),
            ]),
        ])->save();
    }

    private function contentTypeFor(?GrowthAssetType $type, string $fallback): string
    {
        return match ($type) {
            GrowthAssetType::LANDING_PAGE,
            GrowthAssetType::INDUSTRY_PAGE,
            GrowthAssetType::LOCATION_PAGE,
            GrowthAssetType::COMPARISON_PAGE,
            GrowthAssetType::ALTERNATIVE_PAGE,
            GrowthAssetType::FAQ_PAGE,
            GrowthAssetType::AI_ANSWER_PAGE,
            GrowthAssetType::PILLAR_PAGE,
            GrowthAssetType::INTEGRATION_PAGE,
            GrowthAssetType::FEATURE_PAGE => 'seo_page',
            default => $this->lifecycle->mapOutputTypeToContentType($fallback ?: 'article'),
        };
    }

    private function language(?Draft $draft, mixed $brief): string
    {
        $value = $draft?->language instanceof SupportedLanguage
            ? $draft->language->value
            : (string) ($draft?->language ?: $brief?->language ?: 'nl');

        return SupportedLanguage::tryFromString($value)?->value ?? SupportedLanguage::NL->value;
    }

    private function excerpt(string $html): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?: '');

        return $text !== '' ? Str::limit($text, 180, '') : null;
    }

    private function preferredLength(?GrowthAssetType $type): string
    {
        return match ($type) {
            GrowthAssetType::PILLAR_PAGE => 'long',
            GrowthAssetType::FAQ_PAGE,
            GrowthAssetType::AI_ANSWER_PAGE,
            GrowthAssetType::STRUCTURED_ANSWER,
            GrowthAssetType::SCHEMA_MARKUP => 'short',
            default => 'medium',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function programmaticReferences(ProgrammaticDraftReview $review): array
    {
        return [
            'programmatic_draft_review_id' => (string) $review->id,
            'programmatic_draft_request_id' => (string) $review->programmatic_draft_request_id,
            'programmatic_brief_blueprint_id' => $review->request?->programmatic_brief_blueprint_id ? (string) $review->request->programmatic_brief_blueprint_id : null,
            'programmatic_cluster_id' => $review->programmatic_cluster_id ? (string) $review->programmatic_cluster_id : null,
            'programmatic_cluster_item_id' => $review->programmatic_cluster_item_id ? (string) $review->programmatic_cluster_item_id : null,
            'growth_program_id' => $review->growth_program_id ? (string) $review->growth_program_id : null,
            'draft_id' => $review->draft_id ? (string) $review->draft_id : null,
            'brief_id' => $review->brief_id ? (string) $review->brief_id : null,
        ];
    }

    private function externalId(ProgrammaticDraftReview $review): string
    {
        return 'programmatic-review-'.$review->id;
    }

    private function externalKey(ProgrammaticDraftReview $review): string
    {
        return 'programmatic-draft-review-'.$review->id;
    }
}
