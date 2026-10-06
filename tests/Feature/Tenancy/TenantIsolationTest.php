<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\Fixtures\RecordVisibleBranches;

beforeEach(function () {
    $this->tenantA = createTenant('alpha');
    $this->tenantB = createTenant('bravo');

    asTenant($this->tenantA, fn () => Branch::create(['code' => 'A1', 'name' => 'Alpha HQ']));
    asTenant($this->tenantB, fn () => Branch::create(['code' => 'B1', 'name' => 'Bravo HQ']));

    Route::middleware('web')->get('/_test/branches', fn () => [
        'eloquent' => Branch::orderBy('name')->pluck('name'),
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

// --- (b) Without row level security ---------------------------------------------
// MariaDB has none: the TenantScope of every model, the tenant-aware "exists"/"unique" rules and
// BelongsToTenant's write checks keep tenants apart. A raw query sees every tenant, so it must
// always carry the tenant condition itself.

it('(b) lets raw queries see every tenant, so they must carry the tenant condition', function () {
    asTenant($this->tenantA, function () {
        expect(DB::table('branches')->count())->toBe(2)
            ->and(DB::table('branches')->where('tenant_id', $this->tenantA->id)->pluck('name')->all())->toBe(['Alpha HQ']);
    });
});

it('shows no tenant rows at all through models when no tenant is set', function () {
    app(TenantContext::class)->forget();

    expect(Branch::count())->toBe(0);
});

it('keeps "exists" and "unique" validation to the current tenant', function () {
    $bravo = asTenant($this->tenantB, fn () => Branch::where('code', 'B1')->value('id'));
    $alpha = asTenant($this->tenantA, fn () => Branch::where('code', 'A1')->value('id'));

    asTenant($this->tenantA, function () use ($alpha, $bravo) {
        $exists = fn ($id) => Validator::make(['id' => $id], ['id' => [Rule::exists('branches', 'id')]])->passes();
        $unique = fn ($code) => Validator::make(['code' => $code], ['code' => [Rule::unique('branches', 'code')]])->passes();

        expect($exists($alpha))->toBeTrue()
            ->and($exists($bravo))->toBeFalse()
            // another company's code is free here; our own is taken
            ->and($unique('B1'))->toBeTrue()
            ->and($unique('A1'))->toBeFalse();
    });

    app(TenantContext::class)->forget();
    expect(Validator::make(['id' => $alpha], ['id' => [Rule::exists('branches', 'id')]])->passes())->toBeFalse();
});

it('rejects writing a row into another tenant', function () {
    app(TenantContext::class)->set($this->tenantA);

    $branch = new Branch(['code' => 'X', 'name' => 'Sneaky']);
    $branch->tenant_id = $this->tenantB->id;
    $branch->save();
})->throws(LogicException::class, 'another tenant');

it('never moves a row to another tenant', function () {
    app(TenantContext::class)->set($this->tenantA);

    $branch = Branch::where('code', 'A1')->first();
    $branch->tenant_id = $this->tenantB->id;
    $branch->save();
})->throws(LogicException::class, 'never moves');

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
    ]);
    // ...and goes back to no tenant after the job.
    expect(app(TenantContext::class)->id())->toBeNull()
        ->and(Branch::count())->toBe(0);
});

it('keeps the caller tenant after a sync job of another tenant', function () {
    config(['queue.default' => 'sync']);
    app(TenantContext::class)->set($this->tenantB);

    asTenant($this->tenantA, function () {
        RecordVisibleBranches::dispatch();
    });

    expect(RecordVisibleBranches::$seen['eloquent'])->toBe(['Alpha HQ'])
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
    expect($this->get('http://bravo.localhost/_test/branches')->assertOk()->json('eloquent'))->toBe(['Bravo HQ']);
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
    expect($this->get('/_test/branches')->assertOk()->json())->toBe(['eloquent' => []]);
});
