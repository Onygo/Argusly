<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\ContentAuditService;
use App\Services\ContentLanguageService;
use App\Services\ContentTranslationService;
use App\Services\PublishingService;
use App\Services\RecommendationEngineService;
use App\Services\SocialPublishing\SocialPublishingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class DistributionController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly ContentLanguageService $languages,
    ) {}

    public function index(Request $request): View
    {
        [$user, $account, $brand] = $this->context($request);
        Gate::forUser($user)->authorize('view_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);
        app(RecommendationEngineService::class)->generateDistributionRecommendations($account, $brand);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(ContentAsset::STATUSES)],
            'language' => ['nullable', 'string', Rule::in($this->languages->enabledCodesForBrand($brand))],
            'distribution' => ['nullable', 'string', Rule::in(['needs_website', 'needs_social', 'needs_review'])],
        ]);

        $assets = ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->with([
                'publishingChannel.connectorInstallation.version',
                'publishingChannel.connectorInstallation.manifest',
                'publishingActions' => fn ($query) => $query->with('publishingChannel')->latest()->limit(5),
                'socialPosts' => fn ($query) => $query->with(['campaign', 'socialProfile'])->latest()->limit(5),
                'campaigns' => fn ($query) => $query->latest('campaign_assets.created_at'),
                'latestAudit',
                'sourceTranslations',
                'translatedFrom',
            ])
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        $assetIds = $assets->getCollection()->pluck('id')->all();
        $recommendations = $this->recommendationsForAssets($account, $brand, $assetIds);
        $channels = $this->publishingChannels($account, $brand);

        if (($filters['distribution'] ?? null) !== null) {
            $assets->setCollection($assets->getCollection()
                ->filter(fn (ContentAsset $asset) => $this->matchesDistributionFilter($asset, $filters['distribution']))
                ->values());
        }

        return view('app.distribution.index', [
            'account' => $account,
            'brand' => $brand,
            'assets' => $assets,
            'filters' => $filters,
            'statuses' => ContentAsset::STATUSES,
            'contentLanguages' => $this->languages->enabledForBrand($brand),
            'publishingChannels' => $channels,
            'recommendationsByAsset' => $recommendations,
            'defaultTranslationTarget' => $this->defaultTranslationTarget($brand),
        ]);
    }

    public function publishWebsite(Request $request, ContentAsset $contentAsset, PublishingService $publishing): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $this->assertAssetContext($contentAsset, $account, $brand);
        Gate::forUser($user)->authorize('publish', $contentAsset);

        $channelIds = $this->publishingChannels($account, $brand)->pluck('id')->all();
        $attributes = $request->validate([
            'publishing_channel_id' => ['nullable', 'integer', Rule::in($channelIds)],
        ]);

        try {
            $publishing->request($contentAsset, $user, [
                'action' => 'publish',
                'publishing_channel_id' => $attributes['publishing_channel_id'] ?? $contentAsset->channel_id,
            ]);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['publishing_channel_id' => $exception->getMessage()]);
        }

        return redirect()->route('app.distribution')->with('status', 'Website publishing action queued.');
    }

    public function scheduleSocial(Request $request, SocialPost $socialPost, SocialPublishingService $publishing): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);

        if ($socialPost->account_id !== $account->id || $socialPost->brand_id !== $brand->id) {
            abort(404);
        }

        Gate::forUser($user)->authorize('schedule', $socialPost);

        $attributes = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        try {
            $publishing->schedule($socialPost, $user, Carbon::parse($attributes['scheduled_at']));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['scheduled_at' => $exception->getMessage()]);
        }

        return redirect()->route('app.distribution')->with('status', 'Social post scheduled.');
    }

    public function runAudit(Request $request, ContentAsset $contentAsset, ContentAuditService $audits): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $this->assertAssetContext($contentAsset, $account, $brand);
        Gate::forUser($user)->authorize('update', $contentAsset);

        try {
            $audits->requestForContentAsset($contentAsset, $user);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        }

        return redirect()->route('app.distribution')->with('status', 'Content audit queued.');
    }

    public function translate(Request $request, ContentAsset $contentAsset, ContentTranslationService $translations): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $this->assertAssetContext($contentAsset, $account, $brand);
        Gate::forUser($user)->authorize('update', $contentAsset);

        $targets = array_values(array_diff($this->languages->enabledCodesForBrand($brand), [$contentAsset->language]));
        $attributes = $request->validate([
            'target_language' => ['required', 'string', Rule::in($targets)],
        ]);

        try {
            $translations->createTranslations($contentAsset, $user, [$attributes['target_language']]);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['target_language' => $exception->getMessage()]);
        }

        return redirect()->route('app.distribution')->with('status', 'Translation draft created.');
    }

    public function markReviewed(Request $request, ContentAsset $contentAsset): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $this->assertAssetContext($contentAsset, $account, $brand);
        Gate::forUser($user)->authorize('update', $contentAsset);

        $metadata = $contentAsset->metadata ?? [];
        $metadata['distribution_reviewed_at'] = now()->toDateTimeString();
        $metadata['distribution_reviewed_by'] = $user->id;

        $contentAsset->forceFill(['metadata' => $metadata])->save();

        return redirect()->route('app.distribution')->with('status', 'Distribution row marked reviewed.');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function context(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $brand = $this->currentBrand->get($user) ?? abort(403);

        return [$user, $account, $brand];
    }

    private function assertAssetContext(ContentAsset $asset, Account $account, Brand $brand): void
    {
        if ($asset->account_id !== $account->id || $asset->brand_id !== $brand->id) {
            abort(404);
        }
    }

    /**
     * @return Collection<int, PublishingChannel>
     */
    private function publishingChannels(Account $account, Brand $brand): Collection
    {
        return PublishingChannel::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with(['connectorInstallation.manifest', 'connectorInstallation.version'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, int>  $assetIds
     * @return Collection<int, Collection<int, Recommendation>>
     */
    private function recommendationsForAssets(Account $account, Brand $brand, array $assetIds): Collection
    {
        return Recommendation::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->open()
            ->with('signal')
            ->latest('created_at')
            ->get()
            ->flatMap(function (Recommendation $recommendation) use ($assetIds): array {
                $payload = $recommendation->signal?->payload ?? [];
                $recommendationAssetIds = collect([$payload['content_asset_id'] ?? null])
                    ->merge($payload['content_asset_ids'] ?? [])
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->intersect($assetIds)
                    ->values();

                return $recommendationAssetIds
                    ->map(fn (int $assetId) => ['asset_id' => $assetId, 'recommendation' => $recommendation])
                    ->all();
            })
            ->groupBy('asset_id')
            ->map(fn (Collection $items) => $items->pluck('recommendation')->values());
    }

    private function matchesDistributionFilter(ContentAsset $asset, string $filter): bool
    {
        return match ($filter) {
            'needs_website' => ! in_array($asset->publishingActions->first()?->status, ['queued', 'processing', 'completed'], true),
            'needs_social' => ! in_array($asset->socialPosts->first()?->status, ['scheduled', 'queued', 'publishing', 'published'], true),
            'needs_review' => empty(($asset->metadata ?? [])['distribution_reviewed_at']),
            default => true,
        };
    }

    private function defaultTranslationTarget(Brand $brand): ?string
    {
        return $this->languages->enabledForBrand($brand)
            ->first(fn ($language) => $language->code !== $this->languages->defaultFor($brand, $brand->account))
            ?->code;
    }

    private function insufficientCredits(InsufficientCreditsException $exception): RedirectResponse
    {
        return back()->withErrors([
            'credits' => "Not enough credits. {$exception->requiredCredits} required, {$exception->availableCredits} available.",
        ]);
    }
}
