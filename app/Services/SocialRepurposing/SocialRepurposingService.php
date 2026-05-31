<?php

namespace App\Services\SocialRepurposing;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ContentLanguageService;
use App\Services\CreditService;
use App\Services\DomainEventService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialPublishing\SocialPublishingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SocialRepurposingService
{
    public function __construct(
        private readonly SocialProfileService $profiles,
        private readonly SocialPublishingService $publishing,
        private readonly ContentLanguageService $languages,
        private readonly CreditService $credits,
        private readonly DomainEventService $events,
    ) {}

    public function generateFromContentAsset(
        Account $account,
        Brand $brand,
        User $user,
        ContentAsset $asset,
        SocialProfile $profile,
        string $language,
    ): SocialPost {
        $this->assertTenant($account, $brand, $asset);

        if (! $this->profiles->canPrepare($user, $profile, $account, $brand)) {
            throw new InvalidArgumentException('User cannot prepare with this social profile.');
        }

        $language = $this->languages->validateForBrand($language, $brand);

        $this->credits->consume(
            $account,
            $user,
            'social_repurpose',
            'Social content repurposing requested.',
            $asset,
            [
                'content_asset_id' => $asset->id,
                'social_profile_id' => $profile->id,
                'language' => $language,
            ],
        );

        return DB::transaction(function () use ($account, $brand, $user, $asset, $profile, $language): SocialPost {
            $post = $this->publishing->prepare($account, $brand, $user, [
                'content_asset_id' => $asset->id,
                'social_profile_id' => $profile->id,
                'post_text' => $this->seedPostText($asset, $language),
                'language' => $language,
                'status' => 'draft',
            ]);

            foreach ($this->variantPayloads($asset, $language, $profile) as $payload) {
                SocialPostVariant::query()->create([
                    'account_id' => $account->id,
                    'brand_id' => $brand->id,
                    'social_post_id' => $post->id,
                    'content_asset_id' => $asset->id,
                    'variant_type' => $payload['variant_type'],
                    'status' => 'draft',
                    'post_text' => $payload['post_text'],
                    'language' => $language,
                    'metadata' => $payload['metadata'],
                    'created_by' => $user->id,
                ]);
            }

            $post->load('variants');

            $this->events->recordForSubject('SocialPostVariantsGenerated', $post, $user, [
                'content_asset_id' => $asset->id,
                'social_profile_id' => $profile->id,
                'language' => $language,
                'variant_count' => $post->variants->count(),
            ]);

            return $post->refresh();
        });
    }

    public function selectVariant(SocialPost $post, SocialPostVariant $variant, User $user): SocialPost
    {
        if ($variant->social_post_id !== $post->id) {
            throw new InvalidArgumentException('Variant must belong to the selected social post.');
        }

        if (! $this->profiles->canPrepare($user, $post->socialProfile, $post->account, $post->brand)) {
            throw new InvalidArgumentException('User cannot select variants for this social profile.');
        }

        return DB::transaction(function () use ($post, $variant, $user): SocialPost {
            $post->variants()
                ->whereKeyNot($variant->id)
                ->where('status', 'selected')
                ->update(['status' => 'draft']);

            $variant->forceFill(['status' => 'selected'])->save();
            $post->forceFill([
                'post_text' => $variant->post_text,
                'language' => $variant->language ?? $post->language,
                'locale' => $variant->language ? $this->languages->localeForLanguage($variant->language) : $post->locale,
                'status' => 'draft',
            ])->save();

            $this->events->recordForSubject('SocialPostVariantSelected', $post->refresh(), $user, [
                'social_post_variant_id' => $variant->id,
                'variant_type' => $variant->variant_type,
                'content_asset_id' => $variant->content_asset_id,
                'language' => $variant->language,
            ]);

            return $post->refresh();
        });
    }

    /**
     * @return Collection<int, SocialPostVariant>
     */
    public function variantsForPost(SocialPost $post): Collection
    {
        return $post->variants()
            ->orderByRaw("case variant_type when 'short' then 1 when 'linkedin_personal' then 2 when 'thread' then 3 else 4 end")
            ->orderBy('id')
            ->get();
    }

    private function assertTenant(Account $account, Brand $brand, ContentAsset $asset): void
    {
        if ($brand->account_id !== $account->id || $asset->account_id !== $account->id || $asset->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Content asset must belong to the current account and brand.');
        }
    }

    private function seedPostText(ContentAsset $asset, string $language): string
    {
        return "[{$language}] ".$asset->title;
    }

    /**
     * @return array<int, array{variant_type: string, post_text: string, metadata: array<string, mixed>}>
     */
    private function variantPayloads(ContentAsset $asset, string $language, SocialProfile $profile): array
    {
        $title = trim($asset->title);
        $excerpt = trim((string) ($asset->excerpt ?: str($asset->body)->limit(180)));
        $body = trim((string) str($asset->body ?: $excerpt)->limit(360));
        $prefix = strtoupper($language);

        return [
            [
                'variant_type' => 'short',
                'post_text' => "{$title}\n\n{$excerpt}",
                'metadata' => [
                    'fake' => true,
                    'language' => $language,
                    'source' => 'title_excerpt',
                ],
            ],
            [
                'variant_type' => $profile->provider === 'linkedin' ? 'linkedin_personal' : 'long',
                'post_text' => "{$title}\n\n{$body}\n\nWhat stands out most to you?",
                'metadata' => [
                    'fake' => true,
                    'language' => $language,
                    'source' => 'body_summary',
                    'provider' => $profile->provider,
                ],
            ],
            [
                'variant_type' => 'thread',
                'post_text' => "{$prefix} thread idea:\n1. {$title}\n2. {$excerpt}\n3. Read the full piece and turn it into action.",
                'metadata' => [
                    'fake' => true,
                    'language' => $language,
                    'source' => 'thread_outline',
                ],
            ],
        ];
    }
}
