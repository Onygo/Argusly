<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Relationship;
use App\Models\User;
use App\Services\RelationshipIntelligenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RelationshipController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        RelationshipIntelligenceService $relationships,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Relationship::class);

        return view('app.relationships.index', [
            'account' => $account,
            'contacts' => $relationships->contactsForAccount($account),
            'organizations' => $relationships->organizationsForAccount($account),
            'relationships' => $relationships->relationshipsForAccount($account),
            'graph' => $relationships->graph($account),
            'nodes' => $relationships->nodes($account),
            'relationshipTypes' => Relationship::TYPES,
        ]);
    }

    public function storeContact(
        Request $request,
        CurrentAccountContract $currentAccount,
        RelationshipIntelligenceService $relationships,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Contact::class);

        $contact = $relationships->createContact($account, $this->validatedContact($request));

        return redirect()->route('app.relationships.contacts.show', $contact)->with('status', 'Contact created.');
    }

    public function storeOrganization(
        Request $request,
        CurrentAccountContract $currentAccount,
        RelationshipIntelligenceService $relationships,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Organization::class);

        $organization = $relationships->createOrganization($account, $this->validatedOrganization($request));

        return redirect()->route('app.relationships.organizations.show', $organization)->with('status', 'Organization created.');
    }

    public function storeRelationship(
        Request $request,
        CurrentAccountContract $currentAccount,
        RelationshipIntelligenceService $relationships,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Relationship::class);

        $attributes = $request->validate([
            'from_type' => ['required', 'string', Rule::in(['contact', 'organization', Contact::class, Organization::class])],
            'from_id' => ['required', 'integer'],
            'to_type' => ['required', 'string', Rule::in(['contact', 'organization', Contact::class, Organization::class])],
            'to_id' => ['required', 'integer'],
            'relationship_type' => ['required', 'string', Rule::in(Relationship::TYPES)],
            'strength' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        try {
            $relationships->createRelationship($account, $attributes);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['relationship' => $exception->getMessage()]);
        }

        return redirect()->route('app.relationships')->with('status', 'Relationship created.');
    }

    public function showContact(
        Contact $contact,
        Request $request,
        CurrentAccountContract $currentAccount,
        RelationshipIntelligenceService $relationships,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $contact);

        return view('app.relationships.contacts.show', [
            'contact' => $relationships->findContact($account, $contact->id),
        ]);
    }

    public function showOrganization(
        Organization $organization,
        Request $request,
        CurrentAccountContract $currentAccount,
        RelationshipIntelligenceService $relationships,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $organization);

        return view('app.relationships.organizations.show', [
            'organization' => $relationships->findOrganization($account, $organization->id),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedContact(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:2048'],
            'linkedin_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedOrganization(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:2048'],
            'industry' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
