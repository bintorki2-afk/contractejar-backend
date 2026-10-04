<?php

namespace Tests\Unit\Users;

use App\Modules\Users\Models\User;
use App\Modules\Users\Policies\UserPolicy;
use App\Modules\Users\Resources\UserResource;
use App\Shared\Routing\ModuleRouteLoader;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UsersAliasTest extends TestCase
{
    public function test_legacy_user_and_resource_names_alias_the_module_classes(): void
    {
        $this->assertTrue(is_a(\App\Models\User::class, User::class, true));
        $this->assertTrue(is_a(\App\Http\Resources\UserResource::class, UserResource::class, true));
        $this->assertTrue(is_a(
            \App\Http\Resources\Admin\V2\Api\AllUserResource::class,
            \App\Modules\Users\Resources\Admin\AllUserResource::class,
            true
        ));
    }

    public function test_user_policy_is_registered(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }

    public function test_users_module_exposes_route_files(): void
    {
        $loader = $this->app->make(ModuleRouteLoader::class);
        $users = collect($loader->moduleDirectories())
            ->first(fn (string $path): bool => basename($path) === 'Users');

        $this->assertNotNull($users);
        $this->assertFileExists($users.DIRECTORY_SEPARATOR.'Routes'.DIRECTORY_SEPARATOR.'api.php');
        $this->assertFileExists($users.DIRECTORY_SEPARATOR.'Routes'.DIRECTORY_SEPARATOR.'api_v2.php');
        $this->assertFileExists($users.DIRECTORY_SEPARATOR.'Routes'.DIRECTORY_SEPARATOR.'admin.php');
        // web.php is optional in the module route loader (prefix: null, 'web'
        // middleware) and no module ships one; only assert the files that exist.
    }
}
