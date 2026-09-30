<?php

use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\Modules;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->customer = createTenant('customer');
});

function navigationTitles(Assert $page): array
{
    return collect($page->toArray()['props']['navigation'])->pluck('title')->all();
}

it('builds the sidebar from config, filtered by permission', function () {
    $this->actingAs(userWithRole('admin_company'))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(navigationTitles($page))
            ->toBe(['หน้าหลัก', 'ใบงาน', 'รอบ PM', 'ทรัพย์สิน', 'หมวดทรัพย์สิน', 'สัญญา MA', 'ลูกค้า', 'แผน PM', 'พิมพ์ป้าย QR', 'Checklist PM', 'วันหยุด', 'ผู้ใช้งาน', 'บทบาทและสิทธิ์']));

    $this->actingAs(userWithRole('technician'))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(navigationTitles($page))->toBe(['หน้าหลัก', 'ใบงาน', 'รอบ PM', 'ทรัพย์สิน', 'สัญญา MA', 'ลูกค้า', 'แผน PM', 'พิมพ์ป้าย QR', 'Checklist PM']));
});

it('turns the asset module on by default', function () {
    expect(app(Modules::class)->enabled('asset'))->toBeTrue()
        ->and(app(Modules::class)->enabled('asset', $this->customer))->toBeTrue();
});

it('hides the menu and returns 404 when the module is switched off', function () {
    $admin = userWithRole('admin_company');
    Feature::for($this->tenant)->deactivate(Modules::feature('asset'));

    $this->actingAs($admin)->get('/assets')->assertNotFound();
    $this->actingAs($admin)->get('/asset-categories')->assertNotFound();
    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(navigationTitles($page))->not->toContain('ทรัพย์สิน'));

    // other tenants are not affected
    expect(app(Modules::class)->enabled('asset', $this->customer))->toBeTrue();
});

it('never gives the platform tenant business modules', function () {
    $superadmin = createSuperadmin();

    $this->actingAs($superadmin)->get('/assets')->assertNotFound();
});

it('lets a superadmin switch modules of a tenant and logs it', function () {
    $superadmin = createSuperadmin(['name' => 'Root']);

    $this->actingAs($superadmin)->get("/platform/tenants/{$this->customer->ulid}/modules")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Platform/Tenants/Modules')->where('modules', ['asset' => true, 'contract' => true, 'service' => true, 'maintenance' => true, 'labeling' => true]));

    $this->actingAs($superadmin)->put("/platform/tenants/{$this->customer->ulid}/modules", ['modules' => ['asset' => false]])
        ->assertRedirect(route('platform.impersonation.index'))
        ->assertSessionHasNoErrors();

    expect(app(Modules::class)->enabled('asset', $this->customer))->toBeFalse();

    $log = asTenant($superadmin->tenant, fn () => Activity::where('event', 'modules_updated')->first());
    // toEqual: JSONB does not keep key order.
    expect($log->properties['old'])->toEqual(['asset' => true, 'contract' => true, 'service' => true, 'maintenance' => true, 'labeling' => true])
        ->and($log->properties['attributes'])->toEqual(['asset' => false, 'contract' => true, 'service' => true, 'maintenance' => true, 'labeling' => true])
        ->and($log->properties['actor']['name'])->toBe('Root');
});

it('rejects unknown modules', function () {
    $superadmin = createSuperadmin();

    $this->actingAs($superadmin)->put("/platform/tenants/{$this->customer->ulid}/modules", ['modules' => ['asset' => true, 'rocket' => true]])
        ->assertSessionHasErrors('modules');
});

it('forbids a customer admin and an impersonating superadmin from switching modules', function () {
    $this->actingAs(userWithRole('admin_company'))->get("/platform/tenants/{$this->customer->ulid}/modules")->assertForbidden();

    $superadmin = createSuperadmin();
    $this->actingAs($superadmin)->post("/platform/impersonation/{$this->customer->ulid}");
    $this->actingAs($superadmin)->put("/platform/tenants/{$this->customer->ulid}/modules", ['modules' => ['asset' => false]])
        ->assertForbidden();

    expect(app(Modules::class)->enabled('asset', $this->customer))->toBeTrue();
});
