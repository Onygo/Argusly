<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreTeamMemberRequest;
use App\Http\Requests\App\UpdateTeamMemberRequest;
use App\Models\TeamMember;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppTeamMembersController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = (int) $request->user()->organization_id;

        if (! $organizationId) {
            abort(403);
        }

        $teamMembers = TeamMember::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        $workspace = $this->resolveWorkspace($request);

        return view('app.brand.team-members', [
            'teamMembers' => $teamMembers,
            'workspace' => $workspace,
            'organization' => $request->user()->organization,
            'latestBrandContext' => $workspace?->brandContexts()->latest()->first(),
        ]);
    }

    public function store(StoreTeamMemberRequest $request): RedirectResponse
    {
        $this->ensureManager();

        $organizationId = (int) $request->user()->organization_id;

        if (! $organizationId) {
            return back()->withErrors(['team_members' => 'No organization found.']);
        }

        $data = $request->validated();
        $profileData = [
            'use_as_writing_persona' => (bool) ($data['use_as_writing_persona'] ?? false),
            'link_to_real_team_member_later' => (bool) ($data['link_to_real_team_member_later'] ?? false),
        ];

        TeamMember::query()->create([
            'organization_id' => $organizationId,
            'name' => (string) $data['name'],
            'title' => (string) ($data['title'] ?? $data['role'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'public_profile_url' => (string) ($data['public_profile_url'] ?? ''),
            'bio_source_text' => (string) ($data['bio_source_text'] ?? ''),
            'source_payload' => [
                'source_type' => 'manual',
            ],
            'profile_data' => $profileData,
            'status' => TeamMember::STATUS_APPROVED,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'role' => (string) ($data['title'] ?? $data['role'] ?? ''),
            'expertise' => (string) ($data['expertise'] ?? ''),
            'writing_perspective' => (string) ($data['writing_perspective'] ?? ''),
            'personality_traits' => (string) ($data['personality_traits'] ?? ''),
            'is_active' => true,
        ]);

        return back()->with('status', 'Team member persona created.');
    }

    public function update(UpdateTeamMemberRequest $request, TeamMember $teamMember): RedirectResponse
    {
        $this->ensureManager();
        $this->ensureOwnership($request, $teamMember);

        $data = $request->validated();
        $profileData = array_merge(
            is_array($teamMember->profile_data) ? $teamMember->profile_data : [],
            [
                'use_as_writing_persona' => (bool) ($data['use_as_writing_persona'] ?? false),
                'link_to_real_team_member_later' => (bool) ($data['link_to_real_team_member_later'] ?? false),
            ]
        );

        $teamMember->update([
            'name' => (string) $data['name'],
            'title' => (string) ($data['title'] ?? $data['role'] ?? $teamMember->title ?? $teamMember->role),
            'email' => (string) ($data['email'] ?? $teamMember->email),
            'public_profile_url' => (string) ($data['public_profile_url'] ?? $teamMember->public_profile_url),
            'bio_source_text' => (string) ($data['bio_source_text'] ?? $teamMember->bio_source_text),
            'profile_data' => $profileData,
            'updated_by' => $request->user()->id,
            'role' => (string) ($data['title'] ?? $data['role'] ?? $teamMember->title ?? $teamMember->role),
            'expertise' => (string) ($data['expertise'] ?? $teamMember->expertise),
            'writing_perspective' => (string) ($data['writing_perspective'] ?? $teamMember->writing_perspective),
            'personality_traits' => (string) ($data['personality_traits'] ?? $teamMember->personality_traits),
        ]);

        return back()->with('status', 'Team member persona updated.');
    }

    public function toggleActive(Request $request, TeamMember $teamMember): RedirectResponse
    {
        $this->ensureManager();
        $this->ensureOwnership($request, $teamMember);

        $teamMember->update([
            'is_active' => ! $teamMember->is_active,
            'updated_by' => $request->user()->id,
        ]);

        $status = $teamMember->is_active
            ? 'Team member persona activated.'
            : 'Team member persona deactivated.';

        return back()->with('status', $status);
    }

    public function destroy(Request $request, TeamMember $teamMember): RedirectResponse
    {
        $this->ensureManager();
        $this->ensureOwnership($request, $teamMember);

        // Soft-delete by deactivating instead of hard delete to preserve history
        $teamMember->update([
            'is_active' => false,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Team member persona deactivated. Existing content associations are preserved.');
    }

    private function ensureManager(): void
    {
        Gate::authorize('manage-organization');
    }

    private function ensureOwnership(Request $request, TeamMember $teamMember): void
    {
        $organizationId = (int) $request->user()->organization_id;

        if ((int) $teamMember->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $organizationId = (int) $request->user()->organization_id;

        if (! $organizationId) {
            return null;
        }

        $impersonatedWorkspaceId = (string) $request->session()->get('impersonated_workspace_id', '');
        if ($impersonatedWorkspaceId !== '') {
            $workspace = Workspace::query()
                ->where('organization_id', $organizationId)
                ->whereKey($impersonatedWorkspaceId)
                ->first();

            if ($workspace) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->first();
    }
}
