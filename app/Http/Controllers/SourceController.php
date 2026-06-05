<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Source;
use App\Models\User;
use App\Services\SourceRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class SourceController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        SourceRegistryService $sources,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Source::class);

        $filters = $request->validate([
            'type' => ['nullable', 'string', Rule::in(Source::TYPES)],
            'provider' => ['nullable', 'string', Rule::in(Source::PROVIDERS)],
            'status' => ['nullable', 'string', Rule::in(Source::STATUSES)],
            'scope' => ['nullable', 'string', Rule::in(['global', 'account', 'brand'])],
        ]);

        return view('app.sources.index', [
            'account' => $account,
            'brand' => $brand,
            'sources' => $sources->paginatedForTenant($account, $brand, $filters),
            'filters' => $filters,
            'types' => Source::TYPES,
            'providers' => Source::PROVIDERS,
            'statuses' => Source::STATUSES,
            'connections' => $sources->integrationConnections($account, $brand),
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        SourceRegistryService $sources,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Source::class);

        try {
            $source = $sources->create($account, $brand, $this->validatedAttributes($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['source' => $exception->getMessage()]);
        }

        return redirect()->route('app.sources.show', $source)->with('status', 'Source created.');
    }

    public function show(
        Source $source,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        SourceRegistryService $sources,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $source);

        $source = $sources->findForTenant($account, $brand, $source->id);

        return view('app.sources.show', [
            'source' => $source,
            'syncs' => $sources->syncHistory($account, $brand, $source),
        ]);
    }

    public function history(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        SourceRegistryService $sources,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Source::class);

        return view('app.sources.syncs', [
            'syncs' => $sources->syncHistory($account, $brand),
        ]);
    }

    public function planSync(
        Source $source,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        SourceRegistryService $sources,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('sync', $source);

        $source = $sources->findForTenant($account, $brand, $source->id);

        try {
            $sources->dispatchSync($source);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('app.sources.show', $source)->withErrors(['sync' => $exception->getMessage()]);
        }

        return redirect()->route('app.sources.show', $source)->with('status', 'Source sync queued.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(Source::TYPES)],
            'provider' => ['required', 'string', Rule::in(Source::PROVIDERS)],
            'status' => ['required', 'string', Rule::in(Source::STATUSES)],
            'scope' => ['required', 'string', Rule::in(['global', 'account', 'brand'])],
            'integration_connection_id' => ['nullable', 'integer', 'exists:integration_connections,id'],
        ]);
    }
}
