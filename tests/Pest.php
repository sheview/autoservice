<?php

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Contract\Actions\SaveContract;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Service\Actions\OpenTicket;
use App\Modules\Service\Models\Ticket;
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
 * Lets a role of the current tenant see every branch (branch.all), as a company does for its
 * head-office dispatchers. By default only the company admin has it.
 */
function allowAllBranches(string $role): void
{
    Role::findByName($role)->givePermissionTo(PermissionCatalog::ALL_BRANCHES);
}

/**
 * A superadmin: a user with the "superadmin" role in the platform tenant.
 */
function createSuperadmin(array $attributes = []): User
{
    $platform = Tenant::create(['name' => 'Platform', 'slug' => 'platform', 'subdomain' => 'admin', 'is_platform' => true]);

    return userWithRole(PermissionCatalog::SUPERADMIN, $attributes, $platform);
}

/**
 * An asset category in the current tenant.
 */
function createAssetCategory(array $attributes = []): AssetCategory
{
    static $n = 0;

    return AssetCategory::create($attributes + ['name' => 'Category '.++$n, 'code_prefix' => 'PC']);
}

/**
 * An asset in the current tenant, saved through SaveAsset (so it gets a generated code).
 */
function createAsset(AssetCategory $category, array $attributes = []): Asset
{
    return app(SaveAsset::class)->handle(null, $attributes + [
        'category_id' => $category->id,
        'name' => 'Asset',
        'status' => Asset::STATUS_IN_USE,
    ]);
}

/**
 * A customer (Contract module) in the current tenant.
 */
function createCustomer(array $attributes = []): Customer
{
    static $n = 0;
    $n++;

    return Customer::create($attributes + ['code' => "C{$n}", 'name' => "Customer {$n}"]);
}

/**
 * An active contract that runs from a month ago to 11 months from now.
 */
function createContract(Customer $customer, array $attributes = []): Contract
{
    static $n = 0;
    $n++;

    return app(SaveContract::class)->handle(null, $attributes + [
        'customer_id' => $customer->id,
        'contract_no' => "MA-{$n}",
        'title' => "Contract {$n}",
        'status' => Contract::STATUS_ACTIVE,
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonths(11)->toDateString(),
        'service_window' => '8x5',
        'notify_days_before' => 60,
    ]);
}

/**
 * A part (Inventory module) in the current tenant, with $stock pieces received into stock.
 */
function createPart(array $attributes = [], int $stock = 0): Part
{
    static $n = 0;
    $n++;

    $part = app(SavePart::class)->handle(null, $attributes + ['code' => "P{$n}", 'name' => "Part {$n}", 'unit' => 'pcs']);
    if ($stock > 0) {
        app(RecordStockMovement::class)->handle($part, StockMovement::TYPE_RECEIVE, $stock, null);
    }

    return $part;
}

/**
 * A ticket opened by $actor through OpenTicket (number, SLA and due times as in the app).
 */
function openTicket(User $actor, array $attributes = []): Ticket
{
    return app(OpenTicket::class)->handle($actor, $attributes + [
        'title' => 'Printer does not print',
        'priority' => 'medium',
        'source' => 'phone',
    ]);
}
