<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AccessOverrides\AccessOverrideResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivateUserRequest;
use App\Http\Requests\Admin\ApproveUserRequest;
use App\Http\Requests\Admin\DisableUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AccessOverride;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminUsersController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'organization_id' => (string) $request->query('organization_id', ''),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', 'newest'),
        ];

        $query = User::query()
            ->with(['organization', 'latestAccessOverride']);

        if (! $request->user()?->isSuperadmin()) {
            $query->where('is_admin', false);
        }

        if ($filters['q'] !== '') {
            $query->where(function ($builder) use ($filters): void {
                $builder
                    ->where('name', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('email', 'like', '%' . $filters['q'] . '%');
            });
        }

        if ($filters['organization_id'] !== '' && ctype_digit($filters['organization_id'])) {
            $query->where('organization_id', (int) $filters['organization_id']);
        }

        if ($filters['status'] === 'active') {
            $query->whereNotNull('approved_at')->where('active', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('active', false);
        } elseif ($filters['status'] === 'pending') {
            $query->whereNull('approved_at');
        }

        if ($filters['sort'] === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
            $filters['sort'] = 'newest';
        }

        $users = $query
            ->paginate(25)
            ->withQueryString();

        $organizations = Cache::remember(
            'admin.users.organizations.options',
            now()->addMinutes(10),
            fn () => Organization::query()->orderBy('name')->get(['id', 'name'])
        );

        return view('admin.users.index', [
            'users' => $users,
            'organizations' => $organizations,
            'filters' => $filters,
        ]);
    }

    public function show(User $user, AccessOverrideResolver $resolver): View
    {
        if ($user->is_admin && ! request()->user()?->isSuperadmin()) {
            abort(404);
        }

        $user->load([
            'organization.workspaces.clientSites',
            'latestAccessOverride',
            'accessOverrides' => fn ($query) => $query
                ->with(['createdBy', 'endedBy'])
                ->latest('created_at'),
        ]);

        /** @var AccessOverride|null $activeAccessOverride */
        $activeAccessOverride = $resolver->getActiveOverrideForUser($user);
        $openAccessOverride = $resolver->getOpenOverrideForUser($user);

        return view('admin.users.show', [
            'managedUser' => $user,
            'activeAccessOverride' => $activeAccessOverride,
            'openAccessOverride' => $openAccessOverride,
        ]);
    }

    public function approve(ApproveUserRequest $request, User $user): RedirectResponse
    {
        $user->update([
            'approved_at' => now(),
            'active' => true,
        ]);

        return redirect()->to(url()->previous())->with('status', 'User approved.');
    }

    public function disable(DisableUserRequest $request, User $user): RedirectResponse
    {
        $user->update([
            'active' => false,
        ]);

        return redirect()->to(url()->previous())->with('status', 'User disabled.');
    }

    public function activate(ActivateUserRequest $request, User $user): RedirectResponse
    {
        $user->update([
            'active' => true,
            'approved_at' => $user->approved_at ?? now(),
        ]);

        return redirect()->to(url()->previous())->with('status', 'User activated.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->is_admin) {
            abort(403);
        }

        $data = $request->validated();

        DB::transaction(function () use ($user, $data): void {
            $organizationId = $data['organization_id'] ?? null;
            $role = $data['role'];

            if ($role === 'owner' && $organizationId) {
                User::query()
                    ->where('organization_id', $organizationId)
                    ->where('id', '!=', $user->id)
                    ->where('role', 'owner')
                    ->update(['role' => 'admin']);
            }

            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'organization_id' => $organizationId,
                'role' => $role,
                'active' => (bool) ($data['active'] ?? false),
                'approved_at' => ! empty($data['active']) ? ($user->approved_at ?? now()) : $user->approved_at,
            ]);
        });

        return redirect()->to(url()->previous())->with('status', 'User updated.');
    }

    public function setRole(Request $request, User $user, AuditLogService $auditLogs): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $data = $request->validate([
            'admin_role' => ['required', 'in:user,admin,superadmin'],
        ]);

        $before = [
            'is_admin' => (bool) $user->is_admin,
            'admin_role' => (string) ($user->admin_role ?? ''),
        ];

        $nextRole = (string) $data['admin_role'];
        $user->admin_role = $nextRole;
        $user->is_admin = in_array($nextRole, ['admin', 'superadmin'], true);
        $user->save();

        $after = [
            'is_admin' => (bool) $user->is_admin,
            'admin_role' => (string) $user->admin_role,
        ];

        $auditLogs->log(
            actor: $request->user(),
            subject: $user,
            action: 'admin.user.role.updated',
            before: $before,
            after: $after,
            request: $request
        );

        return redirect()->to(url()->previous())->with('status', 'Admin role updated.');
    }
}
