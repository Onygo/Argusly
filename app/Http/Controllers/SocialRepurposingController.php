<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ContentLanguageService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialRepurposing\SocialRepurposingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

class SocialRepurposingController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly SocialProfileService $profiles,
        private readonly SocialRepurposingService $repurposing,
        private readonly ContentLanguageService $languages,
    ) {}

    public function create(Request $request, ContentAsset $contentAsset): View
    {
        Gate::authorize('view', $contentAsset);
        [$user, $account, $brand] = $this->context($request);
        $this->assertAssetContext($contentAsset, $account, $brand);

        $profiles = $this->profiles->profilesFor($user, $account, $brand)
            ->filter(fn (SocialProfile $profile) => $this->profiles->canPrepare($user, $profile, $account, $brand))
            ->values();

        return view('app.social-posts.repurpose', [
            'account' => $account,
            'brand' => $brand,
            'asset' => $contentAsset,
            'profiles' => $profiles,
            'contentLanguages' => $this->languages->enabledForBrand($brand),
        ]);
    }

    public function store(Request $request, ContentAsset $contentAsset): RedirectResponse
    {
        Gate::authorize('view', $contentAsset);
        [$user, $account, $brand] = $this->context($request);
        $this->assertAssetContext($contentAsset, $account, $brand);

        $data = $request->validate([
            'social_profile_id' => ['required', 'integer', 'exists:social_profiles,id'],
            'language' => $this->languages->validationRules($brand),
        ]);

        try {
            $post = $this->repurposing->generateFromContentAsset(
                account: $account,
                brand: $brand,
                user: $user,
                asset: $contentAsset,
                profile: SocialProfile::query()->findOrFail($data['social_profile_id']),
                language: $data['language'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['social_profile_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.social-posts.variants', $post)
            ->with('status', 'Social post variants generated.');
    }

    public function variants(Request $request, SocialPost $socialPost): View
    {
        Gate::authorize('view', $socialPost);
        [, $account, $brand] = $this->context($request);

        if ($socialPost->account_id !== $account->id || $socialPost->brand_id !== $brand->id) {
            abort(404);
        }

        return view('app.social-posts.variants.index', [
            'account' => $account,
            'brand' => $brand,
            'post' => $socialPost->load(['contentAsset', 'socialProfile']),
            'variants' => $this->repurposing->variantsForPost($socialPost),
        ]);
    }

    public function select(Request $request, SocialPost $socialPost, SocialPostVariant $variant): RedirectResponse
    {
        Gate::authorize('approve', $socialPost);
        [$user, $account, $brand] = $this->context($request);

        if ($socialPost->account_id !== $account->id || $socialPost->brand_id !== $brand->id) {
            abort(404);
        }

        try {
            $post = $this->repurposing->selectVariant($socialPost, $variant, $user);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['variant' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.social-posts.show', $post)
            ->with('status', 'Selected variant converted to final social post.');
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
}
