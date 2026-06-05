<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\GeneratedAsset;
use App\Models\PublishingAction;
use App\Models\User;
use App\Services\ContentAssetService;
use App\Services\ContentAuditService;
use App\Services\BrandKnowledgeCenterService;
use App\Services\ContentFirstDraftService;
use App\Services\ContentGenerationService;
use App\Services\ContentLanguageService;
use App\Services\ContentLifecycleService;
use App\Services\ContentTranslationService;
use App\Services\PublishingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ContentAssetController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ContentAssetService $contentAssets,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', ContentAsset::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'language' => ['nullable', 'string', Rule::in(app(ContentLanguageService::class)->enabledCodesForBrand($brand))],
        ]);

        return view('app.content.index', [
            'account' => $account,
            'brand' => $brand,
            'assets' => $contentAssets->paginatedForTenant($account, $brand, $filters),
            'filters' => $filters,
            'statuses' => ContentAsset::STATUSES,
            'types' => ContentAsset::TYPES,
            'contentLanguages' => app(ContentLanguageService::class)->enabledForBrand($brand),
        ]);
    }

    public function show(ContentAsset $contentAsset): View
    {
        Gate::authorize('view', $contentAsset);

        return view('app.content.show', [
            'asset' => $contentAsset->load([
                'brand',
                'property',
                'publishingChannel',
                'creator',
                'updater',
                'generatedAssets' => fn ($query) => $query->latest()->limit(10),
                'generatedAssets.creator',
                'audits' => fn ($query) => $query->with('evidenceItems.source')->latest()->limit(10),
                'answerBlocks' => fn ($query) => $query->orderBy('position')->orderBy('id'),
                'lifecycleScores' => fn ($query) => $query->latest('scored_at')->latest()->limit(5),
                'ga4MetricSnapshots' => fn ($query) => $query->with('ga4Property')->latest('date')->latest()->limit(5),
                'searchConsoleQuerySnapshots' => fn ($query) => $query->with('searchConsoleSite')->latest('date')->latest()->limit(5),
                'publishingActions' => fn ($query) => $query->with('publishingChannel')->latest()->limit(10),
                'sourceTranslations' => fn ($query) => $query->with('translatedContentAsset')->latest(),
                'translatedFrom' => fn ($query) => $query->with('sourceContentAsset')->latest(),
            ]),
            'generationTypes' => GeneratedAsset::TYPES,
            'contentLanguages' => app(ContentLanguageService::class)->enabledForBrand($contentAsset->brand),
            'translationTargets' => app(ContentLanguageService::class)->enabledForBrand($contentAsset->brand)
                ->reject(fn ($language) => $language->code === $contentAsset->language)
                ->values(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ContentAsset::class);
        /** @var User $user */
        $user = request()->user();
        $account = app(CurrentAccountContract::class)->get($user);
        $brand = app(CurrentBrandContract::class)->get($user);
        $languages = app(ContentLanguageService::class);
        $brandContext = $account && $brand
            ? app(BrandKnowledgeCenterService::class)->centerForBrand($account, $brand)
            : null;

        return view('app.content.create', [
            'asset' => new ContentAsset([
                'type' => 'article',
                'status' => 'draft',
                'language' => $languages->defaultFor($brand, $brand?->account),
                'locale' => 'en_US',
                'source' => 'manual',
            ]),
            'types' => ContentAsset::TYPES,
            'contentLanguages' => $languages->enabledForBrand($brand),
            'brandContext' => $brandContext,
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ContentAssetService $contentAssets,
        ContentFirstDraftService $firstDrafts,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', ContentAsset::class);

        if ($request->input('creation_mode') === 'guided_first_draft') {
            $attributes = $this->validatedFirstDraftAttributes($request, $brand);
            $attributes['locale'] = app(ContentLanguageService::class)->localeForLanguage($attributes['language']);
            $created = $firstDrafts->create($account, $brand, $user, $attributes);

            return redirect()
                ->route('app.content.show', $created->first())
                ->with('status', $created->count() === 1 ? 'First draft created.' : $created->count().' chained drafts created.');
        }

        $attributes = $this->validatedAttributes($request, $brand);
        $attributes['locale'] = app(ContentLanguageService::class)->localeForLanguage($attributes['language']);
        $this->authorizeStatus($user, $attributes['status'] ?? 'draft', $account->id, $brand->id);
        $asset = $contentAssets->create($account, $brand, $attributes, $user);

        return redirect()->route('app.content.show', $asset)->with('status', 'Content asset created.');
    }

    public function edit(ContentAsset $contentAsset): View
    {
        Gate::authorize('update', $contentAsset);

        return view('app.content.edit', [
            'asset' => $contentAsset,
            'types' => ContentAsset::TYPES,
            'contentLanguages' => app(ContentLanguageService::class)->enabledForBrand($contentAsset->brand),
        ]);
    }

    public function update(Request $request, ContentAsset $contentAsset, ContentAssetService $contentAssets): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('update', $contentAsset);

        $attributes = $this->validatedAttributes($request, $contentAsset->brand);
        $this->authorizeStatus($user, $attributes['status'] ?? $contentAsset->status, $contentAsset->account_id, $contentAsset->brand_id);
        $contentAssets->update($contentAsset, $attributes, $user);

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Content asset updated.');
    }

    public function approve(ContentAsset $contentAsset, ContentAssetService $contentAssets): RedirectResponse
    {
        /** @var User $user */
        $user = request()->user();

        Gate::authorize('approve', $contentAsset);
        $contentAssets->approve($contentAsset, $user);

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Content asset approved.');
    }

    public function publish(ContentAsset $contentAsset, PublishingService $publishing): RedirectResponse
    {
        /** @var User $user */
        $user = request()->user();

        Gate::authorize('publish', $contentAsset);
        try {
            $publishing->request($contentAsset, $user, ['action' => 'publish']);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        }

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Publishing action queued.');
    }

    public function publishingAction(Request $request, ContentAsset $contentAsset, PublishingService $publishing): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('publish', $contentAsset);

        $attributes = $request->validate([
            'action' => ['required', 'string', Rule::in(PublishingAction::ACTIONS)],
            'publishing_channel_id' => ['nullable', 'integer', 'exists:publishing_channels,id'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        try {
            $publishing->request($contentAsset, $user, $attributes);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        } catch (InvalidArgumentException) {
            abort(403);
        }

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Publishing action queued.');
    }

    public function generate(Request $request, ContentAsset $contentAsset, ContentGenerationService $generation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('update', $contentAsset);

        $attributes = $request->validate([
            'type' => ['nullable', 'string', Rule::in(GeneratedAsset::TYPES)],
            'prompt' => ['nullable', 'string'],
            'language' => ['nullable', 'string', Rule::in(app(ContentLanguageService::class)->enabledCodesForBrand($contentAsset->brand))],
        ]);

        try {
            $generation->requestForContentAsset($contentAsset, $user, $attributes);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        }

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Generation job queued.');
    }

    public function translate(Request $request, ContentAsset $contentAsset, ContentTranslationService $translations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('update', $contentAsset);

        $targets = app(ContentLanguageService::class)->enabledCodesForBrand($contentAsset->brand);
        $targets = array_values(array_diff($targets, [$contentAsset->language]));

        $attributes = $request->validate([
            'target_languages' => ['required', 'array', 'min:1'],
            'target_languages.*' => ['required', 'string', Rule::in($targets)],
        ]);

        try {
            $created = $translations->createTranslations($contentAsset, $user, $attributes['target_languages']);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['translations' => $exception->getMessage()]);
        }

        return redirect()->route('app.content.show', $contentAsset)->with('status', $created->count().' translation draft(s) created.');
    }

    public function audit(ContentAsset $contentAsset, ContentAuditService $audits): RedirectResponse
    {
        /** @var User $user */
        $user = request()->user();

        Gate::authorize('update', $contentAsset);

        try {
            $audits->requestForContentAsset($contentAsset, $user);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        }

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Content audit queued.');
    }

    public function lifecycle(ContentAsset $contentAsset, ContentLifecycleService $lifecycle): RedirectResponse
    {
        /** @var User $user */
        $user = request()->user();

        Gate::authorize('update', $contentAsset);

        try {
            $lifecycle->requestForContentAsset($contentAsset, $user);
        } catch (InsufficientCreditsException $exception) {
            return $this->insufficientCredits($exception);
        }

        return redirect()->route('app.content.show', $contentAsset)->with('status', 'Lifecycle calculation queued.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'type' => ['required', 'string', Rule::in(ContentAsset::TYPES)],
            'status' => ['nullable', 'string', Rule::in(ContentAsset::STATUSES)],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'language' => app(ContentLanguageService::class)->validationRules($brand),
            'locale' => ['nullable', 'string', 'max:32'],
            'source' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFirstDraftAttributes(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'creation_mode' => ['required', 'string', Rule::in(['guided_first_draft'])],
            'draft_mode' => ['required', 'string', Rule::in(['single', 'chain'])],
            'title' => ['nullable', 'string', 'max:255', 'required_without:primary_keyword'],
            'primary_keyword' => ['nullable', 'string', 'max:255', 'required_without:title'],
            'secondary_keywords' => ['nullable', 'string', 'max:1000'],
            'angle' => ['nullable', 'string', 'max:1000'],
            'audience' => ['nullable', 'string', 'max:500'],
            'chain_count' => ['exclude_unless:draft_mode,chain', 'required', 'integer', 'min:2', 'max:6'],
            'type' => ['required', 'string', Rule::in(['article', 'page', 'landing_page', 'faq'])],
            'language' => app(ContentLanguageService::class)->validationRules($brand),
            'locale' => ['nullable', 'string', 'max:32'],
        ]);
    }

    private function authorizeStatus(User $user, string $status, int $accountId, int $brandId): void
    {
        if (in_array($status, ['approved', 'scheduled', 'published'], true)) {
            Gate::forUser($user)->authorize('publish_content', ['account_id' => $accountId, 'brand_id' => $brandId]);
        }
    }

    private function insufficientCredits(InsufficientCreditsException $exception): RedirectResponse
    {
        return back()->withErrors([
            'credits' => "Not enough credits. {$exception->requiredCredits} required, {$exception->availableCredits} available.",
        ]);
    }
}
