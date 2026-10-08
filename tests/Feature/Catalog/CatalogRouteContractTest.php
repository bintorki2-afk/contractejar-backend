<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Controllers\Admin\CityController;
use App\Modules\Catalog\Controllers\Api\CatalogLookupController;
use App\Modules\Catalog\Controllers\Api\TenantRoleController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogRouteContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function catalogGetUris(): array
    {
        return [
            'cities',
            'regions',
            'bank-accounts',
            'services-pricing',
            'paperwork',
            'real-estat-type',
            'real-estat-usage',
            'units-types',
            'units-usage',
            'payments-types',
            'contract-periods',
        ];
    }

    public function test_public_catalog_lookups_are_registered_once_on_v1_and_v2(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        foreach ($this->catalogGetUris() as $path) {
            $this->assertSame(1, $uris->filter(fn ($uri) => $uri === "api/{$path}")->count(), "api/{$path}");
            $this->assertSame(1, $uris->filter(fn ($uri) => $uri === "api/v2/{$path}")->count(), "api/v2/{$path}");
        }
    }

    public function test_public_catalog_lookups_use_the_module_controller(): void
    {
        foreach (['api/cities', 'api/v2/cities', 'api/regions', 'api/v2/paperwork'] as $uri) {
            $route = $this->routeByUriAndMethod($uri, 'GET');
            $this->assertSame(CatalogLookupController::class, $route->getControllerClass(), $uri);
        }
    }

    public function test_v2_tenant_roles_are_read_only_on_the_public_api(): void
    {
        // الواجهة العامة للقراءة فقط: الكتابة/التعديل/الحذف في لوحة الإدارة فقط.
        // كانت المسارات العامة للكتابة تشير لدوال غير موجودة وتُرجع 500 (APP-2) فحُذفت.
        $tenantRoleRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v2/tenant-roles'))
            ->values();

        $this->assertCount(1, $tenantRoleRoutes);

        $index = $this->routeByUriAndMethod('api/v2/tenant-roles', 'GET');
        $this->assertSame(TenantRoleController::class, $index->getControllerClass());
        $this->assertSame('index', $index->getActionMethod());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertNull(
                $this->routeByUriAndMethodOrNull('api/v2/tenant-roles', $method),
                "api/v2/tenant-roles must not expose {$method}"
            );
            $this->assertNull(
                $this->routeByUriAndMethodOrNull('api/v2/tenant-roles/{id}', $method),
                "api/v2/tenant-roles/{id} must not expose {$method}"
            );
        }
    }

    public function test_admin_catalog_routes_are_registered_once_with_sanctum_and_permissions(): void
    {
        $expected = [
            'GET api/admin/cities' => ['auth:sanctum', 'permission:cities.view'],
            'POST api/admin/cities' => ['auth:sanctum', 'permission:cities.create'],
            'GET api/admin/regions' => ['auth:sanctum', 'permission:regions.view'],
            'GET api/admin/paperworks' => ['auth:sanctum', 'permission:paperworks.view'],
            'POST api/admin/paperworks' => ['auth:sanctum', 'permission:paperworks.create'],
            'GET api/admin/payment-types' => ['auth:sanctum', 'permission:app_content.view'],
            'GET api/admin/tenant-roles' => ['auth:sanctum', 'permission:tenant_roles.view'],
            'POST api/admin/contract-periods/create' => ['auth:sanctum', 'permission:contract_periods.create'],
            'GET api/admin/unit-types/search' => ['auth:sanctum', 'permission:property_reference.view'],
            'GET api/admin/unit-types/create' => ['auth:sanctum', 'permission:property_reference.view'],
            'GET api/admin/unit-usages/create' => ['auth:sanctum', 'permission:property_reference.view'],
            'GET api/admin/real-estate-types' => ['auth:sanctum', 'permission:property_reference.view'],
            'GET api/admin/real-estate-usages' => ['auth:sanctum', 'permission:property_reference.view'],
        ];

        $adminUris = collect(Route::getRoutes())->map(fn ($route) => $this->routeKey($route));

        foreach ($expected as $key => $middleware) {
            $this->assertSame(1, $adminUris->filter(fn ($k) => $k === $key)->count(), $key);
            $route = $this->routeByKey($key);
            foreach ($middleware as $item) {
                $this->assertContains($item, $route->gatherMiddleware(), $key);
            }
        }

        $this->assertSame(
            CityController::class,
            $this->routeByKey('GET api/admin/cities')->getControllerClass()
        );
    }

    public function test_website_city_helpers_are_not_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertNotContains('get-cities', $uris);
        $this->assertNotContains('get-cities-tenant', $uris);
        $this->assertFalse(Route::has('website.home'));
        $this->assertFalse(Route::has('website.login'));
    }

    public function test_unauthenticated_admin_catalog_index_is_rejected(): void
    {
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');

        $this->getJson('/api/admin/cities')->assertStatus(401);
        $this->getJson('/api/cities')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    private function routeByUriAndMethod(string $uri, string $method)
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        $this->fail("Route {$method} {$uri} is not registered.");
    }

    private function routeByUriAndMethodOrNull(string $uri, string $method)
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    private function routeByKey(string $key)
    {
        foreach (Route::getRoutes() as $route) {
            if ($this->routeKey($route) === $key) {
                return $route;
            }
        }

        $this->fail("Route {$key} is not registered.");
    }

    private function routeKey($route): string
    {
        $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

        return "{$method} {$route->uri()}";
    }
}
