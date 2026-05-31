<?php

namespace App\Services\SocialPublishing;

use App\Jobs\PublishSocialPostJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ContentLanguageService;
use App\Services\CreditService;
use App\Services\DomainEventService;
use App\Services\Integrations\LinkedIn\LinkedInPublishingService;
use App\Services\Integrations\LinkedIn\LinkedInTokenService;
use App\Services\MarketingCalendarService;
use App\Services\SocialProfiles\SocialProfileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SocialPublishingService
{
    public function __construct(
        private readonly SocialProfileService $profiles,
        private readonly ContentLanguageService $languages,
        private readonly CreditService $credits,
        private readonly ActivityLogger $activity,
        private readonly DomainEventService $events,
        private readonly MarketingCalendarService $calendar,
        private readonly LinkedInTokenService $linkedInTokens,
        private readonly LinkedInPublishingService $linkedInPublisher,
    ) {}

    /**
     * @param  array{content_asset_id?: int|null, campaign_id?: int|null, social_profile_id: int, post_text: string, media?: array<int, mixed>|null, metadata?: array<string, mixed>|null, language?: string|null, locale?: string|null, market?: string|null, status?: string|null}  $attributes
     */
    public function prepare(Account $account, Brand $brand, User $user, array $attributes): SocialPost
    {
        $profile = SocialProfile::query()->findOrFail($attributes['social_profile_id']);
        $this->assertTenant($account, $brand);
        $this->assertCanPrepare($user, $profile, $account, $brand);

        $contentAsset = $this->contentAsset($attributes['content_asset_id'] ?? null, $account, $brand);
        $campaign = $this->campaign($attributes['campaign_id'] ?? null, $account, $brand);
        $language = $this->languages->validateForBrand($attributes['language'] ?? $contentAsset?->language ?? $this->languages->defaultFor($brand, $account), $brand);

        return DB::transaction(function () use ($account, $brand, $user, $attributes, $profile, $contentAsset, $campaign, $language): SocialPost {
            $post = SocialPost::query()->create([
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'content_asset_id' => $contentAsset?->id,
                'campaign_id' => $campaign?->id,
                'social_profile_id' => $profile->id,
                'provider' => $profile->provider,
                'status' => $attributes['status'] ?? 'draft',
                'post_text' => $attributes['post_text'],
                'media' => $attributes['media'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'language' => $language,
                'locale' => $attributes['locale'] ?? $this->languages->localeForLanguage($language),
                'market' => $attributes['market'] ?? $brand->market,
                'created_by' => $user->id,
            ]);

            $this->activity->log(
                event: 'social_post.prepared',
                description: "Social post for {$profile->display_name} was prepared.",
                account: $account,
                brand: $brand,
                user: $user,
                subject: $post,
                properties: [
                    'social_profile_id' => $profile->id,
                    'provider' => $profile->provider,
                    'language' => $language,
                ],
            );

            $event = $this->events->recordForSubject('SocialPostCreated', $post, $user, [
                'social_profile_id' => $profile->id,
                'social_post_id' => $post->id,
                'content_asset_id' => $post->content_asset_id,
                'campaign_id' => $post->campaign_id,
                'provider' => $profile->provider,
                'language' => $language,
            ]);
            $this->events->process($event);

            $this->events->recordForSubject('SocialPostPrepared', $post, $user, [
                'social_profile_id' => $profile->id,
                'social_post_id' => $post->id,
                'content_asset_id' => $post->content_asset_id,
                'campaign_id' => $post->campaign_id,
                'provider' => $profile->provider,
                'language' => $language,
            ]);

            return $post;
        });
    }

    public function approve(SocialPost $post, User $user): SocialPost
    {
        $this->assertCanPrepare($user, $post->socialProfile, $post->account, $post->brand);

        $post->forceFill([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();

        $this->events->recordForSubject('SocialPostApproved', $post->refresh(), $user, [
            'social_profile_id' => $post->social_profile_id,
            'provider' => $post->provider,
        ]);

        return $post->refresh();
    }

    public function schedule(SocialPost $post, User $user, mixed $scheduledAt): SocialPost
    {
        if (! $this->profiles->canSchedule($user, $post->socialProfile, $post->account, $post->brand)) {
            throw new InvalidArgumentException('User cannot schedule with this social profile.');
        }

        $post->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ])->save();

        $event = $this->events->recordForSubject('SocialPostScheduled', $post->refresh(), $user, [
            'social_profile_id' => $post->social_profile_id,
            'social_post_id' => $post->id,
            'content_asset_id' => $post->content_asset_id,
            'campaign_id' => $post->campaign_id,
            'provider' => $post->provider,
            'scheduled_at' => $post->scheduled_at?->toDateTimeString(),
        ]);
        $this->events->process($event);

        $this->calendar->syncSocialPost($post->refresh());

        return $post->refresh();
    }

    public function queue(SocialPost $post, User $user): SocialPost
    {
        if (! $this->profiles->canPublish($user, $post->socialProfile, $post->account, $post->brand)) {
            throw new InvalidArgumentException('User cannot publish with this social profile.');
        }

        $this->assertProviderTokenHealthy($post);

        $this->languages->validateForBrand($post->language, $post->brand);

        $this->credits->consume(
            $post->account,
            $user,
            'social_publish',
            'Social post publishing requested.',
            $post,
            [
                'social_post_id' => $post->id,
                'social_profile_id' => $post->social_profile_id,
                'provider' => $post->provider,
            ],
        );

        $post->forceFill([
            'status' => 'queued',
            'error_message' => null,
        ])->save();

        PublishSocialPostJob::dispatch($post->id);

        return $post->refresh();
    }

    public function process(SocialPost $post): SocialPost
    {
        if (! in_array($post->status, ['queued', 'publishing'], true)) {
            return $post;
        }

        if ($post->provider === 'linkedin') {
            return $this->processLinkedIn($post);
        }

        $post->forceFill(['status' => 'publishing'])->save();

        $externalId = "fake-{$post->provider}-{$post->uuid}";
        $externalUrl = "https://social.example/{$post->provider}/{$post->uuid}";

        $post->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'external_id' => $externalId,
            'external_url' => $externalUrl,
            'error_message' => null,
        ])->save();

        $this->activity->log(
            event: 'social_post.published',
            description: "Social post was published through fake {$post->provider}.",
            account: $post->account,
            brand: $post->brand,
            user: $post->creator,
            subject: $post,
            properties: [
                'fake_provider' => true,
                'external_id' => $externalId,
                'external_url' => $externalUrl,
            ],
        );

        $event = $this->events->recordForSubject('SocialPostPublished', $post->refresh(), $post->creator, [
            'social_post_id' => $post->id,
            'social_profile_id' => $post->social_profile_id,
            'content_asset_id' => $post->content_asset_id,
            'campaign_id' => $post->campaign_id,
            'provider' => $post->provider,
            'external_id' => $externalId,
            'external_url' => $externalUrl,
            'fake_provider' => true,
        ], $post->published_at);
        $this->events->process($event);

        return $post->refresh();
    }

    private function processLinkedIn(SocialPost $post): SocialPost
    {
        try {
            $post->forceFill(['status' => 'publishing'])->save();

            $published = $this->linkedInPublisher->publish($post->refresh());

            $post->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'external_id' => $published['external_id'],
                'external_url' => $published['external_url'],
                'error_message' => null,
            ])->save();

            $this->activity->log(
                event: 'social_post.published',
                description: 'Social post was published through LinkedIn.',
                account: $post->account,
                brand: $post->brand,
                user: $post->creator,
                subject: $post,
                properties: [
                    'provider' => 'linkedin',
                    'external_id' => $published['external_id'],
                    'external_url' => $published['external_url'],
                    'linkedin_payload' => $published['payload'],
                ],
            );

            $event = $this->events->recordForSubject('SocialPostPublished', $post->refresh(), $post->creator, [
                'social_post_id' => $post->id,
                'social_profile_id' => $post->social_profile_id,
                'content_asset_id' => $post->content_asset_id,
                'campaign_id' => $post->campaign_id,
                'provider' => $post->provider,
                'external_id' => $published['external_id'],
                'external_url' => $published['external_url'],
                'fake_provider' => false,
            ], $post->published_at);
            $this->events->process($event);

            return $post->refresh();
        } catch (\Throwable $exception) {
            return $this->fail($post->refresh(), $exception->getMessage());
        }
    }

    public function fail(SocialPost $post, string $message): SocialPost
    {
        $post->forceFill([
            'status' => 'failed',
            'error_message' => $message,
        ])->save();

        $event = $this->events->recordForSubject('SocialPostFailed', $post->refresh(), $post->creator, [
            'social_post_id' => $post->id,
            'social_profile_id' => $post->social_profile_id,
            'content_asset_id' => $post->content_asset_id,
            'campaign_id' => $post->campaign_id,
            'provider' => $post->provider,
            'error_message' => $message,
            'fake_provider' => $post->provider !== 'linkedin',
        ]);
        $this->events->process($event);

        return $post->refresh();
    }

    public function markOverdue(SocialPost $post): SocialPost
    {
        if ($post->scheduled_at === null || $post->published_at !== null || $post->scheduled_at->isFuture()) {
            return $post;
        }

        $event = $this->events->recordForSubject('SocialPostOverdue', $post, $post->creator, [
            'social_post_id' => $post->id,
            'social_profile_id' => $post->social_profile_id,
            'content_asset_id' => $post->content_asset_id,
            'campaign_id' => $post->campaign_id,
            'provider' => $post->provider,
            'scheduled_at' => $post->scheduled_at?->toDateTimeString(),
        ]);
        $this->events->process($event);

        return $post->refresh();
    }

    public function flagContentAssetWithoutSocialDistribution(ContentAsset $asset, ?User $actor = null): bool
    {
        $hasDistribution = SocialPost::query()
            ->where('account_id', $asset->account_id)
            ->where('brand_id', $asset->brand_id)
            ->where('content_asset_id', $asset->id)
            ->exists();

        if ($hasDistribution) {
            return false;
        }

        $event = $this->events->recordForSubject('ContentAssetMissingSocialDistribution', $asset, $actor, [
            'content_asset_id' => $asset->id,
            'title' => $asset->title,
            'language' => $asset->language,
        ]);
        $this->events->process($event);

        return true;
    }

    public function flagCampaignWithoutScheduledSocialPosts(Campaign $campaign, ?User $actor = null): bool
    {
        if (! $campaign->contentAssets()->exists()) {
            return false;
        }

        $hasScheduledSocialPosts = SocialPost::query()
            ->where('account_id', $campaign->account_id)
            ->where('brand_id', $campaign->brand_id)
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('scheduled_at')
            ->whereIn('status', ['scheduled', 'queued', 'publishing', 'published'])
            ->exists();

        if ($hasScheduledSocialPosts) {
            return false;
        }

        $event = $this->events->recordForSubject('CampaignMissingScheduledSocialPosts', $campaign, $actor, [
            'campaign_id' => $campaign->id,
            'content_asset_count' => $campaign->contentAssets()->count(),
            'campaign_status' => $campaign->status,
        ]);
        $this->events->process($event);

        return true;
    }

    /**
     * @param  array{brand_id?: int|null, provider?: string|null, status?: string|null, language?: string|null}  $filters
     * @return LengthAwarePaginator<int, SocialPost>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return SocialPost::query()
            ->where('account_id', $account->id)
            ->when($brand !== null && empty($filters['brand_id']), fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->when($filters['brand_id'] ?? null, fn (Builder $query, int|string $brandId) => $query->where('brand_id', (int) $brandId))
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->with(['brand', 'contentAsset', 'campaign', 'socialProfile', 'creator'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    private function assertCanPrepare(User $user, SocialProfile $profile, Account $account, Brand $brand): void
    {
        if (! $this->profiles->canPrepare($user, $profile, $account, $brand)) {
            throw new InvalidArgumentException('User cannot prepare with this social profile.');
        }
    }

    private function assertProviderTokenHealthy(SocialPost $post): void
    {
        $profile = $post->socialProfile()->with('integrationConnection.integration')->first();

        if (! $profile || $profile->provider !== 'linkedin') {
            return;
        }

        $connection = $profile->integrationConnection;

        if (! $connection) {
            throw new InvalidArgumentException('LinkedIn profile is missing its integration connection.');
        }

        $connection = $this->linkedInTokens->refreshIfPossible($connection);

        if ($connection->status !== 'active' || $profile->fresh()?->status !== 'connected') {
            throw new InvalidArgumentException('Reconnect LinkedIn profile before publishing.');
        }

        if ($profile->type === 'person' && ! in_array('w_member_social', $connection->scopes ?? [], true)) {
            throw new InvalidArgumentException('LinkedIn profile is missing the w_member_social scope.');
        }

        if (in_array($profile->type, ['organization', 'page'], true) && ! in_array('w_organization_social', $connection->scopes ?? [], true)) {
            throw new InvalidArgumentException('LinkedIn organization publishing requires approved w_organization_social scope and page publishing role.');
        }
    }

    private function assertTenant(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Social post brand must belong to the account.');
        }
    }

    private function contentAsset(?int $id, Account $account, Brand $brand): ?ContentAsset
    {
        if ($id === null) {
            return null;
        }

        $asset = ContentAsset::query()->findOrFail($id);

        if ($asset->account_id !== $account->id || $asset->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Content asset must belong to the same account and brand as the social post.');
        }

        return $asset;
    }

    private function campaign(?int $id, Account $account, Brand $brand): ?Campaign
    {
        if ($id === null) {
            return null;
        }

        $campaign = Campaign::query()->findOrFail($id);

        if ($campaign->account_id !== $account->id || $campaign->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Campaign must belong to the same account and brand as the social post.');
        }

        return $campaign;
    }
}
