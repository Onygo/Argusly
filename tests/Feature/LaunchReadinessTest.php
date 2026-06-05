<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_public_and_admin_routes_are_rate_limited(): void
    {
        $this->assertRouteHasMiddleware('login.store', 'throttle:auth-actions');
        $this->assertRouteHasMiddleware('password.email', 'throttle:auth-actions');
        $this->assertRouteHasMiddleware('password.update', 'throttle:auth-actions');
        $this->assertRouteHasMiddleware('marketing.signup.store', 'throttle:marketing-forms');
        $this->assertRouteHasMiddleware('marketing.contact.store', 'throttle:marketing-forms');
        $this->assertRouteHasMiddleware('admin.billing', 'throttle:admin-actions');
        $this->assertRouteHasMiddleware('tenant.account.switch', 'throttle:tenant-switch');
        $this->assertRouteHasMiddleware('tenant.brand.switch', 'throttle:tenant-switch');
        $this->assertRouteHasMiddleware('app.visibility.checks.store', 'throttle:ai-actions');
        $this->assertRouteHasMiddleware('app.visibility.prompts.run', 'throttle:ai-actions');
    }

    private function assertRouteHasMiddleware(string $routeName, string $middleware): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route [{$routeName}] does not exist.");
        $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$routeName}] is missing [{$middleware}].");
    }
}
