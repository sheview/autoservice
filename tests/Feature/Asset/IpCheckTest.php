<?php

use App\Modules\Asset\Actions\IpChoices;
use App\Modules\Asset\Actions\SaveNetwork;
use App\Modules\Asset\Actions\SaveSubnet;
use App\Modules\Asset\Models\IpAddress;
use App\Modules\Asset\Models\IpAssetHistory;
use App\Modules\Asset\Models\IpReservation;
use App\Modules\Asset\Models\Network;
use App\Modules\Asset\Models\Subnet;
use App\Modules\Asset\Support\IpRange;
use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Service\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin']);
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->beta = createCustomer(['name' => 'Beta']);
    $this->category = createAssetCategory();
    $this->site = CustomerSite::create(['customer_id' => $this->acme->id, 'name' => 'ABC Head Office']);

    $this->network = app(SaveNetwork::class)->handle(null, ['customer_id' => $this->acme->id, 'site_id' => $this->site->id, 'name' => 'LAN']);
    // 192.168.1.1 - .6
    $this->subnet = app(SaveSubnet::class)->handle(null, ['network_id' => $this->network->id, 'cidr' => '192.168.1.0/29', 'gateway' => '192.168.1.1']);

    $this->asset = fn (string $ip, array $attributes = []) => createAsset($this->category, $attributes + ['ip_address' => $ip, 'customer_id' => $this->acme->id]);
    $this->ipUrl = fn (string $ip, string $action = '') => "/ip-check/subnets/{$this->subnet->id}/ips/{$ip}".($action ? "/{$action}" : '');
});

it('reads subnets, ranges and single addresses', function () {
    expect(IpRange::parse('192.168.1.0/30'))->toBe(['192.168.1.1', '192.168.1.2'])
        ->and(IpRange::parse('192.168.1.10-12'))->toBe(['192.168.1.10', '192.168.1.11', '192.168.1.12'])
        ->and(IpRange::parse('192.168.1.300'))->toBe('invalid')
        ->and(IpRange::subnet('192.168.1.77/24'))->toBe(['cidr' => '192.168.1.0/24', 'first' => ip2long('192.168.1.0'), 'prefix' => 24])
        ->and(IpRange::subnet('10.0.0.0/16'))->toBe('too_large')
        ->and(IpRange::subnet('hello'))->toBe('invalid')
        ->and(IpRange::usable(ip2long('192.168.1.0'), 29))->toBe([ip2long('192.168.1.1'), ip2long('192.168.1.6')]);
});

