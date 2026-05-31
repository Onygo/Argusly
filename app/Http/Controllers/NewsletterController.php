<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Newsletter;
use App\Models\NewsletterSection;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ContentLanguageService;
use App\Services\NewsletterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NewsletterController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly NewsletterService $newsletters,
        private readonly ContentLanguageService $languages,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', Newsletter::class);

        return view('app.newsletters.index', [
            'account' => $account,
            'brand' => $brand,
            'newsletters' => $this->newsletters->paginatedForTenant($account, $brand),
            'campaigns' => $this->newsletters->campaigns($account, $brand),
            'statuses' => Newsletter::STATUSES,
            'languages' => $this->languages->enabledForBrand($brand),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', Newsletter::class);

        $newsletter = $this->newsletters->create($account, $brand, $user, $request->validate([
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'language' => $this->languages->validationRules($brand),
            'status' => ['required', 'string', Rule::in(Newsletter::STATUSES)],
            'scheduled_at' => ['nullable', 'date'],
        ]));

        return redirect()->route('app.newsletters.show', $newsletter)->with('status', 'Newsletter created.');
    }

    public function show(Request $request, Newsletter $newsletter): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('view', $newsletter);

        $newsletter = $this->newsletters->findForTenant($account, $brand, $newsletter->id);

        return view('app.newsletters.show', [
            'newsletter' => $newsletter,
            'sectionTypes' => NewsletterSection::TYPES,
            'contentAssets' => $this->newsletters->contentAssets($account, $brand, $newsletter->language),
            'languages' => $this->languages->enabledForBrand($brand),
        ]);
    }

    public function update(Request $request, Newsletter $newsletter): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $newsletter);
        $newsletter = $this->newsletters->findForTenant($account, $brand, $newsletter->id);

        $this->newsletters->update($newsletter, $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'language' => $this->languages->validationRules($brand),
            'status' => ['required', 'string', Rule::in(Newsletter::STATUSES)],
        ]));

        return redirect()->route('app.newsletters.show', $newsletter)->with('status', 'Newsletter saved.');
    }

    public function storeSection(Request $request, Newsletter $newsletter): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $newsletter);
        $newsletter = $this->newsletters->findForTenant($account, $brand, $newsletter->id);

        $this->newsletters->addSection($newsletter, $request->validate([
            'type' => ['required', 'string', Rule::in(NewsletterSection::TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'content_asset_id' => ['nullable', 'integer', 'exists:content_assets,id'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]));

        return redirect()->route('app.newsletters.show', $newsletter)->with('status', 'Newsletter section added.');
    }

    public function reorderSections(Request $request, Newsletter $newsletter): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $newsletter);
        $newsletter = $this->newsletters->findForTenant($account, $brand, $newsletter->id);

        $attributes = $request->validate([
            'positions' => ['required', 'array'],
            'positions.*' => ['required', 'integer', 'min:0'],
        ]);

        $this->newsletters->reorderSections($newsletter, $attributes['positions']);

        return redirect()->route('app.newsletters.show', $newsletter)->with('status', 'Newsletter sections reordered.');
    }

    public function saveDraft(Request $request, Newsletter $newsletter): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $newsletter);
        $newsletter = $this->newsletters->findForTenant($account, $brand, $newsletter->id);

        $this->newsletters->saveDraft($newsletter);

        return redirect()->route('app.newsletters.show', $newsletter)->with('status', 'Newsletter saved as draft.');
    }

    public function requestApproval(Request $request, Newsletter $newsletter, ApprovalService $approvals): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $newsletter);
        $newsletter = $this->newsletters->findForTenant($account, $brand, $newsletter->id);

        $newsletter->forceFill(['status' => 'review'])->save();
        $approvals->request($newsletter, $user, $request->input('notes'));

        return redirect()->route('app.newsletters.show', $newsletter)->with('status', 'Newsletter submitted for approval.');
    }
}
