<?php

use App\Modules\Service\Models\Holiday;
use App\Modules\Service\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('manages the holidays of a year', function () {
    $this->actingAs($this->admin)->post('/holidays', ['date' => '2026-04-13', 'name' => 'วันสงกรานต์'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/holidays', ['date' => '2026-04-13', 'name' => 'ซ้ำ'])->assertSessionHasErrors('date');
    $this->actingAs($this->admin)->post('/holidays', ['date' => '2027-01-01', 'name' => 'วันขึ้นปีใหม่']);

    $this->actingAs($this->admin)->get('/holidays?year=2026')
        ->assertInertia(fn (Assert $page) => $page->component('Service/Holidays/Index')
            ->where('holidays', [['id' => Holiday::where('name', 'วันสงกรานต์')->value('id'), 'date' => '2026-04-13', 'name' => 'วันสงกรานต์']]));

    $holiday = Holiday::where('name', 'วันสงกรานต์')->first();
    $this->actingAs($this->admin)->delete("/holidays/{$holiday->id}")->assertSessionHasNoErrors();
    expect(Holiday::count())->toBe(1);

    // helpdesk may look but not change
    $helpdesk = userWithRole('helpdesk');
    $this->actingAs($helpdesk)->get('/holidays')->assertOk();
    $this->actingAs($helpdesk)->post('/holidays', ['date' => '2026-05-01', 'name' => 'x'])->assertForbidden();
    $this->actingAs($helpdesk)->get('/holidays')->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'delete' => false]));
});

it('lets holidays.manage add and remove holidays, and keeps roles without holidays.view out', function () {
    grantTo('helpdesk', ['holidays.manage']);
    $helpdesk = userWithRole('helpdesk');

    $this->actingAs($helpdesk)->get('/holidays')->assertInertia(fn (Assert $page) => $page->where('can', ['create' => true, 'delete' => true]));
    $this->actingAs($helpdesk)->post('/holidays', ['date' => '2026-05-01', 'name' => 'วันแรงงาน'])->assertSessionHasNoErrors();
    $this->actingAs($helpdesk)->delete('/holidays/'.Holiday::sole()->id)->assertSessionHasNoErrors();
    expect(Holiday::count())->toBe(0);

    $this->actingAs(userWithRole('technician'))->get('/holidays')->assertForbidden();
});

it('keeps tickets, holidays and their numbers per tenant', function () {
    $mine = openTicket($this->admin);
    Holiday::create(['date' => '2026-12-31', 'name' => 'Mine']);

    $other = createTenant('other');
    [$theirs, $theirAsset, $theirCustomer, $theirUser, $theirHoliday] = asTenant($other, function () {
        $user = userWithRole('admin_company');
        $customer = createCustomer();

        return [
            openTicket($user), createAsset(createAssetCategory(), ['customer_id' => $customer->id]), $customer, $user,
            Holiday::create(['date' => '2026-12-31', 'name' => 'Theirs']),
        ];
    });

    // Each company counts its own numbers (shown with its own company code).
    expect($mine->getRawOriginal('ticket_no'))->toBe($theirs->getRawOriginal('ticket_no'))
        ->and($mine->ticket_no)->not->toBe($theirs->ticket_no);

    $this->actingAs($this->admin)->get('/tickets?status=all')
        ->assertInertia(fn (Assert $page) => $page->where('tickets.total', 1));
    $this->actingAs($this->admin)->get("/tickets/{$theirs->ulid}")->assertNotFound();
    $this->actingAs($this->admin)->post("/tickets/{$theirs->ulid}/move", ['action' => 'cancel', 'comment' => 'x'])->assertNotFound();
    $this->actingAs($this->admin)->delete("/holidays/{$theirHoliday->id}")->assertNotFound();

    // references into the other tenant are rejected
    $this->actingAs($this->admin)->post('/tickets', [
        'customer_id' => $theirCustomer->id, 'title' => 'x', 'priority' => 'low', 'source' => 'phone',
    ])->assertSessionHasErrors('customer_id');
    $this->actingAs($this->admin)->post('/tickets', [
        'asset_id' => $theirAsset->id, 'title' => 'x', 'priority' => 'low', 'source' => 'phone',
    ])->assertSessionHasErrors('asset_id');
    $this->actingAs($this->admin)->post("/tickets/{$mine->ulid}/assign", ['assignee_id' => $theirUser->id])
        ->assertSessionHasErrors('assignee_id');

    // and raw queries only see this tenant (RLS)
    expect(DB::table('tickets')->pluck('id')->all())->toBe([$mine->id])
        ->and(DB::table('ticket_events')->where('ticket_id', $theirs->id)->count())->toBe(0)
        ->and(DB::table('holidays')->pluck('name')->all())->toBe(['Mine'])
        ->and(Ticket::count())->toBe(1);
});
