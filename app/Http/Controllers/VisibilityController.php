<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\User;
use App\Models\VisibilityCheck;
use App\Models\VisibilityPromptTemplate;
use App\Services\ContentLanguageService;
use App\Services\Visibility\ProviderRegistry;
use App\Services\Visibility\ProviderRunService;
use App\Services\VisibilityMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VisibilityController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        VisibilityMonitoringService $visibility,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', VisibilityCheck::class);
        $filters = $this->visibilityFilters($request);

        return view('app.visibility.index', [
            'account' => $account,
            'brand' => $brand,
            'checks' => $visibility->checksForTenant($account, $brand),
            'timeline' => $visibility->timelineForTenant($account, $brand, filters: $filters),
            'stats' => $visibility->dashboardStats($account, $brand),
            'promptTemplates' => $visibility->promptTemplatesForTenant($account, $brand, $filters),
            'providerRuns' => $visibility->providerRunsForTenant($account, $brand, filters: $filters),
            'latestRunsByLanguage' => $visibility->latestRunsByLanguage($account, $brand, $filters),
            'providers' => VisibilityCheck::PROVIDERS,
            'adapterProviders' => app(ProviderRegistry::class)->providers(),
            'brands' => $account->brands()->orderBy('name')->get(),
            'examplePrompts' => $this->examplePrompts($brand),
            'contentLanguages' => app(ContentLanguageService::class)->enabledForBrand($brand),
            'filters' => $filters,
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        VisibilityMonitoringService $visibility,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', VisibilityCheck::class);

        $attributes = $request->validate([
            'provider' => ['required', 'string', Rule::in(VisibilityCheck::PROVIDERS)],
            'query' => ['required', 'string', 'max:1000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(VisibilityCheck::STATUSES)],
        ]);

        $check = $visibility->createCheck($account, $brand, $attributes);
        $visibility->queueCheck($check);

        return redirect()->route('app.visibility')->with('status', 'Visibility check created and placeholder monitoring queued.');
    }

    public function storePrompt(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        VisibilityMonitoringService $visibility,
    ): RedirectResponse {
        [$user, $account, $brand] = $this->context($request, $currentAccount, $currentBrand);
        Gate::forUser($user)->authorize('manage_visibility', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $targetBrand = $this->brandFromRequest($request, $account, $brand);
        $visibility->createPromptTemplate($account, $targetBrand, $this->promptAttributes($request, $account, $targetBrand));

        return redirect()->route('app.visibility')->with('status', 'Prompt created.');
    }

    public function updatePrompt(
        Request $request,
        VisibilityPromptTemplate $promptTemplate,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
    ): RedirectResponse {
        [$user, $account, $brand] = $this->context($request, $currentAccount, $currentBrand);
        Gate::forUser($user)->authorize('manage_visibility', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $promptTemplate = $this->promptTemplate($promptTemplate->id, $account, $brand);
        $targetBrand = $this->brandFromRequest($request, $account, $brand);
        $attributes = $this->promptAttributes($request, $account, $targetBrand);

        $promptTemplate->update([
            'brand_id' => $targetBrand->id,
            ...$attributes,
        ]);

        return redirect()->route('app.visibility')->with('status', 'Prompt updated.');
    }

    public function archivePrompt(
        Request $request,
        VisibilityPromptTemplate $promptTemplate,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
    ): RedirectResponse {
        [$user, $account, $brand] = $this->context($request, $currentAccount, $currentBrand);
        Gate::forUser($user)->authorize('manage_visibility', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $this->promptTemplate($promptTemplate->id, $account, $brand)->update(['status' => 'archived']);

        return redirect()->route('app.visibility')->with('status', 'Prompt archived.');
    }

    public function duplicatePrompt(
        Request $request,
        VisibilityPromptTemplate $promptTemplate,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
    ): RedirectResponse {
        [$user, $account, $brand] = $this->context($request, $currentAccount, $currentBrand);
        Gate::forUser($user)->authorize('manage_visibility', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $template = $this->promptTemplate($promptTemplate->id, $account, $brand);
        $copyName = $this->copyName($template, $account, $brand);

        VisibilityPromptTemplate::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $copyName,
            'prompt' => $template->prompt,
            'language' => $template->language,
            'intent' => $template->intent,
            'locale' => $template->locale,
            'market' => $template->market,
            'persona' => $template->persona,
            'status' => 'active',
            'metadata' => [
                ...($template->metadata ?? []),
                'duplicated_from' => $template->id,
            ],
        ]);

        return redirect()->route('app.visibility')->with('status', 'Prompt duplicated.');
    }

    public function runPrompt(
        Request $request,
        VisibilityPromptTemplate $promptTemplate,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ProviderRunService $runs,
        ProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $account, $brand] = $this->context($request, $currentAccount, $currentBrand);
        Gate::forUser($user)->authorize('manage_visibility', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $template = $this->promptTemplate($promptTemplate->id, $account, $brand);
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($providers->keys())],
        ]);
        $prompt = str_replace('{brand}', $brand->name, $template->prompt);

        $runs->runPrompt(
            account: $account,
            brand: $brand,
            provider: $validated['provider'],
            prompt: $prompt,
            template: $template,
            context: [
                'brand' => $brand->name,
                'language' => $template->language,
                'intent' => $template->intent,
                'locale' => $template->locale,
                'market' => $template->market,
                'persona' => $template->persona,
            ],
        );

        return redirect()->route('app.visibility')->with('status', 'Prompt test run completed with fake provider.');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function context(Request $request, CurrentAccountContract $currentAccount, CurrentBrandContract $currentBrand): array
    {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        return [$user, $account, $brand];
    }

    private function promptTemplate(int $id, Account $account, Brand $brand): VisibilityPromptTemplate
    {
        return VisibilityPromptTemplate::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->findOrFail($id);
    }

    private function brandFromRequest(Request $request, Account $account, Brand $fallback): Brand
    {
        $brandId = $request->integer('brand_id') ?: $fallback->id;

        return Brand::query()
            ->where('account_id', $account->id)
            ->findOrFail($brandId);
    }

    /**
     * @return array{name: string, prompt: string, language?: string|null, intent?: string|null, locale?: string|null, market?: string|null, persona?: string|null, status?: string|null, metadata?: array<string, mixed>|null}
     */
    private function promptAttributes(Request $request, Account $account, Brand $brand): array
    {
        $languages = app(ContentLanguageService::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:5000'],
            'language' => $languages->validationRules($brand),
            'intent' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:32'],
            'market' => ['nullable', 'string', 'max:255'],
            'persona' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(VisibilityPromptTemplate::STATUSES)],
        ]);

        return [
            ...$validated,
            'locale' => $validated['locale'] ?? $languages->localeForLanguage($validated['language']),
            'status' => $validated['status'] ?? 'active',
            'metadata' => [
                'assigned_brand_id' => $brand->id,
                'assigned_account_id' => $account->id,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function examplePrompts(Brand $brand): array
    {
        return [
            'NL market, Dutch language: Wat zijn de beste AI visibility tools voor B2B-bedrijven?',
            'BE market, Dutch language: Welke marketingplatformen helpen Belgische teams met AI-zichtbaarheid?',
            'US market, English language: Best AI visibility tools for B2B companies',
            'DE market, German language: Welche Anbieter helfen bei KI-Sichtbarkeit?',
            'FR market, French language: Quels outils suivent la visibilité IA des marques?',
            'ES market, Spanish language: Mejores plataformas de visibilidad en IA para marcas',
        ];
    }

    /**
     * @return array{language?: string|null, market?: string|null}
     */
    private function visibilityFilters(Request $request): array
    {
        return array_filter([
            'language' => $request->string('language')->toString() ?: null,
            'market' => $request->string('market')->toString() ?: null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function copyName(VisibilityPromptTemplate $template, Account $account, Brand $brand): string
    {
        $base = Str::limit($template->name, 230, '').' Copy';
        $name = $base;
        $index = 2;

        while (VisibilityPromptTemplate::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('name', $name)
            ->exists()) {
            $name = "{$base} {$index}";
            $index++;
        }

        return $name;
    }
}
