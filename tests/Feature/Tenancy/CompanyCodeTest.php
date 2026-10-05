<?php

use App\Modules\Service\Support\TicketNumber;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => CompanyCodes::forget());

it('gives every company the next code, never twice, and never to the platform', function () {
    expect($this->tenant->company_code)->toBe('001');

    $second = createTenant('second');
    $gone = createTenant('gone');
    expect([$second->company_code, $gone->company_code])->toBe(['002', '003']);

    // A deleted company still keeps its code taken (company_codes never forgets one).
    $gone->delete();
    expect(createTenant('next')->company_code)->toBe('004')
        ->and(DB::table('company_codes')->pluck('code')->all())->toBe(['001', '002', '003', '004']);

    $platform = Tenant::create(['name' => 'Platform', 'slug' => 'platform', 'subdomain' => 'admin', 'is_platform' => true]);
    expect($platform->company_code)->toBeNull();
});

it('never changes a company code', function () {
    $tenant = createTenant('second');
    $tenant->company_code = '099';
    expect(fn () => $tenant->save())->toThrow(LogicException::class);

    // Not from the platform's company form either.
    $superadmin = createSuperadmin();
    $this->actingAs($superadmin)->put("/platform/tenants/{$tenant->ulid}", [
        'name' => 'Second Co', 'subdomain' => 'second', 'status' => 'active', 'company_code' => '777',
    ]);
    expect($tenant->fresh()->company_code)->toBe('002');
});

it('turns ticket numbers into the shown form and back', function () {
    expect(TicketNumber::format('TK-2569-00001', '001'))->toBe('TK001-2569-00001')
        ->and(TicketNumber::format('TK-2569-00001', '1234'))->toBe('TK1234-2569-00001')
        ->and(TicketNumber::format('TK-2569-00001', null))->toBe('TK-2569-00001')
        ->and(TicketNumber::parse('  tk001-2569-00001 '))->toBe(['company_code' => '001', 'ticket_no' => 'TK-2569-00001'])
        ->and(TicketNumber::parse('TK-2569-00001'))->toBe(['company_code' => null, 'ticket_no' => 'TK-2569-00001'])
        ->and(TicketNumber::parse('TK01-2569-00001'))->toBeNull()
        ->and(TicketNumber::parse('hello'))->toBeNull()
        ->and(TicketNumber::parse(TicketNumber::format('TK-2570-12345', '002')))->toBe(['company_code' => '002', 'ticket_no' => 'TK-2570-12345']);
});

it('finds the company of a public page in one place, the request first', function () {
    $second = createTenant('second');
    $resolver = fn () => app(PublicTenant::class);
    $context = app(TenantContext::class);

    // No company of the request (the central address, nobody signed in).
    $context->forget();
    expect($resolver()->known())->toBeFalse()
        ->and($resolver()->resolve('SECOND ')?->id)->toBe($second->id)
        ->and($resolver()->resolve('2')?->id)->toBe($second->id)
        ->and($resolver()->resolve('002')?->id)->toBe($second->id)
        ->and($resolver()->resolve(null, '001')?->id)->toBe($this->tenant->id)
        ->and($resolver()->resolve('nobody'))->toBeNull()
        ->and($resolver()->resolve('admin'))->toBeNull();

    // The request's company (subdomain or signed-in user) always wins over anything typed.
    $context->set($this->tenant);
    expect($resolver()->known())->toBeTrue()
        ->and($resolver()->resolve('second')?->id)->toBe($this->tenant->id)
        ->and($resolver()->resolve(null, '002')?->id)->toBe($this->tenant->id);

    // A suspended company is no company here.
    $context->forget();
    $second->update(['status' => Tenant::STATUS_SUSPENDED]);
    expect($resolver()->resolve('second'))->toBeNull();
});
