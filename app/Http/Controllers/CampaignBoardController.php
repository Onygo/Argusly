<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\User;
use App\Services\CampaignBoardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CampaignBoardController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly CampaignBoardService $boards,
    ) {}

    public function show(Request $request, Campaign $campaign): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);

        Gate::authorize('view', $campaign);
        $campaign = $this->boards->findForTenant($account, $brand, $campaign->id);

        return view('app.campaigns.board', [
            'campaign' => $campaign,
            'stages' => $this->boards->stages($campaign),
            'assignableUsers' => $this->boards->assignableUsers($campaign),
            'relatedTypes' => array_keys($this->boards->relatedTypes()),
            'itemStatuses' => CampaignItem::STATUSES,
        ]);
    }

    public function storeItem(Request $request, Campaign $campaign): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);

        Gate::authorize('update', $campaign);
        $campaign = $this->boards->findForTenant($account, $brand, $campaign->id);

        $this->boards->createItem($campaign, $request->validate([
            'campaign_stage_id' => ['nullable', 'integer', 'exists:campaign_stages,id'],
            'related_type' => ['nullable', 'string', Rule::in(array_keys($this->boards->relatedTypes()))],
            'related_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(CampaignItem::STATUSES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]));

        return redirect()->route('app.campaigns.board', $campaign)->with('status', 'Campaign board item created.');
    }

    public function moveItem(Request $request, Campaign $campaign, CampaignItem $item): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account && $brand, 403);

        Gate::authorize('update', $campaign);
        $campaign = $this->boards->findForTenant($account, $brand, $campaign->id);

        $attributes = $request->validate([
            'direction' => ['required', 'string', Rule::in(['left', 'right'])],
        ]);

        $this->boards->moveItem($campaign, $item, $attributes['direction']);

        return redirect()->route('app.campaigns.board', $campaign)->with('status', 'Campaign board item moved.');
    }
}
