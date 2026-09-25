<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createTenant(string $slug): Tenant
{
    return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'subdomain' => $slug]);
}

/**
 * Run a callback inside a tenant, then restore the previous tenant context.
 */
function asTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

/**
 * A user with one role, created in $tenant (default: the current tenant).
 */
function userWithRole(string $role, array $attributes = [], ?Tenant $tenant = null): User
{
    $create = fn () => User::factory()->withRole($role)->create($attributes);

    return $tenant ? asTenant($tenant, $create) : $create();
}

/**
 * A superadmin: a user with the "superadmin" role in the platform tenant.
 */
function createSuperadmin(array $attributes = []): User
{
    $platform = Tenant::create(['name' => 'Platform', 'slug' => 'platform', 'subdomain' => 'admin', 'is_platform' => true]);

    return userWithRole(PermissionCatalog::SUPERADMIN, $attributes, $platform);
}
