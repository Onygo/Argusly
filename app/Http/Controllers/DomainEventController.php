<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\DomainEvent;
use App\Models\User;
use App\Services\DomainEventService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DomainEventController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        DomainEventService $events,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $filters = $request->validate([
            'event_type' => ['nullable', 'string', Rule::in(DomainEvent::TYPES)],
        ]);

        return view('app.domain-events.index', [
            'account' => $account,
            'brand' => $brand,
            'events' => $events->paginatedForTenant($account, $brand, $filters),
            'eventTypes' => DomainEvent::TYPES,
            'filters' => $filters,
        ]);
    }
}
