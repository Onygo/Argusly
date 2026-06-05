<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AccessOverrides\AccessOverrideManager;
use App\Domain\AccessOverrides\AccessOverrideResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAccessOverrideRequest;
use App\Models\AccessOverride;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminUserAccessOverrideController extends Controller
{
    public function store(
        StoreAccessOverrideRequest $request,
        User $user,
        AccessOverrideManager $manager,
    ): RedirectResponse {
        if ($user->is_admin && ! $request->user()?->isSuperadmin()) {
            abort(403);
        }

        try {
            $manager->createForUser(
                targetUser: $user,
                payload: $request->validated(),
                actor: $request->user(),
                request: $request,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'Access override created.');
    }

    public function extend(
        StoreAccessOverrideRequest $request,
        User $user,
        AccessOverride $accessOverride,
        AccessOverrideManager $manager,
    ): RedirectResponse {
        if ($user->is_admin && ! $request->user()?->isSuperadmin()) {
            abort(403);
        }

        if ((int) $accessOverride->user_id !== (int) $user->id) {
            abort(404);
        }

        $manager->extendForUser(
            targetUser: $user,
            currentOverride: $accessOverride,
            payload: $request->validated(),
            actor: $request->user(),
            request: $request,
        );

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'Access override extended.');
    }

    public function stop(
        User $user,
        AccessOverride $accessOverride,
        AccessOverrideManager $manager,
        AccessOverrideResolver $resolver,
    ): RedirectResponse {
        if ($user->is_admin && ! request()->user()?->isSuperadmin()) {
            abort(403);
        }

        if ((int) $accessOverride->user_id !== (int) $user->id) {
            abort(404);
        }

        if (! $resolver->effectiveStatus($accessOverride)->isOpen()) {
            return redirect()
                ->route('admin.users.show', $user)
                ->withErrors(['access_override' => 'This override is already closed.']);
        }

        $manager->cancel($accessOverride, actor: request()->user(), request: request());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'Access override stopped.');
    }
}
