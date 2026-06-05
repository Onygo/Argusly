<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use App\Models\GeneratedAsset;
use App\Models\MarketingTask;
use App\Models\Newsletter;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialPublishing\SocialPublishingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ContentOperationsService
{
    public function __construct(
        private readonly ContentAssetService $contentAssets,
        private readonly ContentGenerationService $generation,
        private readonly ContentLifecycleService $lifecycle,
        private readonly MarketingTaskService $marketingTasks,
        private readonly NewsletterService $newsletters,
        private readonly SocialProfileService $socialProfiles,
        private readonly SocialPublishingService $socialPublishing,
        private readonly DomainEventService $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, Brand $brand): array
    {
        $this->assertTenant($account, $brand);

        return [
            'stats' => [
                'briefings_ready' => $this->briefingQuery($account, $brand)->whereIn('status', ['review', 'approved'])->count(),
                'content_plans' => $this->contentPlanQuery($account, $brand)->count(),
                'drafts' => $this->assetQuery($account, $brand)->whereIn('status', ['draft', 'review'])->count(),
                'generation_runs' => GeneratedAsset::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->count(),
                'publishing_queue' => $this->assetQuery($account, $brand)->whereIn('status', ['approved', 'scheduled'])->count(),
                'social_posts' => SocialPost::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->count(),
                'newsletters' => Newsletter::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->count(),
                'refresh_recommendations' => Recommendation::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('action_type', 'refresh_content')
                    ->open()
                    ->count(),
            ],
            'briefings' => $this->briefingQuery($account, $brand)->with(['campaign', 'creator'])->latest()->limit(8)->get(),
            'contentPlans' => $this->contentPlanQuery($account, $brand)->with(['campaign', 'related', 'creator'])->latest()->limit(8)->get(),
            'drafts' => $this->assetQuery($account, $brand)->whereIn('status', ['draft', 'review'])->with(['generatedAssets' => fn ($query) => $query->latest()->limit(1)])->latest()->limit(8)->get(),
            'generationRuns' => GeneratedAsset::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->with('contentAsset')->latest()->limit(8)->get(),
            'distributionQueue' => $this->assetQuery($account, $brand)->whereIn('status', ['approved', 'scheduled', 'published'])->with(['publishingActions', 'socialPosts'])->latest()->limit(8)->get(),
            'newsletters' => Newsletter::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->with('sections')->latest()->limit(8)->get(),
            'lifecycleScores' => ContentLifecycleScore::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->whereIn('status', ['decaying', 'needs_refresh', 'critical'])
                ->with('contentAsset')
                ->latest('scored_at')
                ->limit(8)
                ->get(),
        ];
    }

    public function createContentPlanFromBriefing(Briefing $briefing, User $user): MarketingTask
    {
        $this->assertTenant($briefing->account, $briefing->brand);

        $task = $this->marketingTasks->create($briefing->account, $briefing->brand, $user, [
            'scope' => 'brand',
            'campaign_id' => $briefing->campaign_id,
            'related_type' => null,
            'related_id' => null,
            'title' => 'Content plan: '.$briefing->title,
            'description' => $this->briefingSummary($briefing),
            'status' => 'todo',
            'priority' => $briefing->status === 'approved' ? 'high' : 'medium',
            'due_at' => now()->addWeek(),
        ]);

        $task->forceFill([
            'metadata' => [
                ...($task->metadata ?? []),
                'source' => 'briefing',
                'workflow' => 'briefing_to_content_plan',
                'briefing_id' => $briefing->id,
                'channels' => $briefing->channels ?? [],
                'languages' => $briefing->languages ?? [],
            ],
        ])->save();

        $this->events->recordForSubject('ContentPlanCreated', $task->refresh(), $user, [
            'briefing_id' => $briefing->id,
            'campaign_id' => $briefing->campaign_id,
        ]);

        return $task->refresh();
    }

    public function createDraftFromBriefing(Briefing $briefing, User $user): ContentAsset
    {
        $this->assertTenant($briefing->account, $briefing->brand);

        $language = collect($briefing->languages ?? [])->first() ?: $briefing->brand->default_content_language ?: 'en';
        $asset = $this->contentAssets->create($briefing->account, $briefing->brand, [
            'type' => $this->typeFromBriefing($briefing),
            'status' => 'draft',
            'title' => $briefing->title,
            'language' => $language,
            'locale' => app(ContentLanguageService::class)->localeForLanguage($language),
            'source' => 'briefing',
            'excerpt' => $briefing->objective,
            'body' => $this->draftBody($briefing),
            'metadata' => [
                'source' => 'briefing',
                'workflow' => 'briefing_to_draft',
                'briefing_id' => $briefing->id,
                'campaign_id' => $briefing->campaign_id,
                'audience' => $briefing->audience,
                'channels' => $briefing->channels ?? [],
            ],
        ], $user);

        if ($briefing->campaign_id !== null) {
            $asset->campaigns()->syncWithoutDetaching([$briefing->campaign_id]);
        }

        $this->events->recordForSubject('ContentDraftCreatedFromBriefing', $asset->refresh(), $user, [
            'briefing_id' => $briefing->id,
            'campaign_id' => $briefing->campaign_id,
        ]);

        return $asset->refresh();
    }

    public function requestDraftGeneration(ContentAsset $asset, User $user, string $type = 'refresh'): GeneratedAsset
    {
        $generated = $this->generation->requestForContentAsset($asset, $user, [
            'type' => $type,
            'prompt' => $this->generationPrompt($asset, $type),
            'language' => $asset->language,
        ]);

        $this->events->recordForSubject('ContentDraftGenerationRequested', $generated, $user, [
            'content_asset_id' => $asset->id,
            'type' => $type,
        ]);

        return $generated;
    }

    public function applyGeneratedDraft(ContentAsset $asset, GeneratedAsset $generatedAsset, User $user): ContentAsset
    {
        if ($generatedAsset->content_asset_id !== $asset->id || $generatedAsset->account_id !== $asset->account_id || $generatedAsset->brand_id !== $asset->brand_id) {
            throw new InvalidArgumentException('Generated draft must belong to the selected content asset.');
        }

        if (! in_array($generatedAsset->status, ['completed', 'approved'], true) || blank($generatedAsset->body)) {
            throw new InvalidArgumentException('Only completed generated drafts can be applied.');
        }

        $asset->forceFill([
            'title' => $generatedAsset->title ?: $asset->title,
            'excerpt' => str($generatedAsset->body)->limit(220)->toString(),
            'body' => $generatedAsset->body,
            'status' => 'review',
            'updated_by' => $user->id,
            'metadata' => [
                ...($asset->metadata ?? []),
                'latest_generated_asset_id' => $generatedAsset->id,
                'draft_applied_at' => now()->toDateTimeString(),
                'draft_applied_by' => $user->id,
            ],
        ])->save();

        $generatedAsset->forceFill([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();

        $this->events->recordForSubject('GeneratedDraftApplied', $asset->refresh(), $user, [
            'generated_asset_id' => $generatedAsset->id,
        ]);

        return $asset->refresh();
    }

    /**
     * @return array{social_post: SocialPost|null, newsletter: Newsletter|null}
     */
    public function prepareDistributionBundle(ContentAsset $asset, User $user): array
    {
        $profile = $this->socialProfile($user, $asset->account, $asset->brand);
        $socialPost = null;

        if ($profile !== null && ! $asset->socialPosts()->where('social_profile_id', $profile->id)->exists()) {
            $socialPost = $this->socialPublishing->prepare($asset->account, $asset->brand, $user, [
                'content_asset_id' => $asset->id,
                'campaign_id' => $asset->campaigns()->value('campaigns.id'),
                'social_profile_id' => $profile->id,
                'post_text' => $this->socialCopy($asset),
                'language' => $asset->language,
                'status' => 'draft',
                'metadata' => ['source' => 'content_distribution_bundle'],
            ]);
        }

        $newsletter = $this->newsletterForBundle($asset, $user);
        $this->ensureNewsletterSection($newsletter, $asset);

        $metadata = $asset->metadata ?? [];
        $metadata['distribution_bundle_prepared_at'] = now()->toDateTimeString();
        $metadata['distribution_bundle_prepared_by'] = $user->id;
        $asset->forceFill(['metadata' => $metadata])->save();

        $this->events->recordForSubject('ContentDistributionBundlePrepared', $asset->refresh(), $user, [
            'social_post_id' => $socialPost?->id,
            'newsletter_id' => $newsletter->id,
        ]);

        return ['social_post' => $socialPost, 'newsletter' => $newsletter->refresh()];
    }

    public function createRefreshRecommendation(ContentLifecycleScore $score, ?User $user = null): ?Recommendation
    {
        $asset = $score->contentAsset;

        if (! $asset || ! in_array($score->status, ['decaying', 'needs_refresh', 'critical'], true)) {
            return null;
        }

        $existing = Recommendation::query()
            ->where('account_id', $score->account_id)
            ->where('brand_id', $score->brand_id)
            ->where('action_type', 'refresh_content')
            ->where('title', 'Refresh '.$asset->title)
            ->whereIn('status', ['new', 'reviewed', 'accepted'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $recommendation = Recommendation::query()->create([
            'account_id' => $score->account_id,
            'brand_id' => $score->brand_id,
            'title' => 'Refresh '.$asset->title,
            'summary' => $score->reason,
            'recommended_action' => 'Refresh this content asset based on lifecycle signals.',
            'action_type' => 'refresh_content',
            'action_payload' => [
                'content_asset_id' => $asset->id,
                'content_lifecycle_score_id' => $score->id,
                'refresh_priority' => $score->refresh_priority,
                'signals' => $score->signals,
            ],
            'impact_score' => min(100, max(40, $score->refresh_priority)),
            'confidence_score' => 85,
            'status' => 'new',
        ]);

        $this->events->recordForSubject('LifecycleRefreshRecommendationCreated', $recommendation, $user, [
            'content_asset_id' => $asset->id,
            'content_lifecycle_score_id' => $score->id,
            'refresh_priority' => $score->refresh_priority,
        ]);

        return $recommendation->refresh();
    }

    private function assertTenant(Account $account, ?Brand $brand): void
    {
        if (! $brand || $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Content operations require a brand inside the current account.');
        }
    }

    private function briefingQuery(Account $account, Brand $brand): Builder
    {
        return Briefing::query()->where('account_id', $account->id)->where('brand_id', $brand->id);
    }

    private function contentPlanQuery(Account $account, Brand $brand): Builder
    {
        return MarketingTask::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('metadata->workflow', 'briefing_to_content_plan');
    }

    private function assetQuery(Account $account, Brand $brand): Builder
    {
        return ContentAsset::query()->where('account_id', $account->id)->where('brand_id', $brand->id);
    }

    private function typeFromBriefing(Briefing $briefing): string
    {
        $channels = $briefing->channels ?? [];

        return match (true) {
            in_array('email', $channels, true) => 'newsletter',
            in_array('paid', $channels, true) => 'landing_page',
            default => 'article',
        };
    }

    private function briefingSummary(Briefing $briefing): string
    {
        return trim(implode("\n\n", array_filter([
            $briefing->objective,
            $briefing->audience ? 'Audience: '.$briefing->audience : null,
            $briefing->key_message ? 'Key message: '.$briefing->key_message : null,
            $briefing->tone_of_voice ? 'Tone: '.$briefing->tone_of_voice : null,
        ])));
    }

    private function draftBody(Briefing $briefing): string
    {
        return trim(implode("\n\n", array_filter([
            $briefing->objective,
            $briefing->key_message,
            $briefing->audience ? 'Audience: '.$briefing->audience : null,
            $briefing->tone_of_voice ? 'Tone of voice: '.$briefing->tone_of_voice : null,
        ])));
    }

    private function generationPrompt(ContentAsset $asset, string $type): string
    {
        return "Create a {$type} draft for {$asset->title}. Keep the output aligned with Argusly brand intelligence, source context and the current content lifecycle signals.";
    }

    private function socialCopy(ContentAsset $asset): string
    {
        $summary = trim((string) ($asset->excerpt ?: Str::of($asset->body ?: $asset->title)->limit(180)));

        return "{$asset->title}\n\n{$summary}";
    }

    private function socialProfile(User $user, Account $account, Brand $brand): ?SocialProfile
    {
        return $this->socialProfiles->profilesFor($user, $account, $brand)
            ->first(fn (SocialProfile $profile) => $this->socialProfiles->canPrepare($user, $profile, $account, $brand));
    }

    private function newsletterForBundle(ContentAsset $asset, User $user): Newsletter
    {
        $newsletter = Newsletter::query()
            ->where('account_id', $asset->account_id)
            ->where('brand_id', $asset->brand_id)
            ->where('language', $asset->language)
            ->whereIn('status', ['draft', 'review'])
            ->latest()
            ->first();

        if ($newsletter) {
            return $newsletter;
        }

        return $this->newsletters->create($asset->account, $asset->brand, $user, [
            'title' => 'Content digest: '.$asset->title,
            'subject' => $asset->title,
            'preheader' => $asset->excerpt,
            'language' => $asset->language,
            'status' => 'draft',
        ]);
    }

    private function ensureNewsletterSection(Newsletter $newsletter, ContentAsset $asset): void
    {
        if ($newsletter->sections()->where('content_asset_id', $asset->id)->exists()) {
            return;
        }

        $this->newsletters->addSection($newsletter, [
            'type' => 'content_asset',
            'title' => $asset->title,
            'body' => $asset->excerpt,
            'content_asset_id' => $asset->id,
        ]);
    }
}
