<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ContentLanguageService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialPublishing\SocialPublishingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class SocialPostController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly SocialPublishingService $publishing,
        private readonly SocialProfileService $profiles,
        private readonly ContentLanguageService $languages,
    ) {}

    public function index(Request $request): View
    {
        [$user, $account, $brand] = $this->context($request);
        $filters = $request->only(['brand_id', 'provider', 'status', 'language']);

        return view('app.social-posts.index', [
            'account' => $account,
            'brand' => $brand,
            'posts' => $this->publishing->paginatedForTenant($account, $brand, $filters),
            'filters' => $filters,
            'brands' => $account->brands()->orderBy('name')->get(),
            'providers' => SocialProfile::query()
                ->whereHas('permissions', fn (Builder $query) => $query->where('account_id', $account->id))
                ->orWhere('owner_user_id', $user->id)
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider')
                ->filter()
                ->values(),
            'statuses' => SocialPost::STATUSES,
            'contentLanguages' => $this->languages->enabledForBrand($brand),
        ]);
    }

    public function create(Request $request): View
    {
        [$user, $account, $brand] = $this->context($request);
        $asset = $request->integer('content_asset_id')
            ? ContentAsset::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->findOrFail($request->integer('content_asset_id'))
            : null;

        $profiles = $this->profiles->profilesFor($user, $account, $brand)
            ->filter(fn (SocialProfile $profile) => $this->profiles->canPrepare($user, $profile, $account, $brand))
            ->values();

        return view('app.social-posts.create', [
            'account' => $account,
            'brand' => $brand,
            'asset' => $asset,
            'profiles' => $profiles,
            'contentLanguages' => $this->languages->enabledForBrand($brand),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);

        $data = $request->validate([
            'content_asset_id' => ['nullable', 'integer', 'exists:content_assets,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'social_profile_id' => ['required', 'integer', 'exists:social_profiles,id'],
            'post_text' => ['required', 'string', 'max:3000'],
            'language' => $this->languages->validationRules($brand),
            'locale' => ['nullable', 'string', 'max:16'],
            'market' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'review'])],
        ]);

        try {
            $post = $this->publishing->prepare($account, $brand, $user, $data);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['social_profile_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.social-posts.show', $post)
            ->with('status', 'Social post prepared.');
    }

    public function show(Request $request, SocialPost $socialPost): View
    {
        Gate::authorize('view', $socialPost);
        [, $account, $brand] = $this->context($request);

        if ($socialPost->account_id !== $account->id || $socialPost->brand_id !== $brand->id) {
            abort(404);
        }

        return view('app.social-posts.show', [
            'account' => $account,
            'brand' => $brand,
            'post' => $socialPost->load(['contentAsset', 'campaign', 'socialProfile.owner', 'creator', 'approver']),
        ]);
    }

    public function approve(Request $request, SocialPost $socialPost): RedirectResponse
    {
        Gate::authorize('approve', $socialPost);
        [$user] = $this->context($request);

        $this->publishing->approve($socialPost, $user);

        return back()->with('status', 'Approve placeholder completed.');
    }

    public function schedule(Request $request, SocialPost $socialPost): RedirectResponse
    {
        Gate::authorize('schedule', $socialPost);
        [$user] = $this->context($request);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        try {
            $this->publishing->schedule($socialPost, $user, $data['scheduled_at']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['scheduled_at' => $exception->getMessage()]);
        }

        return back()->with('status', 'Schedule placeholder saved.');
    }

    public function publish(Request $request, SocialPost $socialPost): RedirectResponse
    {
        Gate::authorize('publish', $socialPost);
        [$user] = $this->context($request);

        try {
            $this->publishing->queue($socialPost, $user);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['publish' => $exception->getMessage()]);
        }

        return back()->with('status', 'Social post queued for fake publishing.');
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
}
