<?php

namespace Tests\Feature\Admin;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class AdminRouteAuthorizationTest extends TestCase
{
    private const PUBLIC_ROUTES = [
        'POST api/admin/employees/login',
        'POST api/admin/employees/refresh-token',
        'POST api/admin/payment-gateway/status/{uuid}/success',
        'POST api/admin/payment-gateway/status/{uuid}',
        'GET api/admin/payment-gateway/status/success/{uuid}',
        'GET api/admin/payment-gateway/status/error/{uuid}',
        'GET api/admin/seo-google/callback',
    ];

    private const AUTH_ONLY_ROUTES = [
        'POST api/admin/employees/fcm',
        'POST api/admin/employees/logout',
        'GET api/admin/employees/me',
        'GET api/admin/employees/profile',
        'GET api/admin/employees/me/kpis',
    ];

    public function test_only_the_explicit_public_allowlist_omits_sanctum_authentication(): void
    {
        $publicRoutes = [];

        foreach ($this->adminRoutes() as $route) {
            $key = $this->routeKey($route);
            $middleware = $route->gatherMiddleware();

            if (in_array($key, self::PUBLIC_ROUTES, true)) {
                $publicRoutes[] = $key;
                $this->assertNotContains('auth:sanctum', $middleware, $key);
            } else {
                $this->assertContains('auth:sanctum', $middleware, $key);
            }
        }

        sort($publicRoutes);
        $expected = self::PUBLIC_ROUTES;
        sort($expected);

        $this->assertSame($expected, $publicRoutes);
    }

    public function test_every_non_public_route_except_self_service_routes_has_a_module_permission(): void
    {
        foreach ($this->adminRoutes() as $route) {
            $key = $this->routeKey($route);

            if (in_array($key, [...self::PUBLIC_ROUTES, ...self::AUTH_ONLY_ROUTES], true)) {
                continue;
            }

            $this->assertNotEmpty(
                $this->permissionMiddleware($route),
                "{$key} must declare a permission middleware."
            );
        }

        foreach (self::AUTH_ONLY_ROUTES as $key) {
            $this->assertSame([], $this->permissionMiddleware($this->routeByKey($key)), $key);
        }
    }

    public function test_sensitive_and_shared_workflows_use_the_curated_permissions(): void
    {
        $expected = [
            'GET api/admin/employees/employee-salary' => 'permission:employee_salaries.view',
            'POST api/admin/employees/{id}/salary' => 'permission:employee_salaries.create',
            'GET api/admin/orders/{id}' => 'permission:all_requests.view',
            'POST api/admin/orders/{id}/status' => 'permission:all_requests.edit',
            'POST api/admin/orders/{id}/return-contract-status' => 'permission:returned_request.retrieve',
            'POST api/admin/analytics/refunds/contracts/confirm' => 'permission:returned_request.retrieve',
            'GET api/admin/contract-whatsapp' => 'permission:contract_whatsapp.view',
            'POST api/admin/contract-whatsapp/complete' => 'permission:completed_whatsapp_request.create',
            'POST api/admin/contract-whatsapp/incomplete' => 'permission:incomplete_whatsapp_request.create',
            'GET api/admin/operating-expenses' => 'permission:operating_expenses.view',
            'POST api/admin/operating-expenses' => 'permission:operating_expenses.create',
            'GET api/admin/unit-types' => 'permission:property_reference.view',
            'POST api/admin/real-estate-types' => 'permission:property_reference.create',
        ];

        foreach ($expected as $key => $permission) {
            $this->assertContains($permission, $this->routeByKey($key)->gatherMiddleware(), $key);
        }
    }

    public function test_tenant_role_routes_are_registered_once(): void
    {
        $tenantRoleRoutes = array_filter(
            $this->adminRoutes(),
            fn (Route $route): bool => str_starts_with($route->uri(), 'api/admin/tenant-roles')
        );

        $this->assertCount(5, $tenantRoleRoutes);
    }

    /**
     * @return list<Route>
     */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route): bool => str_starts_with($route->uri(), 'api/admin/')
        ));
    }

    private function routeByKey(string $key): Route
    {
        foreach ($this->adminRoutes() as $route) {
            if ($this->routeKey($route) === $key) {
                return $route;
            }
        }

        $this->fail("Admin route {$key} is not registered.");
    }

    private function routeKey(Route $route): string
    {
        $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

        return "{$method} {$route->uri()}";
    }

    /**
     * @return list<string>
     */
    private function permissionMiddleware(Route $route): array
    {
        return array_values(array_filter(
            $route->gatherMiddleware(),
            fn (string $middleware): bool => str_starts_with($middleware, 'permission:')
        ));
    }
}
