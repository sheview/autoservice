<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\RecordVisibleBranches;

beforeEach(function () {
    $this->tenantA = createTenant('alpha');
    $this->tenantB = createTenant('bravo');

    asTenant($this->tenantA, fn () => Branch::create(['code' => 'A1', 'name' => 'Alpha HQ']));
    asTenant($this->tenantB, fn () => Branch::create(['code' => 'B1', 'name' => 'Bravo HQ']));

    Route::middleware('web')->get('/_test/branches', fn () => [
        'eloquent' => Branch::orderBy('name')->pluck('name'),
        'raw' => DB::table('branches')->orderBy('name')->pluck('name'),
    ]);

    RecordVisibleBranches::$seen = null;
});

// --- (a) Eloquent ------------------------------------------------------------

it('(a) shows a user of tenant A only the branches of tenant A', function () {
    $user = asTenant($this->tenantA, fn () => User::factory()->create());

    $response = $this->actingAs($user)->get('/_test/branches')->assertOk();

    expect($response->json('eloquent'))->toBe(['Alpha HQ']);
    expect(asTenant($this->tenantA, fn () => Branch::all()->pluck('name')->all()))->toBe(['Alpha HQ']);
});

// --- (b) RLS -----------------------------------------------------------------

it('(b) limits raw queries that bypass Eloquent to the current tenant', function () {
    asTenant($this->tenantA, function () {
        expect(DB::table('branches')->pluck('name')->all())->toBe(['Alpha HQ'])
            ->and(DB::select('select name from branches'))->toHaveCount(1);
    });

    $user = asTenant($this->tenantA, fn () => User::factory()->create());
    expect($this->actingAs($user)->get('/_test/branches')->json('raw'))->toBe(['Alpha HQ']);
});

it('shows no tenant rows at all when no tenant is set', function () {
    app(TenantContext::class)->forget();

    expect(DB::table('branches')->count())->toBe(0)
        ->and(DB::selectOne('select count(*) as c from branches')->c)->toBe(0)
        ->and(Branch::count())->toBe(0);
});

it('rejects writing a row into another tenant', function () {
    app(TenantContext::class)->set($this->tenantA);

    DB::table('branches')->insert([
        'tenant_id' => $this->tenantB->id, 'code' => 'X', 'name' => 'Sneaky',
        'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'row-level security');

it('connects as a role that row level security applies to', function () {
    $role = DB::selectOne('select rolname, rolsuper, rolbypassrls from pg_roles where rolname = current_user');
    $owner = DB::selectOne("select tableowner from pg_tables where tablename = 'branches'")->tableowner;

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($owner)->not->toBe($role->rolname);
});

// --- (c) Queue ---------------------------------------------------------------

it('(c) runs a queued job with the tenant it was dispatched from', function () {
    config(['queue.default' => 'database']);

    asTenant($this->tenantA, function () {
        RecordVisibleBranches::dispatch();
    });

    // A worker starts without any tenant.
    app(TenantContext::class)->forget();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(RecordVisibleBranches::$seen)->toBe([
        'tenant' => $this->tenantA->id,
        'eloquent' => ['Alpha HQ'],
        'raw' => ['Alpha HQ'],
    ]);
    // ...and goes back to no tenant after the job.
    expect(app(TenantContext::class)->id())->toBeNull()
        ->and(DB::table('branches')->count())->toBe(0);
});

it('keeps the caller tenant after a sync job of another tenant', function () {
    config(['queue.default' => 'sync']);
    app(TenantContext::class)->set($this->tenantB);

    asTenant($this->tenantA, function () {
        RecordVisibleBranches::dispatch();
    });

    expect(RecordVisibleBranches::$seen['raw'])->toBe(['Alpha HQ'])
        ->and(app(TenantContext::class)->id())->toBe($this->tenantB->id);
});

// --- (d) creating --------------------------------------------------------------

it('(d) fills tenant_id from the current tenant when creating', function () {
    $branch = asTenant($this->tenantB, fn () => Branch::create(['code' => 'B2', 'name' => 'Bravo 2']));

    expect($branch->tenant_id)->toBe($this->tenantB->id);
});

it('refuses to create a tenant model without a tenant', function () {
    app(TenantContext::class)->forget();

    Branch::create(['code' => 'X', 'name' => 'Nowhere']);
})->throws(LogicException::class);

// --- ResolveTenant -------------------------------------------------------------

it('resolves the tenant from the subdomain', function () {
    expect($this->get('http://bravo.localhost/_test/branches')->assertOk()->json('raw'))->toBe(['Bravo HQ']);
});

it('returns 404 for an unknown subdomain', function () {
    $this->get('http://nobody.localhost/_test/branches')->assertNotFound();
});

it('forbids a user of tenant A on the subdomain of tenant B', function () {
    $user = asTenant($this->tenantA, fn () => User::factory()->create());

    $this->actingAs($user)->get('http://bravo.localhost/_test/branches')->assertForbidden();
});

it('forbids a suspended tenant', function () {
    $this->tenantB->update(['status' => Tenant::STATUS_SUSPENDED]);

    $this->get('http://bravo.localhost/_test/branches')->assertForbidden();
});

it('shows a guest on the central domain no tenant rows', function () {
    expect($this->get('/_test/branches')->assertOk()->json())->toBe(['eloquent' => [], 'raw' => []]);
});
