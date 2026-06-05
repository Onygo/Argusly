<?php

namespace App\Http\Middleware;

use App\Services\Support\SupportContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupportModeContext
{
    public function handle(Request $request, Closure $next, string $scope = 'any'): Response
    {
        $context = app(SupportContext::class);
        $context->hydrateFromRequest($request);

        if (! $context->isEnabled()) {
            return $next($request);
        }

        $actor = $request->user();
        $targetCompany = $context->targetCompany();
        $targetUser = $context->targetUser();

        $isValid = $actor
            && $actor->isSuperadmin()
            && $targetCompany
            && $targetUser
            && (int) $targetUser->organization_id === (int) $targetCompany->id;

        if (! $isValid) {
            $context->clear($request);
            abort(403, 'Support mode context is invalid.');
        }

        $request->attributes->set('support_mode_enabled', true);
        $request->attributes->set('support_target_company', $targetCompany);
        $request->attributes->set('support_target_user', $targetUser);

        if ($scope === 'app') {
            // Preserve actor identity while applying target visibility scope in app reads.
            $actor->setAttribute('support_actor_id', $actor->getKey());
            $actor->setAttribute('support_mode', true);
            $actor->setAttribute('support_target_user_id', $targetUser->getKey());
            $actor->organization_id = $targetCompany->id;
            $actor->role = (string) ($targetUser->role ?? 'member');
            $actor->is_admin = false;
        }

        return $next($request);
    }
}

