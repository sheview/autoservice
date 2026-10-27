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
            ->toBe([
                'หน้าหลัก',
                'งานของฉัน', 'ใบงาน', 'ขอเข้าห้อง Server', 'เช็ค IP ว่าง',
                'ทรัพย์สิน', 'พิมพ์ป้าย QR',
                'อะไหล่', 'ความเคลื่อนไหวสต็อก', 'ใบขอซื้อ',
                'เบิก / ยืม',
                'ภาพรวม PM', 'รายงานถึงลูกค้า', 'แผน PM', 'อุปกรณ์ในแผน PM', 'ใบงาน PM (Work Orders)', 'รายงาน PM', 'Template PM', 'เครื่องมือวัด', 'ตั้งค่า PM',
                'ลูกค้า', 'สัญญา MA',
                'รายงาน', 'อะไหล่ที่เปลี่ยน/ส่งออก', 'สรุปรายบุคคล', 'สรุปรายโครงการ', 'ผลประเมินความพึงพอใจ',
                'คู่มือ',
                'ผู้ใช้งาน', 'บทบาทและสิทธิ์', 'ข้อมูลบริษัท', 'สาขา', 'วันหยุด', 'แชร์ข้อมูลกับบริษัทอื่น', 'อาการและวิธีแก้', 'ห้อง Server', 'การแจ้งเตือน', 'Log การใช้งาน',
                'หมวดทรัพย์สิน', 'สถานที่จัดเก็บ',
            ]));

    // each item carries its section
    $this->actingAs(userWithRole('admin_company'))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('navigation.0.group', null)
        ->where('navigation.1.group', 'งานบริการ'));

    // technicians do PM from their own work (งานของฉัน): no PM menu
    $this->actingAs(userWithRole('technician'))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(navigationTitles($page))->toBe([
            'หน้าหลัก', 'งานของฉัน', 'ใบงาน', 'ขอเข้าห้อง Server', 'เช็ค IP ว่าง',
            'ทรัพย์สิน', 'พิมพ์ป้าย QR', 'อะไหล่', 'ความเคลื่อนไหวสต็อก', 'ใบขอซื้อ', 'เบิก / ยืม',
            'ลูกค้า', 'สัญญา MA', 'สรุปรายบุคคล', 'ผลประเมินความพึงพอใจ', 'คู่มือ',
        ]));

    // helpdesk runs the PM work but does not shape templates or settings
    $this->actingAs(userWithRole('helpdesk'))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(collect(navigationTitles($page))->filter(fn ($title) => str_contains($title, 'PM') || $title === 'รายงานถึงลูกค้า')->values()->all())
            ->toBe(['ภาพรวม PM', 'รายงานถึงลูกค้า', 'แผน PM', 'อุปกรณ์ในแผน PM', 'ใบงาน PM (Work Orders)', 'รายงาน PM']));
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
        ->assertInertia(fn (Assert $page) => $page->component('Platform/Tenants/Modules')->where('modules', ['asset' => true, 'contract' => true, 'service' => true, 'maintenance' => true, 'labeling' => true, 'inventory' => true, 'survey' => true, 'reporting' => true, 'room_access' => true]));

    $this->actingAs($superadmin)->put("/platform/tenants/{$this->customer->ulid}/modules", ['modules' => ['asset' => false]])
        ->assertRedirect(route('platform.impersonation.index'))
        ->assertSessionHasNoErrors();

    expect(app(Modules::class)->enabled('asset', $this->customer))->toBeFalse();

    $log = asTenant($superadmin->tenant, fn () => Activity::where('event', 'modules_updated')->first());
    // toEqual: JSONB does not keep key order.
    expect($log->properties['old'])->toEqual(['asset' => true, 'contract' => true, 'service' => true, 'maintenance' => true, 'labeling' => true, 'inventory' => true, 'survey' => true, 'reporting' => true, 'room_access' => true])
        ->and($log->properties['attributes'])->toEqual(['asset' => false, 'contract' => true, 'service' => true, 'maintenance' => true, 'labeling' => true, 'inventory' => true, 'survey' => true, 'reporting' => true, 'room_access' => true])
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

it('puts the PM pages under four tabs, and gives a customer account only its reports', function () {
    $this->actingAs(userWithRole('admin_company'))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('navigation', fn ($items) => collect($items)->whereNotNull('tab')->pluck('tab')->unique()->values()->all()
            === ['pm.overview', 'pm.plans', 'pm.visits', 'pm.settings', 'settings.users', 'settings.company', 'settings.service', 'settings.system']));

    $customer = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    $this->actingAs($customer)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('navigation', fn ($items) => collect($items)->whereNotNull('tab')->pluck('title')->all() === ['รายงานถึงลูกค้า']));
});
