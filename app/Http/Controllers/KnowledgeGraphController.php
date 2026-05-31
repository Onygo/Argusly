<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Entity;
use App\Models\EntityRelationship;
use App\Models\User;
use App\Services\BrandKnowledgeGraphService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KnowledgeGraphController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeGraphService $knowledgeGraph,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        return view('app.settings.knowledge-graph', [
            'account' => $account,
            'brand' => $brand,
            'graph' => $knowledgeGraph->graphForBrand($account, $brand),
            'entityTypes' => Entity::TYPES,
            'relationshipTypes' => EntityRelationship::TYPES,
        ]);
    }

    public function storeEntity(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeGraphService $knowledgeGraph,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aliases' => ['nullable', 'string'],
            'entity_type' => ['required', 'string', Rule::in(Entity::TYPES)],
        ]);

        $knowledgeGraph->createForBrand($account, $brand, $attributes);

        return redirect()->route('settings.knowledge-graph')->with('status', 'Entity added to brand knowledge graph.');
    }

    public function storeRelationship(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BrandKnowledgeGraphService $knowledgeGraph,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);

        $attributes = $request->validate([
            'source_entity_id' => ['required', 'integer'],
            'target_entity_id' => ['required', 'integer', 'different:source_entity_id'],
            'relationship_type' => ['required', 'string', Rule::in(EntityRelationship::TYPES)],
        ]);

        $knowledgeGraph->relate(
            $account,
            $brand,
            (int) $attributes['source_entity_id'],
            (int) $attributes['target_entity_id'],
            $attributes['relationship_type'],
        );

        return redirect()->route('settings.knowledge-graph')->with('status', 'Entity relationship added.');
    }
}
