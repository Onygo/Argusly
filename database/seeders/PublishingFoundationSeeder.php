<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Property;
use App\Models\PublishingChannel;
use Illuminate\Database\Seeder;

class PublishingFoundationSeeder extends Seeder
{
    /**
     * Seed demo properties and publishing channels for existing brands.
     */
    public function run(): void
    {
        Account::query()
            ->with('brands')
            ->get()
            ->each(function (Account $account): void {
                foreach ($account->brands as $brand) {
                    $website = Property::query()->updateOrCreate(
                        [
                            'account_id' => $account->id,
                            'brand_id' => $brand->id,
                            'url' => $brand->website_url ?? ($brand->domain ? "https://{$brand->domain}" : "https://{$brand->slug}.example"),
                        ],
                        [
                            'name' => "{$brand->name} Website",
                            'type' => 'website',
                            'primary_language' => $brand->language ?? 'en',
                            'settings' => ['demo' => true, 'connector_ready' => ['wordpress', 'laravel']],
                            'status' => 'active',
                        ],
                    );

                    PublishingChannel::query()->updateOrCreate(
                        [
                            'account_id' => $account->id,
                            'brand_id' => $brand->id,
                            'property_id' => $website->id,
                            'provider' => 'wordpress',
                        ],
                        [
                            'name' => "{$brand->name} WordPress",
                            'status' => 'draft',
                            'credentials' => null,
                            'settings' => ['demo' => true, 'publishing_mode' => 'manual'],
                            'last_connected_at' => null,
                        ],
                    );

                    PublishingChannel::query()->updateOrCreate(
                        [
                            'account_id' => $account->id,
                            'brand_id' => $brand->id,
                            'property_id' => null,
                            'provider' => 'linkedin',
                        ],
                        [
                            'name' => "{$brand->name} LinkedIn",
                            'status' => 'draft',
                            'credentials' => null,
                            'settings' => ['demo' => true, 'publishing_mode' => 'manual'],
                            'last_connected_at' => null,
                        ],
                    );
                }
            });
    }
}
