<?php

namespace App\Http\Middleware;

use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeature
{
    public function __construct(private readonly FeatureGate $gate)
    {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            abort(403, 'Workspace context required.');
        }

        try {
            $this->gate->assert($workspace, $featureKey);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        return $next($request);
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $routeWorkspace = $request->route('workspace');
        if ($routeWorkspace instanceof Workspace) {
            return $routeWorkspace;
        }

        if (is_string($routeWorkspace) && $routeWorkspace !== '') {
            $found = Workspace::query()->find($routeWorkspace);
            if ($found) {
                return $found;
            }
        }

        $content = $request->route('content');
        $contentWorkspaceId = data_get($content, 'workspace_id');
        if (is_string($contentWorkspaceId) && $contentWorkspaceId !== '') {
            return Workspace::query()->find($contentWorkspaceId);
        }

        $draft = $request->route('draft');
        if ($draft instanceof Draft && $draft->brief?->workspace_id) {
            return Workspace::query()->find($draft->brief->workspace_id);
        }

        $organization = $request->user()?->organization;
        if ($organization) {
            return Workspace::query()->where('organization_id', $organization->id)->orderBy('created_at')->first();
        }

        return null;
    }
}
