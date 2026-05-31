<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\AnswerBlock;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\User;
use App\Services\ContentLanguageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnswerBlockController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', AnswerBlock::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);

        $answerBlocks = AnswerBlock::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with('contentAsset')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('app.content.answer-blocks.index', [
            'answerBlocks' => $answerBlocks,
            'filters' => $filters,
            'types' => AnswerBlock::TYPES,
            'statuses' => AnswerBlock::STATUSES,
        ]);
    }

    public function create(?ContentAsset $contentAsset = null): View
    {
        Gate::authorize('create', AnswerBlock::class);
        /** @var User $user */
        $user = request()->user();
        $brand = $contentAsset?->brand ?? app(CurrentBrandContract::class)->get($user);
        $account = $contentAsset?->account ?? $brand?->account;

        if ($contentAsset) {
            Gate::authorize('view', $contentAsset);
        }

        return view('app.content.answer-blocks.create', [
            'answerBlock' => new AnswerBlock([
                'content_asset_id' => $contentAsset?->id,
                'type' => 'direct_answer',
                'status' => 'draft',
                'language' => $contentAsset?->language ?? app(ContentLanguageService::class)->defaultFor($brand, $account),
            ]),
            'contentAsset' => $contentAsset,
            'types' => AnswerBlock::TYPES,
            'contentLanguages' => app(ContentLanguageService::class)->enabledForBrand($brand),
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ?ContentAsset $contentAsset = null,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', AnswerBlock::class);

        if ($contentAsset) {
            Gate::authorize('view', $contentAsset);
        }

        $linkedContentAsset = $contentAsset ?? $this->linkedContentAsset($request->all());
        $attributes = $this->validatedAttributes($request, $linkedContentAsset?->brand ?? $brand);
        $this->authorizeStatus($user, $attributes['status'] ?? 'draft', $account->id, $brand->id);

        $answerBlock = AnswerBlock::query()->create([
            ...$attributes,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $linkedContentAsset?->id,
        ]);

        return redirect()->route('app.content.answer-blocks.show', $answerBlock)->with('status', 'Answer block created.');
    }

    public function show(AnswerBlock $answerBlock): View
    {
        Gate::authorize('view', $answerBlock);

        return view('app.content.answer-blocks.show', [
            'answerBlock' => $answerBlock->load('contentAsset'),
        ]);
    }

    public function edit(AnswerBlock $answerBlock): View
    {
        Gate::authorize('update', $answerBlock);

        return view('app.content.answer-blocks.edit', [
            'answerBlock' => $answerBlock->load('contentAsset'),
            'contentAsset' => $answerBlock->contentAsset,
            'types' => AnswerBlock::TYPES,
            'contentLanguages' => app(ContentLanguageService::class)->enabledForBrand($answerBlock->brand),
        ]);
    }

    public function update(Request $request, AnswerBlock $answerBlock): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('update', $answerBlock);

        $attributes = $this->validatedAttributes($request, $answerBlock->brand);
        $this->authorizeStatus($user, $attributes['status'] ?? $answerBlock->status, $answerBlock->account_id, $answerBlock->brand_id);

        $answerBlock->fill($attributes)->save();

        return redirect()->route('app.content.answer-blocks.show', $answerBlock)->with('status', 'Answer block updated.');
    }

    public function destroy(AnswerBlock $answerBlock): RedirectResponse
    {
        Gate::authorize('delete', $answerBlock);

        $answerBlock->update(['status' => 'archived']);

        return redirect()->route('app.content.answer-blocks.index')->with('status', 'Answer block archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'content_asset_id' => ['nullable', 'integer', 'exists:content_assets,id'],
            'question' => ['required', 'string'],
            'answer' => ['required', 'string'],
            'type' => ['required', 'string', Rule::in(AnswerBlock::TYPES)],
            'status' => ['nullable', 'string', Rule::in(AnswerBlock::STATUSES)],
            'language' => app(ContentLanguageService::class)->validationRules($brand),
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function authorizeStatus(User $user, string $status, int $accountId, int $brandId): void
    {
        if (in_array($status, ['approved', 'published'], true)) {
            Gate::forUser($user)->authorize('publish_content', ['account_id' => $accountId, 'brand_id' => $brandId]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkedContentAsset(array $attributes): ?ContentAsset
    {
        $contentAssetId = $attributes['content_asset_id'] ?? null;

        if (! $contentAssetId) {
            return null;
        }

        $contentAsset = ContentAsset::query()->findOrFail($contentAssetId);
        Gate::authorize('view', $contentAsset);

        return $contentAsset;
    }
}
