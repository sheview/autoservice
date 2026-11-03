<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\SampleCompanySeeder;

function sampleCompany(): Tenant
{
    return Tenant::query()->where('subdomain', SampleCompanySeeder::SUBDOMAIN)->firstOrFail();
}

it('creates the sample company with every status of every workflow', function () {
    $this->seed(SampleCompanySeeder::class);

    $tenant = sampleCompany();
    expect($tenant->name)->toBe(SampleCompanySeeder::NAME);

    asTenant($tenant, function () {
        $statuses = fn (string $model) => $model::query()->distinct()->pluck('status')->sort()->values()->all();

        expect($statuses(Ticket::class))->toEqualCanonicalizing(Ticket::STATUSES)
            ->and($statuses(PmVisit::class))->toEqualCanonicalizing(PmVisit::STATUSES)
            ->and($statuses(PurchaseRequest::class))->toEqualCanonicalizing(PurchaseRequest::STATUSES)
            ->and($statuses(CheckoutRequest::class))->toEqualCanonicalizing(CheckoutRequest::STATUSES)
            ->and($statuses(RoomAccessRequest::class))->toEqualCanonicalizing(RoomAccessRequest::STATUSES)
            ->and($statuses(Contract::class))->toEqualCanonicalizing(Contract::STATUSES)
            ->and($statuses(Asset::class))->toEqualCanonicalizing(Asset::STATUSES);

        expect(User::query()->where('email', 'admin@sample.test')->first()->hasRole('admin_company'))->toBeTrue();
    });
});

it('adds nothing when the sample company already exists', function () {
    $this->seed(SampleCompanySeeder::class);
    $tickets = asTenant(sampleCompany(), fn () => Ticket::query()->count());

    $this->seed(SampleCompanySeeder::class);

    expect(Tenant::query()->where('subdomain', SampleCompanySeeder::SUBDOMAIN)->count())->toBe(1)
        ->and(asTenant(sampleCompany(), fn () => Ticket::query()->count()))->toBe($tickets);
});

it('puts nothing into other companies', function () {
    $other = createTenant('other');

    $this->seed(SampleCompanySeeder::class);

    asTenant($other, function () {
        expect(Ticket::query()->count())->toBe(0)
            ->and(Asset::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0);
    });
});

it('puts the branches and customer sites on the map, also for a sample company made before', function () {
    $this->seed(SampleCompanySeeder::class);

    // made before places existed: nothing placed
    asTenant(sampleCompany(), function () {
        Branch::query()->update(['lat' => null, 'lng' => null]);
        CustomerSite::query()->update(['lat' => null, 'lng' => null]);
        CustomerSite::query()->where('name', 'สาขาพัทยา')->delete();
    });
    $this->seed(SampleCompanySeeder::class);

    asTenant(sampleCompany(), function () {
        expect(Branch::query()->whereNull('lat')->count())->toBe(0)
            ->and(CustomerSite::query()->whereNull('lat')->count())->toBe(0)
            ->and(CustomerSite::query()->count())->toBe(10)
            ->and(CustomerSite::query()->where('name', 'สาขาพัทยา')->value('province'))->toBe('ชลบุรี');
    });
});
