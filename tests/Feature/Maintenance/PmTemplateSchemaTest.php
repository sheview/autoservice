<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Support\ChecklistItems;

function schemaFields(): array
{
    return ChecklistItems::clean([
        ['key' => 'cells', 'label' => 'Battery total', 'type' => 'number', 'unit' => 'block'],
        ['key' => 'volts', 'label' => 'Block voltage', 'type' => 'repeat_table', 'rows' => ['from' => 'cells', 'max' => 4],
            'columns' => [['key' => 'v', 'label' => 'Volt', 'type' => 'number', 'min' => 12, 'max' => 14], ['key' => 'ok', 'label' => 'OK', 'type' => 'pass_fail']]],
        ['key' => 'main_input', 'label' => 'Main input', 'type' => 'measure_table',
            'rows' => [['key' => 'phase', 'label' => 'Phase voltage', 'unit' => 'V', 'min' => 210, 'max' => 240], ['key' => 'freq', 'label' => 'Frequency', 'min' => 49, 'max' => 51]],
            'columns' => [['key' => 'l1', 'label' => 'L1'], ['key' => 'l2', 'label' => 'L2']]],
        ['key' => 'voltage', 'label' => 'Voltage', 'type' => 'measure_table',
            'rows' => [['key' => 'reading', 'label' => 'Reading', 'min' => 0, 'max' => 1]],
            'columns' => [['key' => 'r_s', 'label' => 'R - S', 'min' => 371, 'max' => 410], ['key' => 'n_g', 'label' => 'N - G', 'min' => 0, 'max' => 0.9]]],
        ['key' => 'reader', 'label' => 'Reader Type', 'type' => 'select', 'options' => ['Face scan', 'Card']],
        ['key' => 'checks', 'label' => 'Checks', 'type' => 'checkbox_group', 'options' => ['A', 'B', 'C']],
        ['key' => 'serial', 'label' => 'S/N', 'type' => 'readonly_from_asset', 'source' => 'serial_number', 'required' => true],
        ['key' => 'notes', 'label' => 'Detail', 'type' => 'textarea', 'required' => true],
        ['key' => 'photo', 'label' => 'Photo', 'type' => 'photo', 'photos' => 3],
        ['key' => 'temp', 'label' => 'Temperature', 'type' => 'number', 'unit' => '°C', 'max' => 32],
    ]);
}

it('keeps answers in the shape of each field type and the asset value of the moment', function () {
    $fields = schemaFields();
    $answers = ChecklistItems::normalize($fields, [
        'cells' => '3',
        'volts' => [['v' => '13.1', 'ok' => 'pass'], ['v' => 'x', 'ok' => 'maybe'], ['v' => 15], ['v' => 13]],
        'main_input' => ['phase' => ['l1' => '220', 'l2' => 250, 'l3' => 1], 'nope' => ['l1' => 1]],
        'reader' => 'Iris',
        'checks' => ['C', 'A', 'Z'],
        'serial' => 'typed-over',
        'notes' => '  ok  ',
        'photo' => 'x',
    ], ['serial_number' => 'SN-001']);

    expect($answers['volts'])->toBe([['v' => 13.1, 'ok' => 'pass'], ['v' => null, 'ok' => null], ['v' => 15.0, 'ok' => null]])
        ->and($answers['main_input'])->toBe(['phase' => ['l1' => 220.0, 'l2' => 250.0], 'freq' => ['l1' => null, 'l2' => null]])
        ->and($answers['voltage'])->toBeNull()
        ->and($answers['reader'])->toBeNull()
        ->and($answers['checks'])->toBe(['A', 'C'])
        ->and($answers['serial'])->toBe('SN-001')
        ->and($answers['notes'])->toBe('ok')
        ->and($answers['photo'])->toBeNull();
});

it('flags readings out of range, a column range before its row range', function () {
    $fields = schemaFields();
    $answers = ChecklistItems::normalize($fields, [
        'cells' => 2,
        'volts' => [['v' => 13, 'ok' => 'fail'], ['v' => 11.9]],
        'main_input' => ['phase' => ['l1' => 220, 'l2' => 250], 'freq' => ['l1' => 50]],
        'voltage' => ['reading' => ['r_s' => 380, 'n_g' => 0.95]],
        'temp' => 33,
    ]);

    expect(ChecklistItems::failures($fields, $answers))->toBe([
        'Block voltage – 1 OK',
        'Block voltage – 2 Volt',
        'Main input – Phase voltage L2',
        'Voltage – Reading N - G',
        'Temperature',
    ])
        ->and(ChecklistItems::verdict($fields[2], $answers['main_input']))->toBe('fail')
        ->and(ChecklistItems::verdict($fields[3], ['reading' => ['r_s' => 380, 'n_g' => 0.5]]))->toBe('pass')
        ->and(ChecklistItems::verdict($fields[3], null))->toBeNull()
        ->and(ChecklistItems::rangeText(210, 240, 'V'))->toBe('210 – 240 V')
        ->and(ChecklistItems::rangeText(null, 0.9))->toBe('≤ 0.9');

    // required fields: a table counts once a cell is filled; asset values and photos are never required
    expect(ChecklistItems::missing($fields, $answers))->toBe(['Detail']);
});

it('records table answers on a work order, and refuses "passed" with a reading out of range', function () {
    $admin = userWithRole('admin_company');
    $category = createAssetCategory();
    createPmTemplate(['code' => 'UPS', 'asset_category_id' => $category->id, 'items' => schemaFields()]);
    $customer = createCustomer();
    $contract = createContract($customer);
    $asset = createAsset($category, ['customer_id' => $customer->id, 'serial_number' => 'SN-9']);
    $contract->contractAssets()->create(['asset_id' => $asset->id]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6, 'assignee_id' => $admin->id]);
    $visit = app(StartPmVisit::class)->handle($plan->visits()->orderBy('round')->first(), $admin);
    $item = $visit->items()->sole();
    $url = "/pm-visits/{$visit->ulid}/items/{$item->id}";

    $answers = [
        'cells' => 2,
        'volts' => [['v' => 13, 'ok' => 'pass'], ['v' => 13.5, 'ok' => 'pass']],
        'main_input' => ['phase' => ['l1' => 220, 'l2' => 260]],
        'checks' => ['A'],
        'notes' => 'ทำความสะอาดแล้ว',
    ];
    $this->actingAs($admin)->put($url, ['result' => 'passed', 'answers' => [...$answers, 'checks' => ['Z']]])
        ->assertSessionHasErrors('answers.checks.0');
    $this->actingAs($admin)->put($url, ['result' => 'passed', 'answers' => [...$answers, 'volts' => [['v' => 'abc']]]])
        ->assertSessionHasErrors('answers.volts.0.v');
    $this->actingAs($admin)->put($url, ['result' => 'passed', 'answers' => $answers])->assertSessionHasErrors('result');

    $this->actingAs($admin)->put($url, ['result' => 'failed', 'note' => 'แรงดัน L2 สูง', 'answers' => $answers])->assertSessionHasNoErrors();
    $item->refresh();
    expect($item->answers['main_input']['phase']['l2'])->toEqual(260)
        ->and($item->answers['serial'])->toBe('SN-9')
        ->and($item->answers['volts'])->toHaveCount(2);

    // recording again keeps one result per asset
    $this->actingAs($admin)->put($url, ['result' => 'failed', 'note' => 'ซ้ำ', 'answers' => $answers])->assertSessionHasNoErrors();
    expect($visit->items()->count())->toBe(1);
});
