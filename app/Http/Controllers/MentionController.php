<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Mention;
use App\Models\Source;
use App\Models\User;
use App\Services\BrandIntelligenceService;
use App\Services\MentionIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MentionController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MentionIntelligenceService $mentions,
        BrandIntelligenceService $brandIntelligence,
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
            'source_type' => ['nullable', 'string', Rule::in(Source::TYPES)],
            'sentiment' => ['nullable', 'string', Rule::in(Mention::SENTIMENTS)],
            'author' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
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
            'brandIntelligence' => $brandIntelligence->dashboard($account, $brand, $filters),
            'sourceTypes' => Source::TYPES,
        ]);
    }

    public function export(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandIntelligenceService $brandIntelligence,
        MentionIntelligenceService $mentions,
    ): StreamedResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Mention::class);

        $brandIds = $mentions->brandsForAccount($account)->pluck('id')->map(fn (int $id) => (string) $id)->all();
        $filters = $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'source_type' => ['nullable', 'string', Rule::in(Source::TYPES)],
            'sentiment' => ['nullable', 'string', Rule::in(Mention::SENTIMENTS)],
            'author' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'brand_id' => ['nullable', 'string', Rule::in(['account', ...$brandIds])],
        ]);

        $rows = $brandIntelligence->exportRows($account, $brand, $filters);
        $filename = 'argusly-brand-intelligence-mentions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['published_at', 'brand', 'title', 'source', 'source_type', 'publication', 'author', 'sentiment', 'impact_score', 'url', 'topics', 'entities']);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
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
