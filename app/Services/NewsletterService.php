<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Newsletter;
use App\Models\NewsletterSection;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NewsletterService
{
    /**
     * @return LengthAwarePaginator<int, Newsletter>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, int $perPage = 15): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Newsletter::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'campaign', 'creator'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Newsletter
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Newsletter::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'campaign', 'creator', 'approver', 'sections.contentAsset'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, Brand $brand, User $creator, array $attributes): Newsletter
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $campaign = $this->campaign($account, $brand, $attributes['campaign_id'] ?? null);

        return Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign?->id,
            'title' => $attributes['title'],
            'subject' => $attributes['subject'] ?? null,
            'preheader' => $attributes['preheader'] ?? null,
            'language' => $attributes['language'],
            'status' => $attributes['status'] ?? 'draft',
            'scheduled_at' => $attributes['scheduled_at'] ?? null,
            'created_by' => $creator->id,
            'metadata' => [
                'source' => 'manual',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Newsletter $newsletter, array $attributes): Newsletter
    {
        $newsletter->fill([
            'title' => $attributes['title'] ?? $newsletter->title,
            'subject' => $attributes['subject'] ?? null,
            'preheader' => $attributes['preheader'] ?? null,
            'language' => $attributes['language'] ?? $newsletter->language,
            'status' => $attributes['status'] ?? $newsletter->status,
        ])->save();

        return $newsletter->refresh();
    }

    public function saveDraft(Newsletter $newsletter): Newsletter
    {
        $newsletter->forceFill([
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        return $newsletter->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addSection(Newsletter $newsletter, array $attributes): NewsletterSection
    {
        $asset = isset($attributes['content_asset_id']) && $attributes['content_asset_id'] !== null
            ? ContentAsset::query()->find((int) $attributes['content_asset_id'])
            : null;

        return $newsletter->sections()->create([
            'type' => $attributes['type'],
            'title' => $attributes['title'] ?? $asset?->title,
            'body' => $attributes['body'] ?? $asset?->excerpt,
            'content_asset_id' => $asset?->id,
            'position' => $attributes['position'] ?? $newsletter->sections()->max('position') + 1,
            'metadata' => [
                'source' => 'manual',
            ],
        ]);
    }

    /**
     * @param  array<int|string, int|string>  $positions
     */
    public function reorderSections(Newsletter $newsletter, array $positions): void
    {
        $sections = $newsletter->sections()->get()->keyBy('id');

        foreach ($positions as $sectionId => $position) {
            $section = $sections->get((int) $sectionId);

            if (! $section) {
                continue;
            }

            $section->forceFill(['position' => max(0, (int) $position)])->save();
        }
    }

    /**
     * @return Collection<int, Campaign>
     */
    public function campaigns(Account $account, ?Brand $brand = null): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, ContentAsset>
     */
    public function contentAssets(Account $account, ?Brand $brand = null, ?string $language = null): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return ContentAsset::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->when($language !== null, fn (Builder $query) => $query->where('language', $language))
            ->latest()
            ->limit(100)
            ->get();
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Newsletter brand must belong to the account.');
        }
    }

    private function campaign(Account $account, Brand $brand, mixed $campaignId): ?Campaign
    {
        if ($campaignId === null || $campaignId === '') {
            return null;
        }

        return Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->findOrFail((int) $campaignId);
    }
}
