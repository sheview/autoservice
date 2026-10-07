<?php

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\ContractMember;
use App\Modules\Identity\Actions\SyncRoleGrants;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Service\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

function ticketOfContract(User $actor, Contract $contract, string $title): Ticket
{
    $ticket = openTicket($actor, ['title' => $title, 'customer_id' => $contract->customer_id]);
    $ticket->forceFill(['contract_id' => $contract->id])->save();

    return $ticket;
}

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->otherTech = userWithRole('technician', ['name' => 'Tech Two']);

    $this->acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->beta = createCustomer(['code' => 'BETA', 'name' => 'Beta']);
    $this->acmeContract = createContract($this->acme, ['contract_no' => 'MA-ACME']);
    $this->betaContract = createContract($this->beta, ['contract_no' => 'MA-BETA']);

    $this->acmeTicket = ticketOfContract($this->admin, $this->acmeContract, 'Acme switch down');
    $this->betaTicket = ticketOfContract($this->admin, $this->betaContract, 'Beta printer');
});

it('gives technicians scope project on what they see by default', function () {
    $grants = PermissionCatalog::grantsFor('technician');

    expect($grants['tickets.view'])->toBe('project')
        ->and($grants['contracts.view'])->toBe('project')
        ->and($grants['tickets.update'])->toBe('own');
});

it('lets whoever may change the contract set its team, and logs it', function () {
    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$this->tech->id]])
        ->assertSessionHasNoErrors();

    expect(ContractMember::where('contract_id', $this->acmeContract->id)->pluck('user_id')->all())->toBe([$this->tech->id]);
    $this->actingAs($this->admin)->get("/contracts/{$this->acmeContract->id}")->assertInertia(fn (Assert $page) => $page
        ->where('members.0.name', 'Tech One')
        ->where('history.0.description', 'แก้ไขทีมโครงการ'));

    // emptied again
    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => []])->assertSessionHasNoErrors();
    expect(ContractMember::count())->toBe(0);

    // a technician may not change the team
    $this->actingAs($this->tech)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$this->tech->id]])->assertForbidden();
});

it('only takes active staff of the company into a team', function () {
    $client = userWithRole('customer_it', ['customer_id' => $this->acme->id]);
    $inactive = userWithRole('technician', ['is_active' => false]);

    foreach ([$client, $inactive] as $user) {
        $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$user->id]])
            ->assertSessionHasErrors('user_ids');
    }
    expect(ContractMember::count())->toBe(0);
});

it('shows a team member the tickets, contract and customer of their project only', function () {
    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$this->tech->id]]);

    $this->actingAs($this->tech)->get('/tickets?status=all')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.total', 1)->where('tickets.data.0.title', 'Acme switch down')
        ->where('customers', fn ($customers) => collect($customers)->pluck('id')->all() === [$this->acme->id]));
    $this->actingAs($this->tech)->get("/tickets/{$this->acmeTicket->ulid}")->assertOk();
    $this->actingAs($this->tech)->get("/tickets/{$this->betaTicket->ulid}")->assertForbidden();

    $this->actingAs($this->tech)->get('/contracts')->assertInertia(fn (Assert $page) => $page
        ->where('contracts.total', 1)->where('contracts.data.0.contract_no', 'MA-ACME'));
    $this->actingAs($this->tech)->get("/contracts/{$this->acmeContract->id}")->assertOk();
    $this->actingAs($this->tech)->get("/contracts/{$this->betaContract->id}")->assertForbidden();
    $this->actingAs($this->tech)->get('/customers')->assertInertia(fn (Assert $page) => $page
        ->where('customers.total', 1)->where('customers.data.0.code', 'ACME'));

    // seeing is not working on it: the ticket is not theirs to change
    $this->actingAs($this->tech)->get("/tickets/{$this->acmeTicket->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.update', false));

    // someone not on the team sees none of it
    $this->actingAs($this->otherTech)->get('/tickets?status=all')->assertInertia(fn (Assert $page) => $page->where('tickets.total', 0));
    $this->actingAs($this->otherTech)->get("/contracts/{$this->acmeContract->id}")->assertForbidden();
});

