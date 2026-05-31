<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Audience;
use App\Models\AudienceMember;
use App\Models\Segment;
use App\Models\User;
use App\Services\AudienceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AudienceController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly AudienceService $audiences,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Audience::class);

        return view('app.audiences.index', [
            'account' => $account,
            'brand' => $brand,
            'audiences' => $this->audiences->paginatedForTenant($account, $brand),
            'segments' => $this->audiences->segments($account, $brand),
            'audienceOptions' => $this->audiences->audiences($account, $brand),
            'statuses' => Audience::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Audience::class);

        $audience = $this->audiences->createAudience($account, $brand, $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(Audience::STATUSES)],
        ]));

        return redirect()->route('app.audiences.show', $audience)->with('status', 'Audience created.');
    }

    public function show(Request $request, Audience $audience): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $audience);

        $audience = $this->audiences->findForTenant($account, $brand, $audience->id);

        return view('app.audiences.show', [
            'audience' => $audience,
            'contacts' => $this->audiences->contacts($account),
            'statuses' => AudienceMember::STATUSES,
            'segmentStatuses' => Segment::STATUSES,
        ]);
    }

    public function storeMember(Request $request, Audience $audience): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        $audience = $this->audiences->findForTenant($account, $brand, $audience->id);
        Gate::authorize('update', $audience);

        $this->audiences->addMember($audience, $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(AudienceMember::STATUSES)],
            'source' => ['nullable', 'string', 'max:255'],
        ]));

        return redirect()->route('app.audiences.show', $audience)->with('status', 'Audience member added.');
    }

    public function storeSegment(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Segment::class);

        $attributes = $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'audience_id' => ['nullable', 'integer', 'exists:audiences,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'rules_json' => ['nullable', 'json'],
            'status' => ['required', 'string', Rule::in(Segment::STATUSES)],
        ]);

        $attributes['rules'] = isset($attributes['rules_json']) && $attributes['rules_json'] !== null
            ? json_decode($attributes['rules_json'], true)
            : null;

        $segment = $this->audiences->createSegment($account, $brand, $attributes);

        return redirect()->route('app.audiences')->with('status', "Segment {$segment->name} created.");
    }
}
