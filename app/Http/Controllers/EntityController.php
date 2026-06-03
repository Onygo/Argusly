<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Entity;
use App\Models\User;
use App\Services\EntityIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EntityController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        EntityIntelligenceService $entities,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Entity::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', 'string', Rule::in(Entity::TYPES)],
            'status' => ['nullable', 'string', Rule::in(Entity::STATUSES)],
            'scope' => ['nullable', 'string', Rule::in(['global', 'account', 'brand'])],
        ]);

        return view('app.entities.index', [
            'account' => $account,
            'brand' => $brand,
            'entities' => $entities->paginatedForTenant($account, $brand, $filters),
            'filters' => $filters,
            'entityTypes' => Entity::TYPES,
            'statuses' => Entity::STATUSES,
        ]);
    }

    public function show(
        Entity $entity,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        EntityIntelligenceService $entities,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $entity);

        return view('app.entities.show', [
            'entity' => $entities->findForTenant($account, $brand, $entity->id),
        ]);
    }
}
