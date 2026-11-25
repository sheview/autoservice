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

it('has a work order to practise changing a form and adding equipment found on site', function () {
    $this->seed(SampleCompanySeeder::class);

    asTenant(sampleCompany(), function () {
        $visit = PmVisit::query()->where('title', SampleCompanySeeder::PM_TRAINING_TITLE)->sole();
        $assets = Asset::query()->whereIn('id', $visit->items()->pluck('asset_id'))->get()->keyBy('serial_number');
        $formOf = fn (string $serial) => $visit->items()->where('asset_id', $assets[$serial]->id)->value('template_code');

        expect($visit->status)->toBe(PmVisit::STATUS_IN_PROGRESS)
            ->and($assets->keys()->sort()->values()->all())->toBe(['TRAIN-SN-NEW', 'TRAIN-SN-UPS', 'TRAIN-SN-WRONG'])
            // the UPS under the battery category got another form than the real UPS
            ->and($formOf('TRAIN-SN-WRONG'))->not->toBe($formOf('TRAIN-SN-UPS'))
            ->and($assets['TRAIN-SN-NEW']->needs_review)->toBeTrue()
            ->and(Asset::query()->where('serial_number', SampleCompanySeeder::PM_TRAINING_SERIAL)->where('customer_id', $visit->customer_id)->exists())->toBeTrue();
    });

    // seeding again adds it no second time
    $this->seed(SampleCompanySeeder::class);
    expect(asTenant(sampleCompany(), fn () => PmVisit::query()->where('title', SampleCompanySeeder::PM_TRAINING_TITLE)->count()))->toBe(1);
});

it('has the MOTS project: its equipment on the MOTS forms and the first round approved, added once', function () {
    $this->seed(SampleCompanySeeder::class);

    $check = function () {
        asTenant(sampleCompany(), function () {
            $visit = PmVisit::query()->where('title', SampleCompanySeeder::MOTS_TITLE)->sole();
            $items = $visit->items()->get()->keyBy(fn ($item) => Asset::query()->find($item->asset_id)->serial_number);
            $answers = fn (string $serial) => $items[$serial]->answers;

            expect($visit->status)->toBe(PmVisit::STATUS_APPROVED)
                ->and(Contract::query()->find($visit->contract_id)->contract_no)->toBe('จข.2/2568')
                ->and($items['FG4H0FT924906374']->template_code)->toBe('MOTS-FW')
                ->and($items['ACV442W033']->template_code)->toBe('MOTS-STORAGE')
                ->and($items['SGHD2FLXGW']->template_code)->toBe('MOTS-SERVER')
                ->and($items['250445403990001']->template_code)->toBe('MOTS-UPS')
                // the details from the register's spec fields, and the words of the MOTS forms
                ->and($answers('FG4H0FT924906374')['hostname'])->toBe('MOTS-FW-02')
                ->and($answers('ACV442W033')['controller'])->toBe('2 Controllers (A,B)')
                ->and($answers('SGHD2FLXGW')['raid_controller'])->toBe('HPE MR408i-o Gen11')
                // not in the register: typed on site
                ->and($answers('SGHD2FLXGX')['hostname'])->toBe('MOTS-SRV04')
                ->and(collect($items['SGHD2FLXGW']->checklist)->firstWhere('key', 'cpu')['labels']['pass'])->toBe('เรียบร้อย')
                ->and($items->every(fn ($item) => $item->result === 'passed'))->toBeTrue();
        });
    };
    $check();

    // seeding again (every deploy) adds it no second time
    $this->seed(SampleCompanySeeder::class);
    expect(asTenant(sampleCompany(), fn () => PmVisit::query()->where('title', SampleCompanySeeder::MOTS_TITLE)->count()))->toBe(1);
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
            ->and(CustomerSite::query()->count())->toBe(11) // with the MOTS data center
            ->and(CustomerSite::query()->where('name', 'สาขาพัทยา')->value('province'))->toBe('ชลบุรี');
    });
});
