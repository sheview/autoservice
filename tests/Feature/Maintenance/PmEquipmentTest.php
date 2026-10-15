<?php

use App\Modules\Asset\Actions\AddStandardCategories;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\StandardCategories;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\StandardChecklists;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

it('has a standard category and checklist for every kind of equipment an MA company looks after', function () {
    $prefixes = array_column(StandardCategories::all(), 'code_prefix');

    // PC, Laptop, Server, Printer, Network, Data Center, UPS, Battery Bank, Generator, ATS, Precision Air,
    // Fire Alarm, Smoke Detector, CCTV, Access Control, Cabling, Environmental Monitoring, Backup & Storage
    foreach (['PC', 'NB', 'SV', 'PR', 'SW', 'RT', 'FW', 'AP', 'DC', 'RK', 'UPS', 'BAT', 'GEN', 'ATS', 'PAC', 'FA', 'SD', 'CCTV', 'ACS', 'CAB', 'EMS', 'STO'] as $prefix) {
        expect($prefixes)->toContain($prefix)
            ->and(StandardChecklists::all())->toHaveKey($prefix);
    }
    expect(StandardChecklists::all())->toHaveKey('');

    // Every standard checklist is valid as a template would be saved.
    foreach (StandardChecklists::all() as $checklist) {
        $keys = array_column($checklist['items'], 'key');
        expect($keys)->toBe(array_values(array_unique($keys)));
        foreach ($checklist['items'] as $item) {
            expect($item['key'])->toMatch('/^[a-z][a-z0-9_]{0,39}$/');
        }
    }
});

it('adds the standard checklists of the categories the company has, never over its own', function () {
    $admin = userWithRole('admin_company');
    app(AddStandardCategories::class)->handle();
    $ups = AssetCategory::where('code_prefix', 'UPS')->sole();
    PmChecklist::create(['name' => 'UPS ของเรา', 'asset_category_id' => $ups->id, 'items' => [['key' => 'a', 'label' => 'A', 'type' => 'check']]]);
    // A category of the company's own (no standard prefix) gets nothing.
    createAssetCategory(['name' => 'โดรน', 'code_prefix' => 'DRN']);

    $expected = count(StandardChecklists::all()) - 1;
    $this->actingAs($admin)->get('/pm-checklists')->assertInertia(fn (Assert $page) => $page
        ->where('standardMissing', fn ($names) => count($names) === $expected && ! collect($names)->contains('PM เครื่องสำรองไฟ (UPS)')));

    $this->actingAs($admin)->post('/pm-checklists/standard')->assertSessionHasNoErrors();
    expect(PmChecklist::count())->toBe($expected + 1)
        ->and(PmChecklist::where('asset_category_id', $ups->id)->sole()->name)->toBe('UPS ของเรา')
        ->and(PmChecklist::whereNull('asset_category_id')->sole()->name)->toBe('PM ทั่วไป');

    $generator = PmChecklist::where('name', 'PM เครื่องกำเนิดไฟฟ้า')->sole();
    expect(collect($generator->items)->firstWhere('key', 'frequency'))
        ->toMatchArray(['type' => 'measure', 'unit' => 'Hz', 'min' => 49.5, 'max' => 50.5]);

    $this->actingAs($admin)->get('/pm-checklists')->assertInertia(fn (Assert $page) => $page->where('standardMissing', []));
    $this->actingAs(userWithRole('helpdesk'))->post('/pm-checklists/standard')->assertForbidden();
});

it('gives a new company the standard checklists with its categories', function () {
    $this->actingAs(createSuperadmin())->post('/platform/tenants', [
        'name' => 'บริษัท ใหม่ จำกัด', 'subdomain' => 'newco', 'status' => 'active',
        'admin_name' => 'คุณสมชาย', 'admin_email' => 'admin@newco.test',
        'admin_password' => 'Password-123', 'admin_password_confirmation' => 'Password-123',
    ])->assertSessionHasNoErrors();

    asTenant(Tenant::where('subdomain', 'newco')->sole(), fn () => expect(PmChecklist::count())->toBe(count(StandardChecklists::all())));
});

it('lists the equipment under PM with its last and next PM', function () {
    $this->travelTo('2026-05-10 10:00');
    $helpdesk = userWithRole('helpdesk');
    $tech = userWithRole('technician');
    $customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'UPS']);
    $planned = createAsset($category, ['customer_id' => $customer->id, 'name' => 'Planned UPS']);
    $loose = createAsset($category, ['customer_id' => $customer->id, 'name' => 'Not under PM']);

    $contract = createContract($customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $planned->id]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $tech->id]);

    $this->actingAs($helpdesk)->get('/pm-equipment')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Equipment/Index')
        ->where('assets.total', 1)
        ->where('assets.data.0.name', 'Planned UPS')
        ->where('assets.data.0.pm.last', null)
        // Round 2 of the plan (due 2026-06-30) is the next one; round 1 ended before the plan was made.
        ->where('assets.data.0.pm.next.date', $plan->visits()->orderBy('round')->first()->due_on->toDateString())
        ->where('counts.never', 1));

    $this->travelTo('2026-07-05');
    $this->actingAs($helpdesk)->get('/pm-equipment?pm=overdue')->assertInertia(fn (Assert $page) => $page
        ->where('assets.total', 1)->where('assets.data.0.pm.next.overdue', true));
    $this->actingAs($helpdesk)->get('/pm-equipment?search=Not')->assertInertia(fn (Assert $page) => $page->where('assets.total', 0));

    $this->actingAs(userWithRole('customer_it', ['customer_id' => $customer->id]))->get('/pm-equipment')->assertForbidden();
    expect($loose->exists)->toBeTrue();
});
