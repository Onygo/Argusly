<?php

namespace App\Http\Middleware;

use App\Services\Support\SupportContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyWriteActionsInSupportMode
{
    /** @var array<int,string> */
    private array $allowedMutationRoutes = [
        'admin.support.stop',
    ];

    /** @var array<int,string> */
    private array $deniedReadRoutePrefixes = [
        'app.billing',
        'admin.billing',
        'admin.invoices',
        'admin.organizations.billing',
        'admin.llm.settings',
        'admin.users.role.update',
        'admin.users.update',
        'app.content.publish-now',
        'app.content.republish',
        'app.drafts.republish',
        'app.sites.regenerate-key',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(SupportContext::class);
        if (! $context->isEnabled()) {
            return $next($request);
        }

        $routeName = (string) optional($request->route())->getName();
        $method = strtoupper($request->method());

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && ! in_array($routeName, $this->allowedMutationRoutes, true)) {
            abort(403, 'Write actions are blocked in support mode.');
        }

        foreach ($this->deniedReadRoutePrefixes as $prefix) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                abort(403, 'This route is not available in support mode.');
            }
        }

        return $next($request);
    }
}
