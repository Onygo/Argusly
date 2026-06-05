<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\BrandNarrative;
use App\Models\BrandProduct;
use App\Models\BrandService;
use App\Models\User;
use App\Services\BrandKnowledgeCenterService;
use App\Services\BrandSetupGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BrandKnowledgeCenterController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeCenterService $knowledgeCenter,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        return view('app.settings.knowledge-center', [
            'account' => $account,
            'brand' => $brand,
            'center' => $knowledgeCenter->centerForBrand($account, $brand),
            'statuses' => BrandProduct::STATUSES,
            'importanceLevels' => BrandNarrative::IMPORTANCE_LEVELS,
            'routeBase' => $this->routeBase($request),
            'setupPreview' => session('brand_setup_preview'),
            'setupSections' => $this->setupSections(),
        ]);
    }

    public function updateProfile(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeCenterService $knowledgeCenter,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'official_name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
            'mission' => ['nullable', 'string'],
            'vision' => ['nullable', 'string'],
            'positioning' => ['nullable', 'string'],
            'value_proposition' => ['nullable', 'string'],
            'tone_of_voice' => ['nullable', 'string'],
            'primary_audience' => ['nullable', 'string'],
            'secondary_audience' => ['nullable', 'string'],
            'website' => ['nullable', 'url', 'max:255'],
        ]);

        $knowledgeCenter->updateProfile($account, $brand, $attributes);

        return redirect()->route($this->routeBase($request))->with('status', 'Brand profile updated.');
    }

    public function storeProduct(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeCenterService $knowledgeCenter,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'status' => ['required', 'string', Rule::in(BrandProduct::STATUSES)],
        ]);

        $knowledgeCenter->createProduct($account, $brand, $attributes);

        return redirect()->route($this->routeBase($request))->with('status', 'Brand product added.');
    }

    public function storeService(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeCenterService $knowledgeCenter,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(BrandService::STATUSES)],
        ]);

        $knowledgeCenter->createService($account, $brand, $attributes);

        return redirect()->route($this->routeBase($request))->with('status', 'Brand service added.');
    }

    public function storeNarrative(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeCenterService $knowledgeCenter,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'importance' => ['required', 'string', Rule::in(BrandNarrative::IMPORTANCE_LEVELS)],
            'status' => ['required', 'string', Rule::in(BrandNarrative::STATUSES)],
        ]);

        $knowledgeCenter->createNarrative($account, $brand, $attributes);

        return redirect()->route($this->routeBase($request))->with('status', 'Brand narrative added.');
    }

    public function generateSetup(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandSetupGenerationService $setupGenerator,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'input_method' => ['required', 'string', Rule::in(['paste_text', 'website_url', 'guided_input'])],
            'source_text' => ['nullable', 'string', 'required_if:input_method,paste_text'],
            'website_url' => ['nullable', 'url', 'required_if:input_method,website_url', 'max:255'],
            'guided_company' => ['nullable', 'string'],
            'guided_offer' => ['nullable', 'string'],
            'guided_audience' => ['nullable', 'string'],
            'guided_positioning' => ['nullable', 'string'],
            'guided_voice' => ['nullable', 'string'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'string', Rule::in(array_keys($this->setupSections()))],
        ]);

        $preview = $setupGenerator->generate($account, $brand, $attributes);

        return redirect()
            ->route($this->routeBase($request))
            ->with('status', 'AI brand setup generated. Review the preview before applying it.')
            ->with('brand_setup_preview', $preview);
    }

    public function applySetup(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandSetupGenerationService $setupGenerator,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'payload' => ['required', 'json'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'string', Rule::in(array_keys($this->setupSections()))],
        ]);

        $result = $setupGenerator->apply(
            $account,
            $brand,
            json_decode($attributes['payload'], true),
            $attributes['sections'],
        );

        return redirect()
            ->route($this->routeBase($request))
            ->with('status', "AI brand setup applied. {$result['narratives_created']} narratives and {$result['audiences_created']} personas were prepared.");
    }

    private function routeBase(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'app.content.brand-voice')
            ? 'app.content.brand-voice'
            : 'settings.knowledge-center';
    }

    /**
     * @return array<string, string>
     */
    private function setupSections(): array
    {
        return [
            'company_profile' => 'Company profile',
            'brand_voices' => 'Brand voices',
            'buyer_personas' => 'Buyer personas',
            'team_personas' => 'Team personas',
        ];
    }
}
