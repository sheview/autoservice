<?php

use App\Modules\Asset\Actions\SaveAssetCategory;
use App\Modules\Maintenance\Actions\AddStandardChecklists;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A template's own words for pass / fail (the MOTS sheets: เรียบร้อย / ยังไม่เรียบร้อย), and device
 * details from the asset's spec fields (hostname, CPU, RAID ...) — typed by the technician when the
 * register has none. And the MOTS templates to choose from.
 */

beforeEach(function () {
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 pm', 200, ['Content-Type' => 'application/pdf'])]);
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);

    // A firewall category with a hostname spec field; one firewall with it filled in, one without.
    $this->category = createAssetCategory(['name' => 'ไฟร์วอลล์', 'code_prefix' => 'FW']);
    app(SaveAssetCategory::class)->handle($this->category, [
        'name' => 'ไฟร์วอลล์', 'code_prefix' => 'FW', 'service_line' => 'network', 'asset_type' => 'hardware', 'requires_serial' => false,
        'spec_fields' => [['key' => 'hostname', 'label' => 'Hostname', 'type' => 'text']],
    ]);
    $this->customer = createCustomer(['name' => 'MOTS']);
    $this->fw1 = createAsset($this->category->fresh(), ['customer_id' => $this->customer->id, 'name' => 'FortiGate 1', 'model' => 'FortiGate-400F', 'specs' => ['hostname' => 'MOTS-FW-01']]);
    $this->fw2 = createAsset($this->category->fresh(), ['customer_id' => $this->customer->id, 'name' => 'FortiGate 2', 'model' => 'FortiGate-400F']);
});

it('keeps a pass/fail field\'s own words and a spec field as a source, and nothing else', function () {
    $items = ChecklistItems::clean([
        ['key' => 'dashboard', 'label' => 'Dashboard', 'type' => 'pass_fail', 'labels' => ['pass' => ' เรียบร้อย ', 'fail' => 'ยังไม่เรียบร้อย', 'na' => '', 'odd' => 'x']],
        ['key' => 'plain', 'label' => 'Plain', 'type' => 'pass_fail', 'labels' => ['pass' => '  ']],
        ['key' => 'host', 'label' => 'Hostname', 'type' => 'readonly_from_asset', 'source' => 'spec.hostname'],
        ['key' => 'bad', 'label' => 'Bad', 'type' => 'readonly_from_asset', 'source' => 'password'],
        ['key' => 'words', 'label' => 'Text', 'type' => 'text', 'labels' => ['pass' => 'x']],
    ]);

    expect($items[0]['labels'])->toBe(['pass' => 'เรียบร้อย', 'fail' => 'ยังไม่เรียบร้อย'])
        ->and($items[1])->not->toHaveKey('labels')
        ->and($items[2]['source'])->toBe('spec.hostname')
        ->and($items[3]['source'])->toBe('asset_code')
        ->and($items[4])->not->toHaveKey('labels');

    // the register's value first, else what was typed
    $asset = ['serial_number' => 'SN-1', 'specs' => ['hostname' => 'MOTS-FW-01']];
    expect(ChecklistItems::normalize([$items[2]], ['host' => 'typed'], $asset)['host'])->toBe('MOTS-FW-01')
        ->and(ChecklistItems::normalize([$items[2]], ['host' => ' MOTS-FW-02 '], ['specs' => []])['host'])->toBe('MOTS-FW-02')
        ->and(ChecklistItems::assetFacts($asset))->toBe(['serial_number' => 'SN-1', 'spec.hostname' => 'MOTS-FW-01']);
});

it('lets the template builder pick the categories\' spec fields and set the words', function () {
    $this->actingAs($this->admin)->get('/pm-checklists/create')->assertInertia(fn (Assert $page) => $page
        ->where('specSources', fn ($specs) => collect($specs)->contains(fn ($s) => $s['key'] === 'hostname' && in_array('ไฟร์วอลล์', $s['categories'], true))));

    $this->actingAs($this->admin)->post('/pm-checklists', [
        'code' => 'FW-WORDS', 'name' => 'Firewall', 'service_line' => 'network', 'layout' => 'per_asset', 'blocks' => [],
        'items' => [
            ['key' => 'host', 'label' => 'Hostname', 'type' => 'readonly_from_asset', 'source' => 'spec.hostname'],
            ['key' => 'dashboard', 'label' => 'Dashboard', 'type' => 'pass_fail', 'labels' => ['pass' => 'เรียบร้อย', 'fail' => 'ยังไม่เรียบร้อย']],
        ],
    ])->assertSessionHasNoErrors();
    $template = PmChecklist::where('code', 'FW-WORDS')->sole();
    expect($template->items[0]['source'])->toBe('spec.hostname')
        ->and($template->items[1]['labels'])->toBe(['pass' => 'เรียบร้อย', 'fail' => 'ยังไม่เรียบร้อย']);

    $this->actingAs($this->admin)->post('/pm-checklists', [
        'code' => 'FW-BAD', 'name' => 'Firewall', 'service_line' => 'network', 'layout' => 'per_asset', 'blocks' => [],
        'items' => [['key' => 'host', 'label' => 'Hostname', 'type' => 'readonly_from_asset', 'source' => 'spec.Host Name!']],
    ])->assertSessionHasErrors('items.0.source');
});

