<?php

use App\Modules\Asset\Support\IpRange;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->beta = createCustomer(['name' => 'Beta']);
    $this->category = createAssetCategory();

    $this->ip = fn (string $ip, array $attributes = []) => createAsset($this->category, $attributes + ['ip_address' => $ip]);
});

it('reads subnets, ranges and single addresses', function () {
    expect(IpRange::parse('192.168.1.0/30'))->toBe(['192.168.1.1', '192.168.1.2'])
        ->and(IpRange::parse('192.168.1.77/24'))->toHaveCount(254)
        ->and(IpRange::parse('192.168.1.0/24')[0])->toBe('192.168.1.1')
        ->and(IpRange::parse('192.168.1.0/24')[253])->toBe('192.168.1.254')
        ->and(IpRange::parse('10.0.0.8/32'))->toBe(['10.0.0.8'])
        ->and(IpRange::parse('192.168.1.10-12'))->toBe(['192.168.1.10', '192.168.1.11', '192.168.1.12'])
        ->and(IpRange::parse(' 192.168.1.254 - 192.168.2.1 '))->toBe(['192.168.1.254', '192.168.1.255', '192.168.2.0', '192.168.2.1'])
        ->and(IpRange::parse('172.16.0.5'))->toBe(['172.16.0.5'])
        ->and(IpRange::parse('192.168.1.0/16'))->toBe('too_large')
        ->and(IpRange::parse('10.0.0.0-10.0.10.0'))->toBe('too_large')
        ->and(IpRange::parse('192.168.1.300'))->toBe('invalid')
        ->and(IpRange::parse('192.168.1.20-10'))->toBe('invalid')
        ->and(IpRange::parse('hello'))->toBe('invalid');
});

it('shows which addresses are taken, which are free, and the ones used twice', function () {
    $router = ($this->ip)('192.168.1.1', ['name' => 'Core router', 'customer_id' => $this->acme->id]);
    ($this->ip)('192.168.1.3', ['name' => 'Printer A']);
    ($this->ip)('192.168.1.3', ['name' => 'Printer B']);
    ($this->ip)('192.168.2.1', ['name' => 'Elsewhere']);

    $this->actingAs($this->admin)->get('/ip-check?range=192.168.1.0/29')->assertInertia(fn (Assert $page) => $page
        ->component('Asset/IpCheck')
        ->where('error', null)
        ->where('summary', ['total' => 6, 'used' => 2, 'duplicates' => 1])
        ->where('rows.0.ip', '192.168.1.1')
        ->where('rows.0.assets.0', [
            'ulid' => $router->ulid, 'asset_code' => $router->asset_code, 'name' => 'Core router',
            'customer_id' => $this->acme->id, 'visible' => true, 'customer' => 'Acme',
        ])
        ->where('rows.1.assets', [])
        ->has('rows.2.assets', 2));

    $this->actingAs($this->admin)->get('/ip-check?range=10.0.0.0/8')
        ->assertInertia(fn (Assert $page) => $page->where('error', 'too_large')->where('rows', []));
    $this->actingAs($this->admin)->get('/ip-check')
        ->assertInertia(fn (Assert $page) => $page->where('error', null)->where('rows', []));
});

it('checks one customer network at a time, or the company own devices', function () {
    ($this->ip)('192.168.1.10', ['customer_id' => $this->acme->id]);
    ($this->ip)('192.168.1.11', ['customer_id' => $this->beta->id]);
    ($this->ip)('192.168.1.12');

    $used = fn (string $customer) => $this->actingAs($this->admin)->get("/ip-check?range=192.168.1.10-12&customer={$customer}");

    $used('')->assertInertia(fn (Assert $page) => $page->where('summary.used', 3));
    $used((string) $this->acme->id)->assertInertia(fn (Assert $page) => $page->where('summary.used', 1)->has('rows.0.assets', 1));
    $used('own')->assertInertia(fn (Assert $page) => $page->where('summary.used', 1)->has('rows.2.assets', 1));
});

it('counts devices of other branches as taken without naming them', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $south = Branch::create(['code' => 'S', 'name' => 'South']);
    ($this->ip)('192.168.1.1', ['name' => 'North switch', 'branch_id' => $north->id]);
    ($this->ip)('192.168.1.2', ['name' => 'South switch', 'branch_id' => $south->id]);
    $tech = userWithRole('technician', ['branch_id' => $north->id]);

    $this->actingAs($tech)->get('/ip-check?range=192.168.1.1-3')->assertInertia(fn (Assert $page) => $page
        ->where('summary.used', 2)
        ->where('rows.0.assets.0.name', 'North switch')
        ->where('rows.1.assets.0', ['ulid' => null, 'asset_code' => null, 'name' => null, 'customer_id' => null, 'visible' => false, 'customer' => null])
        ->where('rows.2.assets', []));
});

it('is for staff who see assets, inside their own company only', function () {
    $other = createTenant('other');
    asTenant($other, fn () => createAsset(createAssetCategory(), ['ip_address' => '192.168.1.5']));

    $this->actingAs($this->admin)->get('/ip-check?range=192.168.1.5')->assertInertia(fn (Assert $page) => $page->where('summary.used', 0));

    $this->actingAs(userWithRole('customer', ['customer_id' => $this->acme->id]))->get('/ip-check')->assertForbidden();
    $this->actingAs(userWithRole('admin_company'))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(collect($page->toArray()['props']['navigation'])->pluck('title'))->toContain('เช็ค IP ว่าง'));
});
