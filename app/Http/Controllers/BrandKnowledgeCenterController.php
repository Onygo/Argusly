<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\BrandNarrative;
use App\Models\BrandProduct;
use App\Models\BrandService;
use App\Models\User;
use App\Services\BrandKnowledgeCenterService;
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

        return redirect()->route('settings.knowledge-center')->with('status', 'Brand profile updated.');
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

        return redirect()->route('settings.knowledge-center')->with('status', 'Brand product added.');
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

        return redirect()->route('settings.knowledge-center')->with('status', 'Brand service added.');
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

        return redirect()->route('settings.knowledge-center')->with('status', 'Brand narrative added.');
    }
}
