<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\BrandNarrative;
use App\Models\BrandProduct;
use App\Models\BrandProfile;
use App\Models\BrandService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BrandKnowledgeCenterService
{
    /**
     * @var array<string, string>
     */
    private const PROFILE_FIELDS = [
        'official_name' => 'Official name',
        'tagline' => 'Tagline',
        'short_description' => 'Short description',
        'long_description' => 'Long description',
        'mission' => 'Mission',
        'vision' => 'Vision',
        'positioning' => 'Positioning',
        'value_proposition' => 'Value proposition',
        'tone_of_voice' => 'Tone of voice',
        'primary_audience' => 'Primary audience',
        'secondary_audience' => 'Secondary audience',
        'website' => 'Website',
    ];

    public function profileForBrand(Account $account, Brand $brand): BrandProfile
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return BrandProfile::query()->firstOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
            ],
            [
                'official_name' => $brand->name,
                'short_description' => $brand->description,
                'website' => $brand->website_url ?: $brand->domain,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(Account $account, Brand $brand, array $attributes): BrandProfile
    {
        $profile = $this->profileForBrand($account, $brand);
        $profile->update($attributes);

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProduct(Account $account, Brand $brand, array $attributes): BrandProduct
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return BrandProduct::query()->create($attributes + [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createService(Account $account, Brand $brand, array $attributes): BrandService
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return BrandService::query()->create($attributes + [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createNarrative(Account $account, Brand $brand, array $attributes): BrandNarrative
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return BrandNarrative::query()->create($attributes + [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    /**
     * @return array{profile: BrandProfile, products: Collection<int, BrandProduct>, services: Collection<int, BrandService>, narratives: Collection<int, BrandNarrative>, completeness: array{completed: int, total: int, percentage: int, missing: array<int, string>, recommendations: array<int, string>}, futureUseCases: array<int, array{label: string, status: string}>}
     */
    public function centerForBrand(Account $account, Brand $brand): array
    {
        $profile = $this->profileForBrand($account, $brand);

        return [
            'profile' => $profile,
            'products' => BrandProduct::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->latest()
                ->get(),
            'services' => BrandService::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->latest()
                ->get(),
            'narratives' => BrandNarrative::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->latest()
                ->get(),
            'completeness' => $this->profileCompleteness($profile),
            'futureUseCases' => [
                ['label' => 'AI visibility', 'status' => 'prepared'],
                ['label' => 'Narrative intelligence', 'status' => 'prepared'],
                ['label' => 'Content generation', 'status' => 'prepared'],
                ['label' => 'Creator matching', 'status' => 'prepared'],
                ['label' => 'Relationship intelligence', 'status' => 'prepared'],
            ],
        ];
    }

    /**
     * @return array{completed: int, total: int, percentage: int, missing: array<int, string>, recommendations: array<int, string>}
     */
    public function profileCompleteness(BrandProfile $profile): array
    {
        $completed = collect(array_keys(self::PROFILE_FIELDS))
            ->filter(fn (string $field): bool => filled($profile->{$field}))
            ->count();
        $total = count(self::PROFILE_FIELDS);
        $missing = collect(self::PROFILE_FIELDS)
            ->filter(fn (string $label, string $field): bool => blank($profile->{$field}))
            ->values()
            ->all();

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => (int) round(($completed / $total) * 100),
            'missing' => $missing,
            'recommendations' => $this->recommendationsForMissingFields($missing),
        ];
    }

    /**
     * @param  array<int, string>  $missing
     * @return array<int, string>
     */
    private function recommendationsForMissingFields(array $missing): array
    {
        return collect($missing)
            ->take(5)
            ->map(fn (string $field): string => "Complete {$field} so AI systems and publishing workflows can represent the brand consistently.")
            ->values()
            ->all();
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Brand knowledge center brand must belong to the account.');
        }
    }
}
