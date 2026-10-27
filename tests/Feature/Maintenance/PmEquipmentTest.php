<?php

use App\Modules\Asset\Actions\AddStandardCategories;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\StandardCategories;
use App\Modules\Maintenance\Actions\AddStandardChecklists;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\StandardTemplates;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

it('has a standard category and template for every kind of equipment an MA company looks after', function () {
    $prefixes = array_column(StandardCategories::all(), 'code_prefix');
    $covered = array_merge(...array_column(StandardTemplates::all(), 'prefixes'));

    // PC, Laptop, Server, Printer, Network, Data Center, UPS, Battery Bank, Generator, ATS, Precision Air,
    // Fire Alarm, Smoke Detector, CCTV, Access Control, Cabling, Environmental Monitoring, Backup & Storage,
    // electrical boards, water leak, fire suppression, control room
    foreach (['PC', 'NB', 'SV', 'PR', 'SW', 'RT', 'FW', 'AP', 'DC', 'RK', 'UPS', 'BAT', 'GEN', 'ATS', 'PAC', 'FA', 'SD', 'CCTV', 'ACS', 'CAB', 'EMS', 'STO', 'EDB', 'WLD', 'FSS', 'CTR'] as $prefix) {
        expect($prefixes)->toContain($prefix)->and($covered)->toContain($prefix);
    }
    expect($covered)->toContain('');

    // Every standard template is valid as a template would be saved, and has a code of its own.
    $codes = array_column(StandardTemplates::all(), 'code');
    expect($codes)->toBe(array_values(array_unique($codes)));
    foreach (StandardTemplates::all() as $template) {
        $items = ChecklistItems::clean($template['items']);
        $keys = array_column($items, 'key');
        expect($keys)->toBe(array_values(array_unique($keys)))
            ->and(ChecklistItems::schemaErrors($items))->toBe([])
            ->and(ChecklistItems::clean($items))->toBe($items);
        foreach ($keys as $key) {
            expect($key)->toMatch(ChecklistItems::KEY_PATTERN);
        }
    }
});

it('adds the standard templates of the categories the company has, never over its own', function () {
    $admin = userWithRole('admin_company');
    app(AddStandardCategories::class)->handle();
    $ups = AssetCategory::where('code_prefix', 'UPS')->sole();
    createPmTemplate(['code' => 'MY-UPS', 'name' => 'UPS ของเรา', 'asset_category_id' => $ups->id, 'items' => [['key' => 'a', 'label' => 'A', 'type' => 'check']]]);
    // A category of the company's own (no standard prefix) gets nothing.
    $drone = createAssetCategory(['name' => 'โดรน', 'code_prefix' => 'DRN']);

    $expected = count(StandardTemplates::all());
    $this->actingAs($admin)->get('/pm-checklists')->assertInertia(fn (Assert $page) => $page
        ->where('standardMissing', fn ($names) => count($names) === $expected && collect($names)->contains('UPS PM')));

    $this->actingAs($admin)->post('/pm-checklists/standard')->assertSessionHasNoErrors();
    expect(PmChecklist::count())->toBe($expected + 1)
        ->and(PmChecklist::where('code', 'DC-UPS')->sole()->status)->toBe(PmChecklist::STATUS_PUBLISHED)
        // the company's own UPS template stays the one UPS assets get; the standard one is there to choose
        ->and(PmTemplateAssignment::where('asset_category_id', $ups->id)->pluck('template_code')->all())->toBe(['MY-UPS'])
        ->and(PmTemplateAssignment::where('template_code', 'DC-UPS')->exists())->toBeFalse()
        ->and(PmTemplateAssignment::whereNull('asset_category_id')->sole()->template_code)->toBe('STD-GENERAL')
        ->and(PmTemplateAssignment::where('asset_category_id', $drone->id)->exists())->toBeFalse()
        ->and(PmTemplateAssignment::where('template_code', 'PC-SET')->count())->toBe(3);

    $battery = PmChecklist::where('code', 'DC-BATT')->sole();
    expect(collect($battery->items)->firstWhere('key', 'block_voltage'))
        ->toMatchArray(['type' => 'repeat_table', 'rows' => ['from' => 'battery_total', 'max' => 100]]);

    $this->actingAs($admin)->get('/pm-checklists')->assertInertia(fn (Assert $page) => $page->where('standardMissing', []));
    $this->actingAs(userWithRole('helpdesk'))->post('/pm-checklists/standard')->assertForbidden();
});

it('gives a new company the standard templates of the categories it starts with', function () {
    $this->actingAs(createSuperadmin())->post('/platform/tenants', [
        'name' => 'บริษัท ใหม่ จำกัด', 'subdomain' => 'newco', 'status' => 'active',
        'admin_name' => 'คุณสมชาย', 'admin_email' => 'admin@newco.test',
        'admin_password' => 'Password-123', 'admin_password_confirmation' => 'Password-123',
    ])->assertSessionHasNoErrors();

    // Every template its (everyday) categories call for, none left to add; the data center ones come with those categories.
    asTenant(Tenant::where('subdomain', 'newco')->sole(), fn () => expect(PmChecklist::count())->toBeGreaterThan(0)
        ->and(app(AddStandardChecklists::class)->missing())->toBe([])
        ->and(PmChecklist::where('code', 'NW-SWITCH')->exists())->toBeTrue());
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
