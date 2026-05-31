<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\User;
use App\Services\Signals\SignalManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ContentAssetService
{
    /**
     * @param  array{status?: string|null, type?: string|null, language?: string|null}  $filters
     * @return LengthAwarePaginator<ContentAsset>
     */
    public function paginatedForTenant(Account $account, Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, Brand $brand, array $attributes, User $user): ContentAsset
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $this->validateTypeAndStatus($attributes['type'] ?? null, $attributes['status'] ?? 'draft');

        $asset = ContentAsset::query()->create([
            ...$attributes,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'slug' => $this->uniqueSlug($account, $brand, $attributes['slug'] ?? null, $attributes['title'], $attributes['locale'] ?? config('app.faker_locale', 'en_US')),
            'status' => $attributes['status'] ?? 'draft',
            'source' => $attributes['source'] ?? 'manual',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        app(DomainEventService::class)->recordForSubject('ContentAssetCreated', $asset, $user, [
            'title' => $asset->title,
            'type' => $asset->type,
            'status' => $asset->status,
            'source' => $asset->source,
        ], $asset->created_at);

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ContentAsset $asset, array $attributes, User $user): ContentAsset
    {
        $this->validateTypeAndStatus($attributes['type'] ?? $asset->type, $attributes['status'] ?? $asset->status);

        if (array_key_exists('title', $attributes)) {
            $attributes['slug'] = $this->uniqueSlug($asset->account, $asset->brand, $attributes['slug'] ?? $asset->slug, $attributes['title'], $attributes['locale'] ?? $asset->locale, $asset);
        }

        $asset->fill([
            ...$attributes,
            'updated_by' => $user->id,
        ])->save();

        return $asset->refresh();
    }

    public function approve(ContentAsset $asset, User $user): ContentAsset
    {
        $asset->forceFill([
            'status' => 'approved',
            'updated_by' => $user->id,
        ])->save();

        return $asset->refresh();
    }

    public function publish(ContentAsset $asset, User $user): ContentAsset
    {
        $publishedAt = $asset->published_at ?? now();

        $asset->forceFill([
            'status' => 'published',
            'published_at' => $publishedAt,
            'first_published_at' => $asset->first_published_at ?? $publishedAt,
            'updated_by' => $user->id,
        ])->save();

        app(SignalManager::class)->produce($asset);
        app(DomainEventService::class)->recordForSubject('ContentAssetPublished', $asset, $user, [
            'title' => $asset->title,
            'canonical_url' => $asset->canonical_url,
            'published_at' => $asset->published_at?->toDateTimeString(),
            'first_published_at' => $asset->first_published_at?->toDateTimeString(),
        ], $publishedAt);

        return $asset->refresh();
    }

    /**
     * @return Builder<ContentAsset>
     */
    private function tenantQuery(Account $account, Brand $brand): Builder
    {
        return ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with([
                'brand',
                'creator',
                'latestLifecycleScore' => fn ($query) => $query->limit(1),
            ]);
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Content asset brand must belong to the content asset account.');
        }
    }

    private function validateTypeAndStatus(?string $type, ?string $status): void
    {
        if (! in_array($type, ContentAsset::TYPES, true)) {
            throw new InvalidArgumentException("Invalid content asset type [{$type}].");
        }

        if (! in_array($status, ContentAsset::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid content asset status [{$status}].");
        }
    }

    private function slug(?string $slug, string $title): string
    {
        return Str::slug($slug ?: $title);
    }

    private function uniqueSlug(Account $account, Brand $brand, ?string $slug, string $title, string $locale, ?ContentAsset $ignore = null): string
    {
        $base = $this->slug($slug, $title);
        $candidate = $base;
        $suffix = 2;

        while (ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('locale', $locale)
            ->where('slug', $candidate)
            ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->id))
            ->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array{status?: string|null, type?: string|null, language?: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;
        $type = $filters['type'] ?? null;
        $language = $filters['language'] ?? null;

        if ($status !== null && $status !== '') {
            abort_unless(in_array($status, ContentAsset::STATUSES, true), 404);
            $query->where('status', $status);
        }

        if ($type !== null && $type !== '') {
            abort_unless(in_array($type, ContentAsset::TYPES, true), 404);
            $query->where('type', $type);
        }

        if ($language !== null && $language !== '') {
            abort_unless(app(ContentLanguageService::class)->isEnabledForBrand($language, $query->getModel()->brand ?? null) || app(LanguageService::class)->isContentLanguage($language), 404);
            $query->where('language', $language);
        }
    }
}
