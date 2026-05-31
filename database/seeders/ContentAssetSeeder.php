<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Services\ContentAssetService;
use Illuminate\Database\Seeder;

class ContentAssetSeeder extends Seeder
{
    /**
     * Seed demo Argusly Content Engine assets.
     */
    public function run(): void
    {
        $service = app(ContentAssetService::class);

        Account::query()
            ->with(['brands', 'users'])
            ->get()
            ->each(function (Account $account) use ($service): void {
                $brand = $account->brands->first();
                $user = $account->users->first();

                if (! $brand || ! $user) {
                    return;
                }

                $property = Property::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->first();
                $channel = PublishingChannel::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('property_id', $property?->id)
                    ->first();

                $this->create($service, $account, $brand, $user, [
                    'property_id' => $property?->id,
                    'channel_id' => $channel?->id,
                    'type' => 'article',
                    'status' => 'published',
                    'title' => 'How AI answer visibility shapes category demand',
                    'language' => 'en',
                    'locale' => 'en_US',
                    'source' => 'demo.content_engine',
                    'canonical_url' => "https://{$brand->domain}/insights/ai-answer-visibility",
                    'excerpt' => 'A demo article for the Argusly Content Engine foundation.',
                    'body' => 'This placeholder article shows how reusable content assets can support blogs, pages, campaigns, newsletters and answer surfaces.',
                    'published_at' => now()->subDays(3),
                    'first_published_at' => now()->subDays(3),
                    'metadata' => ['demo' => true, 'surface' => 'blog'],
                    'seo_metadata' => ['title' => 'AI answer visibility and demand'],
                ]);

                $this->create($service, $account, $brand, $user, [
                    'property_id' => $property?->id,
                    'channel_id' => $channel?->id,
                    'type' => 'landing_page',
                    'status' => 'review',
                    'title' => 'AI visibility audit landing page',
                    'language' => 'en',
                    'locale' => 'en_US',
                    'source' => 'demo.content_engine',
                    'excerpt' => 'A review-ready landing page asset.',
                    'body' => 'Landing page placeholder content for future generation workflows.',
                    'metadata' => ['demo' => true, 'surface' => 'campaign'],
                    'seo_metadata' => ['title' => 'AI visibility audit'],
                ]);

                $this->create($service, $account, $brand, $user, [
                    'type' => 'social_post',
                    'status' => 'draft',
                    'title' => 'LinkedIn post: visibility benchmark insight',
                    'language' => 'en',
                    'locale' => 'en_US',
                    'source' => 'demo.content_engine',
                    'excerpt' => 'A draft social post linked to the content foundation.',
                    'body' => 'Benchmarking AI visibility is becoming as important as tracking search rankings.',
                    'metadata' => ['demo' => true, 'channel' => 'linkedin'],
                    'seo_metadata' => null,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(ContentAssetService $service, Account $account, Brand $brand, $user, array $attributes): void
    {
        $service->create($account, $brand, $attributes, $user);
    }
}
