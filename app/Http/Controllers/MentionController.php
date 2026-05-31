<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Mention;
use App\Models\User;
use App\Services\MentionIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MentionController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MentionIntelligenceService $mentions,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Mention::class);

        $brandIds = $mentions->brandsForAccount($account)->pluck('id')->map(fn (int $id) => (string) $id)->all();

        $filters = $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'sentiment' => ['nullable', 'string', Rule::in(Mention::SENTIMENTS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'brand_id' => ['nullable', 'string', Rule::in(['account', ...$brandIds])],
        ]);

        return view('app.mentions.index', [
            'account' => $account,
            'brand' => $brand,
            'mentions' => $mentions->paginatedForTenant($account, $brand, $filters),
            'filters' => $filters,
            'sentiments' => Mention::SENTIMENTS,
            'sources' => $mentions->sourcesForTenant($account, $brand),
            'brands' => $mentions->brandsForAccount($account),
            'sentimentOverview' => $mentions->sentimentOverview($account, $brand),
        ]);
    }

    public function show(
        Mention $mention,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MentionIntelligenceService $mentions,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $mention);

        return view('app.mentions.show', [
            'mention' => $mentions->findForTenant($account, $brand, $mention->id),
        ]);
    }
}