it('still shows a technician with scope own only their own tickets, team or not', function () {
    setRoleScope('technician', PermissionCatalog::SCOPE_OWN, ['tickets.view']);
    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$this->tech->id]]);

    $this->actingAs($this->tech)->get('/tickets?status=all')->assertInertia(fn (Assert $page) => $page->where('tickets.total', 0));
});

it('offers a team member only the projects of their team on a purchase request', function () {
    $this->actingAs($this->tech)->get('/purchase-requests/create')->assertInertia(fn (Assert $page) => $page
        ->where('contracts', fn ($contracts) => count($contracts) === 2));

    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$this->tech->id]]);

    $this->actingAs($this->tech)->get('/purchase-requests/create')->assertInertia(fn (Assert $page) => $page
        ->where('contracts', fn ($contracts) => collect($contracts)->pluck('id')->all() === [$this->acmeContract->id]));
});

it('lets the roles matrix grant scope project', function () {
    $role = Role::findByName('technician');
    $grants = app(SyncRoleGrants::class)->grantsOf($role);
    $grants['summary-projects.view'] = 'project';

    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$role->id => $grants]])->assertSessionHasNoErrors();

    expect(app(SyncRoleGrants::class)->grantsOf($role)['summary-projects.view'])->toBe('project');
});

it('keeps teams to their own company', function () {
    $other = createTenant('other');
    [$theirContract, $theirTech] = asTenant($other, fn () => [
        createContract(createCustomer(['code' => 'ACME', 'name' => 'Their Acme']), ['contract_no' => 'MA-ACME']),
        userWithRole('technician'),
    ]);

    // their contract is not found here, and their staff cannot join our team
    $this->actingAs($this->admin)->put("/contracts/{$theirContract->id}/members", ['user_ids' => [$this->tech->id]])->assertNotFound();
    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$theirTech->id]])
        ->assertSessionHasErrors('user_ids');

    // a membership of theirs does not reach our records
    asTenant($other, fn () => ContractMember::create(['contract_id' => $theirContract->id, 'user_id' => $theirTech->id]));
    expect(ContractMember::count())->toBe(0);
});

it('checks on the server that a team member asks only for the projects of their team', function () {
    $this->travelTo('2026-10-01 10:00');
    $sfp = createPart(['code' => 'SFP'], stock: 3);
    $purchase = fn (int $contractId) => $this->actingAs($this->tech)->post('/purchase-requests', [
        'item_name' => 'Switch 24 port', 'quantity' => 1, 'unit' => 'เครื่อง', 'links' => ['https://shop.example.com/switch'],
        'reason' => 'Replace the broken one', 'needed_by' => '2026-11-01', 'contract_id' => $contractId,
    ]);
    $checkout = fn (int $contractId) => $this->actingAs($this->tech)->post('/checkout-requests', [
        'purpose' => 'Spare', 'needed_by' => '2026-10-10', 'submit' => false, 'contract_id' => $contractId,
        'items' => [['item_type' => 'part', 'part_id' => $sfp->id, 'qty' => 1]],
    ]);

    // on no team yet: any project, as before
    $purchase($this->betaContract->id)->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->put("/contracts/{$this->acmeContract->id}/members", ['user_ids' => [$this->tech->id]]);

    $purchase($this->betaContract->id)->assertSessionHasErrors(['contract_id' => 'เลือกได้เฉพาะโครงการที่คุณอยู่ในทีม']);
    $checkout($this->betaContract->id)->assertSessionHasErrors('contract_id');
    $purchase($this->acmeContract->id)->assertSessionHasNoErrors();
    $checkout($this->acmeContract->id)->assertSessionDoesntHaveErrors('contract_id');
});