it('records the hostname from the register or as typed, and prints the template\'s words', function () {
    createPmTemplate(['name' => 'Firewall PM', 'asset_category_id' => $this->category->id, 'items' => [
        ['key' => 'host', 'label' => 'Hostname', 'type' => 'readonly_from_asset', 'source' => 'spec.hostname', 'section' => 'อุปกรณ์'],
        ['key' => 'dashboard', 'label' => 'สถานะภาพรวมของอุปกรณ์ (Dashboard)', 'type' => 'pass_fail', 'section' => 'ผล',
            'labels' => ['pass' => 'เรียบร้อย', 'fail' => 'ยังไม่เรียบร้อย']],
    ]]);
    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $this->fw1->id]);
    $contract->contractAssets()->create(['asset_id' => $this->fw2->id]);
    $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM MOTS', 'interval_months' => 3, 'assignee_id' => $this->tech->id]);
    $visit = $plan->visits()->orderBy('round')->first();
    $url = "/pm-visits/{$visit->ulid}";
    $this->actingAs($this->tech)->post("{$url}/start");
    $one = $visit->items()->where('asset_id', $this->fw1->id)->first();
    $two = $visit->items()->where('asset_id', $this->fw2->id)->first();

    // the recording page shows the register's hostname of the first, none for the second
    $this->actingAs($this->tech)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('items', fn ($items) => collect($items)->firstWhere('id', $one->id)['asset']['spec.hostname'] === 'MOTS-FW-01'
            && ! array_key_exists('spec.hostname', collect($items)->firstWhere('id', $two->id)['asset'] ?? [])));

    $this->actingAs($this->tech)->put("{$url}/items/{$one->id}", ['result' => 'passed', 'answers' => ['host' => 'typed over', 'dashboard' => 'pass']])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->put("{$url}/items/{$two->id}", ['result' => 'failed', 'answers' => ['host' => 'MOTS-FW-02', 'dashboard' => 'fail'], 'note' => 'Dashboard แจ้งเตือน'])->assertSessionHasNoErrors();
    expect($one->fresh()->answers['host'])->toBe('MOTS-FW-01')
        ->and($two->fresh()->answers['host'])->toBe('MOTS-FW-02');

    $this->actingAs($this->tech)->get("{$url}/pdf")->assertOk();
    $html = '';
    Http::assertSent(function (HttpRequest $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = $part['contents'];
            }
        }

        return true;
    });
    expect($html)->toContain('MOTS-FW-01')->toContain('MOTS-FW-02')
        ->toContain('เรียบร้อย')->toContain('ยังไม่เรียบร้อย');
});

it('offers the MOTS templates to a company with those kinds of equipment, tied to no category', function () {
    foreach ([['เครื่องแม่ข่าย', 'SV'], ['Storage', 'STO'], ['UPS', 'UPS']] as [$name, $prefix]) {
        createAssetCategory(['name' => $name, 'code_prefix' => $prefix]);
    }

    app(AddStandardChecklists::class)->handle();

    $mots = PmChecklist::where('code', 'like', 'MOTS-%')->orderBy('code')->get();
    expect($mots->pluck('code')->all())->toBe(['MOTS-FW', 'MOTS-SERVER', 'MOTS-STORAGE', 'MOTS-UPS'])
        ->and(collect($mots->firstWhere('code', 'MOTS-SERVER')->items)->firstWhere('key', 'cpu')['labels']['pass'])->toBe('เรียบร้อย')
        ->and(collect($mots->firstWhere('code', 'MOTS-SERVER')->items)->firstWhere('key', 'hostname')['source'])->toBe('spec.hostname')
        ->and(collect($mots->firstWhere('code', 'MOTS-FW')->items)->last()['type'])->toBe('photo')
        // chosen per job or tied by hand: the firewalls keep their own (Network) template
        ->and(PmTemplateAssignment::whereIn('template_code', $mots->pluck('code'))->count())->toBe(0);
});
