<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Topic;
use App\Models\TopicCluster;
use App\Models\TopicRelationship;
use App\Models\User;
use App\Services\TopicIntelligenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class TopicController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Topic::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Topic::STATUSES)],
            'scope' => ['nullable', 'string', Rule::in(['account', 'brand'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return view('app.topics.index', [
            'account' => $account,
            'brand' => $brand,
            'topics' => $topics->paginatedForTenant($account, $brand, $filters),
            'clusters' => $brand ? $topics->clustersForTenant($account, $brand) : collect(),
            'filters' => $filters,
            'statuses' => Topic::STATUSES,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Topic::class);

        return view('app.topics.create', [
            'topic' => new Topic(['status' => 'active']),
            'statuses' => Topic::STATUSES,
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Topic::class);

        try {
            $topic = $topics->create($account, $brand, $this->validatedTopic($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['topic' => $exception->getMessage()]);
        }

        return redirect()->route('app.topics.show', $topic)->with('status', 'Topic created.');
    }

    public function show(
        Topic $topic,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $topic);

        $topic = $topics->findForTenant($account, $brand, $topic->id);

        return view('app.topics.show', [
            'topic' => $topic,
            'availableTopics' => $topics->paginatedForTenant($account, $brand, [], 100)->getCollection()->reject(fn (Topic $availableTopic) => $availableTopic->id === $topic->id),
            'relationshipTypes' => TopicRelationship::TYPES,
        ]);
    }

    public function edit(
        Topic $topic,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('update', $topic);

        $topic = $topics->findForTenant($account, $brand, $topic->id);

        return view('app.topics.edit', [
            'topic' => $topic,
            'statuses' => Topic::STATUSES,
        ]);
    }

    public function update(
        Request $request,
        Topic $topic,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('update', $topic);

        $topic = $topics->findForTenant($account, $brand, $topic->id);

        try {
            $topics->update($topic, $brand, $this->validatedTopic($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['topic' => $exception->getMessage()]);
        }

        return redirect()->route('app.topics.show', $topic)->with('status', 'Topic updated.');
    }

    public function destroy(
        Topic $topic,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('delete', $topic);

        try {
            $topics->deleteForTenant($account, $brand, $topic);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['topic' => $exception->getMessage()]);
        }

        return redirect()->route('app.topics.index')->with('status', 'Topic deleted.');
    }

    public function storeRelationship(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('manage_account');

        $attributes = $request->validate([
            'parent_topic_id' => ['required', 'integer', 'exists:topics,id'],
            'child_topic_id' => ['required', 'integer', 'exists:topics,id', 'different:parent_topic_id'],
            'relationship_type' => ['required', 'string', Rule::in(TopicRelationship::TYPES)],
        ]);

        try {
            $relationship = $topics->createRelationship($account, $brand, $attributes);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['relationship' => $exception->getMessage()]);
        }

        return redirect()->route('app.topics.show', $relationship->parent_topic_id)->with('status', 'Topic relationship created.');
    }

    public function storeCluster(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('manage_account');

        $cluster = $topics->createCluster($account, $brand, $this->validatedCluster($request));

        return redirect()->route('app.topics.clusters.show', $cluster)->with('status', 'Topic cluster created.');
    }

    public function showCluster(
        TopicCluster $cluster,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('view_dashboard');

        return view('app.topics.clusters.show', [
            'cluster' => $topics->findClusterForTenant($account, $brand, $cluster->id),
            'availableTopics' => $topics->paginatedForTenant($account, $brand, [], 100)->getCollection(),
        ]);
    }

    public function updateCluster(
        Request $request,
        TopicCluster $cluster,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        TopicIntelligenceService $topics,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('manage_account');

        try {
            $topics->updateCluster($cluster, $account, $brand, $this->validatedCluster($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['cluster' => $exception->getMessage()]);
        }

        return redirect()->route('app.topics.clusters.show', $cluster)->with('status', 'Topic cluster updated.');
    }

    public function destroyCluster(
        TopicCluster $cluster,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('manage_account');
        abort_unless($cluster->account_id === $account->id && $cluster->brand_id === $brand->id, 404);

        $cluster->delete();

        return redirect()->route('app.topics.index')->with('status', 'Topic cluster deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTopic(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(Topic::STATUSES)],
            'brand_scoped' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'importance_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCluster(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:topics,id'],
        ]);
    }
}
