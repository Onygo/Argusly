<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContentAsset;
use App\Models\Organization;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);
        $query = trim((string) $request->query('q', ''));

        $results = $query === ''
            ? $this->emptyResults()
            : [
                'content' => $this->content($account->id, $brand?->id, $query),
                'campaigns' => $this->campaigns($account->id, $brand?->id, $query),
                'contacts' => $this->contacts($account->id, $query),
                'organizations' => $this->organizations($account->id, $query),
                'topics' => $this->topics($account->id, $brand?->id, $query),
            ];

        return view('app.search.index', [
            'query' => $query,
            'results' => $results,
            'total' => collect($results)->sum(fn (Collection $items) => $items->count()),
        ]);
    }

    /**
     * @return array<string, Collection<int, mixed>>
     */
    private function emptyResults(): array
    {
        return [
            'content' => collect(),
            'campaigns' => collect(),
            'contacts' => collect(),
            'organizations' => collect(),
            'topics' => collect(),
        ];
    }

    /**
     * @return Collection<int, ContentAsset>
     */
    private function content(int $accountId, ?int $brandId, string $query): Collection
    {
        return ContentAsset::query()
            ->where('account_id', $accountId)
            ->when($brandId !== null, fn (Builder $builder) => $builder->where('brand_id', $brandId))
            ->where(fn (Builder $builder) => $this->like($builder, $query, ['title', 'slug', 'excerpt', 'body']))
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Campaign>
     */
    private function campaigns(int $accountId, ?int $brandId, string $query): Collection
    {
        return Campaign::query()
            ->where('account_id', $accountId)
            ->when($brandId !== null, fn (Builder $builder) => $builder->where('brand_id', $brandId))
            ->where(fn (Builder $builder) => $this->like($builder, $query, ['name', 'slug', 'description', 'objective']))
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Contact>
     */
    private function contacts(int $accountId, string $query): Collection
    {
        return Contact::query()
            ->where('account_id', $accountId)
            ->where(fn (Builder $builder) => $this->like($builder, $query, ['first_name', 'last_name', 'display_name', 'email', 'notes']))
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Organization>
     */
    private function organizations(int $accountId, string $query): Collection
    {
        return Organization::query()
            ->where('account_id', $accountId)
            ->where(fn (Builder $builder) => $this->like($builder, $query, ['name', 'website', 'industry', 'description']))
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Topic>
     */
    private function topics(int $accountId, ?int $brandId, string $query): Collection
    {
        return Topic::query()
            ->where('account_id', $accountId)
            ->when(
                $brandId !== null,
                fn (Builder $builder) => $builder->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brandId)),
            )
            ->where(fn (Builder $builder) => $this->like($builder, $query, ['name', 'slug', 'description']))
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function like(Builder $builder, string $query, array $columns): void
    {
        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $builder->{$method}($column, 'like', '%'.$query.'%');
        }
    }
}