it('counts the addresses of a subnet from records and from the assets that carry them', function () {
    ($this->asset)('192.168.1.1', ['name' => 'Core router']);
    ($this->asset)('192.168.1.3', ['name' => 'Printer A']);
    ($this->asset)('192.168.1.3', ['name' => 'Printer B']); // the same address twice
    ($this->asset)('192.168.1.4', ['customer_id' => $this->beta->id]); // another customer's network
    IpAddress::create(['subnet_id' => $this->subnet->id, 'ip' => '192.168.1.5', 'ip_int' => ip2long('192.168.1.5'), 'status' => 'reserved']);
    IpAddress::create(['subnet_id' => $this->subnet->id, 'ip' => '192.168.1.6', 'ip_int' => ip2long('192.168.1.6'), 'status' => 'excluded']);

    $this->actingAs($this->admin)->get("/ip-check?customer={$this->acme->id}&subnet={$this->subnet->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Asset/IpCheck/Index')
            ->where('selected.summary', ['total' => 6, 'available' => 2, 'in_use' => 1, 'reserved' => 1, 'excluded' => 1, 'conflict' => 1])
            ->where('subnets.0.site', 'ABC Head Office')
            ->where('rows.data.0.ip', '192.168.1.1')
            ->where('rows.data.0.status', 'in_use')
            ->where('rows.data.0.assets.0.name', 'Core router')
            ->where('rows.data.2.status', 'conflict')
            ->where('rows.data.2.conflict', 'duplicate')
            ->where('rows.data.3.status', 'available'));

    // Status filter and search (asset name, code, serial, hostname, MAC)
    $this->actingAs($this->admin)->get("/ip-check?subnet={$this->subnet->id}&status=available")
        ->assertInertia(fn (Assert $page) => $page->where('rows.total', 2));
    $this->actingAs($this->admin)->get('/ip-check?search=printer')
        ->assertInertia(fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.ip', '192.168.1.3'));
});

it('suggests free addresses, never the gateway, and reserves them with who and why', function () {
    ($this->asset)('192.168.1.2');

    $this->actingAs($this->admin)->get("/ip-check?find={$this->subnet->id}&count=2")
        ->assertInertia(fn (Assert $page) => $page->where('suggestions.ips', ['192.168.1.3', '192.168.1.4']));

    $this->actingAs($this->admin)->post("/ip-check/subnets/{$this->subnet->id}/reserve", [
        'ips' => ['192.168.1.3', '192.168.1.4'],
        'purpose' => 'Access Point ชั้น 2',
    ])->assertSessionHasNoErrors();

    $ip = IpAddress::where('ip', '192.168.1.3')->first();
    expect($ip->status)->toBe('reserved')
        ->and($ip->reservations()->first()->only(['purpose', 'status', 'reserved_by']))
        ->toBe(['purpose' => 'Access Point ชั้น 2', 'status' => 'active', 'reserved_by' => $this->admin->id]);

    // Taken already: nothing is reserved.
    $this->actingAs($this->admin)->post("/ip-check/subnets/{$this->subnet->id}/reserve", ['ips' => ['192.168.1.4', '192.168.1.5'], 'purpose' => 'x'])
        ->assertSessionHasErrors('ips');
    expect(IpAddress::where('ip', '192.168.1.5')->exists())->toBeFalse();
});

it('gives a reserved address to an asset, then takes it back with the history kept', function () {
    $ap = ($this->asset)('', ['name' => 'AP-023', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
    $this->actingAs($this->admin)->post("/ip-check/subnets/{$this->subnet->id}/reserve", ['ips' => ['192.168.1.45'], 'purpose' => 'x'])
        ->assertSessionHasErrors('ips'); // not in the subnet
    $this->actingAs($this->admin)->post("/ip-check/subnets/{$this->subnet->id}/reserve", ['ips' => ['192.168.1.4'], 'purpose' => 'AP'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(($this->ipUrl)('192.168.1.4', 'assign'), [
        'asset_id' => $ap->id,
        'hostname' => 'AP-F2-01',
        'mac_address' => 'aa-bb-cc-dd-ee-02',
    ])->assertSessionHasNoErrors();

    $ip = IpAddress::where('ip', '192.168.1.4')->first();
    expect($ip->only(['status', 'asset_id', 'hostname', 'mac_address']))
        ->toBe(['status' => 'in_use', 'asset_id' => $ap->id, 'hostname' => 'AP-F2-01', 'mac_address' => 'AA:BB:CC:DD:EE:02'])
        ->and($ap->fresh()->ip_address)->toBe('192.168.1.4')
        ->and(IpReservation::first()->status)->toBe('used');

    $this->actingAs($this->admin)->get(($this->ipUrl)('192.168.1.4'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Asset/IpCheck/Show')
            ->where('address.status', 'in_use')
            ->where('address.customer', 'Acme')
            ->where('address.site', 'ABC Head Office')
            ->where('address.assets.0.ulid', $ap->ulid));

    $this->actingAs($this->admin)->post(($this->ipUrl)('192.168.1.4', 'release'))->assertSessionHasNoErrors();

    expect($ip->fresh()->only(['status', 'asset_id', 'hostname']))->toBe(['status' => 'available', 'asset_id' => null, 'hostname' => null])
        ->and($ap->fresh()->ip_address)->toBeNull()
        ->and(IpAssetHistory::where('ip_address_id', $ip->id)->orderBy('id')->pluck('action')->all())->toBe(['reserved', 'assigned', 'released'])
        ->and(IpAssetHistory::where('action', 'released')->value('asset_code'))->toBe($ap->asset_code);
});

it('excludes only free addresses and keeps them out of the suggestions', function () {
    ($this->asset)('192.168.1.2');
    $this->actingAs($this->admin)->post(($this->ipUrl)('192.168.1.2', 'exclude'), ['excluded' => true])->assertSessionHasErrors('status');
    $this->actingAs($this->admin)->post(($this->ipUrl)('192.168.1.3', 'exclude'), ['excluded' => true])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("/ip-check?find={$this->subnet->id}&count=1")
        ->assertInertia(fn (Assert $page) => $page->where('suggestions.ips', ['192.168.1.4']));
});

it('keeps networks and subnets, refusing overlaps of the same owner', function () {
    $this->actingAs($this->admin)->post('/ip-check/networks', ['customer_id' => $this->acme->id, 'site_id' => $this->site->id, 'name' => 'CCTV', 'vlan_id' => 20])
        ->assertSessionHasNoErrors();
    $cctv = Network::where('name', 'CCTV')->first();

    // A site of another customer is refused.
    $this->actingAs($this->admin)->post('/ip-check/networks', ['customer_id' => $this->beta->id, 'site_id' => $this->site->id, 'name' => 'X'])
        ->assertSessionHasErrors('site_id');

    $this->actingAs($this->admin)->post('/ip-check/subnets', ['network_id' => $cctv->id, 'cidr' => '192.168.1.4/30'])->assertSessionHasErrors('cidr');
    $this->actingAs($this->admin)->post('/ip-check/subnets', ['network_id' => $cctv->id, 'cidr' => '10.0.0.0/16'])->assertSessionHasErrors('cidr');
    $this->actingAs($this->admin)->post('/ip-check/subnets', ['network_id' => $cctv->id, 'cidr' => '192.168.20.9/24', 'gateway' => '192.168.20.1'])
        ->assertSessionHasNoErrors();
    expect(Subnet::where('network_id', $cctv->id)->value('cidr'))->toBe('192.168.20.0/24');

    // Another customer may use the same range.
    $betaNet = app(SaveNetwork::class)->handle(null, ['customer_id' => $this->beta->id, 'site_id' => null, 'name' => 'LAN']);
    $this->actingAs($this->admin)->post('/ip-check/subnets', ['network_id' => $betaNet->id, 'cidr' => '192.168.1.0/24'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->delete("/ip-check/networks/{$cctv->id}")->assertSessionHasErrors('network');
    $this->actingAs($this->admin)->post(($this->ipUrl)('192.168.1.3', 'exclude'), ['excluded' => true]);
    $this->actingAs($this->admin)->delete("/ip-check/subnets/{$this->subnet->id}")->assertSessionHasErrors('subnet');
});

it('links a ticket to an address and lists it on the address', function () {
    $ap = ($this->asset)('192.168.1.2', ['name' => 'AP']);
    $ticket = openTicket($this->admin, ['customer_id' => $this->acme->id, 'asset_id' => $ap->id]);

    // The asset's address is offered on the ticket.
    $this->actingAs($this->admin)->get("/tickets/{$ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('ip.current', null)->where('ip.suggested.ip', '192.168.1.2'));

    $this->actingAs($this->admin)->post("/tickets/{$ticket->ulid}/ip", ['ip' => "{$this->subnet->id}:192.168.1.2"])->assertSessionHasNoErrors();
    $ip = IpAddress::where('ip', '192.168.1.2')->first();
    expect($ticket->fresh()->ip_address_id)->toBe($ip->id)
        ->and($ip->only(['status', 'asset_id']))->toBe(['status' => 'in_use', 'asset_id' => $ap->id]);

    $this->actingAs($this->admin)->get(($this->ipUrl)('192.168.1.2'))
        ->assertInertia(fn (Assert $page) => $page->where('tickets.0.ulid', $ticket->ulid));

    // The picker: a typed address of a known subnet is offered, also without a record; only the ticket's customer's networks.
    expect(collect(app(IpChoices::class)->handle('192.168.1.5', $this->acme->id))->pluck('key')->all())->toBe(["{$this->subnet->id}:192.168.1.5"])
        ->and(app(IpChoices::class)->handle('192.168.1.5', $this->beta->id))->toBe([])
        ->and(collect(app(IpChoices::class)->handle('192.168.1', $this->acme->id))->pluck('ip')->all())->toBe(['192.168.1.2']);

    $this->actingAs($this->admin)->post("/tickets/{$ticket->ulid}/ip", ['ip' => '999:10.0.0.1'])->assertSessionHasErrors('ip');
});

it('lets technicians find and reserve but not keep networks, and keeps customer accounts out', function () {
    $tech = userWithRole('technician');
    $this->actingAs($tech)->get('/ip-check')->assertOk();
    $this->actingAs($tech)->post("/ip-check/subnets/{$this->subnet->id}/reserve", ['ips' => ['192.168.1.3'], 'purpose' => 'x'])->assertSessionHasNoErrors();
    $this->actingAs($tech)->post('/ip-check/networks', ['name' => 'X'])->assertForbidden();
    $this->actingAs($tech)->post(($this->ipUrl)('192.168.1.4', 'exclude'), ['excluded' => true])->assertForbidden();

    $client = userWithRole('customer_it', ['customer_id' => $this->acme->id]);
    $this->actingAs($client)->get('/ip-check')->assertForbidden();
});

it('manages the sites of a customer', function () {
    $this->actingAs($this->admin)->post("/customers/{$this->acme->id}/sites", ['name' => 'Data Center'])->assertSessionHasNoErrors();
    $dc = CustomerSite::where('name', 'Data Center')->first();
    $this->actingAs($this->admin)->put("/customers/{$this->acme->id}/sites/{$dc->id}", ['name' => 'DC Bangna'])->assertSessionHasNoErrors();
    // A site belongs to its own customer only.
    $this->actingAs($this->admin)->delete("/customers/{$this->beta->id}/sites/{$dc->id}")->assertNotFound();

    $this->actingAs($this->admin)->get("/customers/{$this->acme->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('sites', fn ($sites) => collect($sites)->pluck('name')->sort()->values()->all() === ['ABC Head Office', 'DC Bangna']));
});

it('keeps every tenant to its own networks and addresses', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, function () {
        $network = app(SaveNetwork::class)->handle(null, ['customer_id' => null, 'site_id' => null, 'name' => 'Their LAN']);

        return app(SaveSubnet::class)->handle(null, ['network_id' => $network->id, 'cidr' => '10.9.9.0/29']);
    });

    $this->actingAs($this->admin)->get('/ip-check')
        ->assertInertia(fn (Assert $page) => $page->where('subnets', fn ($subnets) => collect($subnets)->pluck('cidr')->all() === ['192.168.1.0/29']));
    $this->actingAs($this->admin)->get("/ip-check/subnets/{$theirs->id}/ips/10.9.9.2")->assertNotFound();
    $this->actingAs($this->admin)->post("/ip-check/subnets/{$theirs->id}/reserve", ['ips' => ['10.9.9.3'], 'purpose' => 'x'])->assertNotFound();
    // Their subnet does not stop us using the same range.
    $this->actingAs($this->admin)->post('/ip-check/subnets', ['network_id' => $this->network->id, 'cidr' => '10.9.9.0/29'])->assertSessionHasNoErrors();
    expect(asTenant($other, fn () => IpAddress::count()))->toBe(0);
});
