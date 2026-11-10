<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Contract\Models\ContractAsset;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-02-10 10:00');

    $this->tech = userWithRole('technician');
    $this->admin = userWithRole('admin_company');
    $this->customer = createCustomer();
    $this->contract = createContract($this->customer);
    $this->ups = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    $this->upsForm = createPmTemplate(['code' => 'UPS', 'name' => 'ฟอร์ม UPS', 'asset_category_id' => $this->ups->id, 'items' => [
        ['key' => 'battery', 'label' => 'แบตเตอรี่', 'type' => 'pass_fail'],
    ]]);
    $this->onVisit = createAsset($this->ups, ['customer_id' => $this->customer->id, 'name' => 'UPS ชั้น 2', 'brand' => 'APC', 'model' => 'SRT3000', 'location' => 'อาคาร A', 'floor' => '2', 'room' => 'Server']);
    // in the customer's register but not on the work order
    $this->spare = createAsset($this->ups, ['customer_id' => $this->customer->id, 'name' => 'UPS ห้องประชุม', 'serial_number' => 'SN-SPARE-01']);

    $this->plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $this->contract->id, 'title' => 'PM UPS', 'interval_months' => 6]);
    $this->visit = PmVisit::create([
        'customer_id' => $this->customer->id, 'contract_id' => $this->contract->id, 'pm_plan_id' => $this->plan->id,
        'visit_no' => 'PM-0001', 'title' => 'PM UPS', 'due_on' => '2026-02-28', 'status' => PmVisit::STATUS_SCHEDULED,
        'assignee_id' => $this->tech->id,
    ]);
    $this->visit->items()->create(['asset_id' => $this->onVisit->id]);
    $this->url = "/pm-visits/{$this->visit->ulid}/site-equipment";
    $this->new = fn (array $data = []) => $this->actingAs($this->tech)->post($this->url, $data + [
        'category_id' => $this->ups->id, 'brand' => 'APC', 'model' => 'SRT3000', 'serial_number' => 'SN-NEW-01',
        'location' => 'อาคาร A', 'floor' => '3', 'room' => 'IT',
    ]);
});

it('finds equipment of the customer by what is read off it, and puts it on the work order', function () {
    $other = createAsset($this->ups, ['customer_id' => createCustomer()->id, 'serial_number' => 'SN-SPARE-02']);

    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=sn-spare")
        ->assertOk()
        ->assertJsonPath('found', fn ($found) => collect($found)->pluck('id')->all() === [$this->spare->id]);
    // what is on it already is not offered again
    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=".urlencode($this->onVisit->asset_code))->assertJsonPath('found', []);

    $this->actingAs($this->tech)->post("{$this->url}/existing", ['asset_ids' => [$this->spare->id]])->assertSessionHasNoErrors();
    expect($this->visit->items()->pluck('asset_id')->all())->toBe([$this->onVisit->id, $this->spare->id]);

    // another customer's: refused
    $this->actingAs($this->tech)->post("{$this->url}/existing", ['asset_ids' => [$other->id]])->assertSessionHasErrors('asset_ids');
});

it('registers new equipment from the work order with the short form, into the contract and plan, waiting for review', function () {
    ($this->new)(['photo' => UploadedFile::fake()->image('label.jpg')])->assertSessionHasNoErrors()->assertSessionHas('success');

    $asset = Asset::where('serial_number', 'SN-NEW-01')->sole();
    expect($asset->only(['customer_id', 'category_id', 'name', 'brand', 'model', 'location', 'floor', 'room', 'needs_review']))->toBe([
        'customer_id' => $this->customer->id, 'category_id' => $this->ups->id, 'name' => 'APC SRT3000', 'brand' => 'APC', 'model' => 'SRT3000',
        'location' => 'อาคาร A', 'floor' => '3', 'room' => 'IT', 'needs_review' => true,
    ])
        ->and($asset->asset_code)->toStartWith('UPS')
        ->and($asset->added_note)->toContain('PM-0001')->toContain($this->tech->name)
        ->and($asset->serials()->pluck('serial_number')->all())->toBe(['SN-NEW-01'])
        ->and(Media::where('model_type', $asset->getMorphClass())->where('model_id', $asset->id)->count())->toBe(1);

    $item = $this->visit->items()->where('asset_id', $asset->id)->sole();
    expect($item->template_code)->toBe('UPS')
        ->and(ContractAsset::where('contract_id', $this->contract->id)->where('asset_id', $asset->id)->exists())->toBeTrue()
        // the plan covers its whole contract: still no list, so what joins the contract later is in it too
        ->and(PmPlanAsset::where('pm_plan_id', $this->plan->id)->exists())->toBeFalse();
    expect(PmVisitEvent::where('pm_visit_id', $this->visit->id)->where('type', PmVisitEvent::TYPE_ITEM_ADDED)->exists())->toBeTrue();
});

it('saves units of the same model one after another, with a form picked by hand, and keeps them off the plan when asked', function () {
    createPmTemplate(['code' => 'UPS-L', 'name' => 'ฟอร์ม UPS ใหญ่', 'items' => [['key' => 'load', 'label' => 'โหลด', 'type' => 'number']]]);

    ($this->new)(['template_code' => 'UPS-L', 'add_to_plan' => false])->assertSessionHasNoErrors();
    ($this->new)(['serial_number' => 'SN-NEW-02', 'template_code' => 'UPS-L', 'add_to_plan' => false])->assertSessionHasNoErrors();

    $assets = Asset::whereIn('serial_number', ['SN-NEW-01', 'SN-NEW-02'])->get();
    expect($assets)->toHaveCount(2)
        ->and($this->visit->items()->whereIn('asset_id', $assets->modelKeys())->pluck('template_code')->unique()->all())->toBe(['UPS-L'])
        ->and(ContractAsset::whereIn('asset_id', $assets->modelKeys())->exists())->toBeFalse()
        ->and(PmPlanAsset::whereIn('asset_id', $assets->modelKeys())->exists())->toBeFalse();
});

it('keeps a plan covering its whole contract without a list, a form picked going on the asset', function () {
    ($this->new)(['template_code' => 'UPS'])->assertSessionHasNoErrors();
    $asset = Asset::where('serial_number', 'SN-NEW-01')->sole();

    expect(PmPlanAsset::where('pm_plan_id', $this->plan->id)->exists())->toBeFalse()
        ->and(PmTemplateAssignment::where('asset_id', $asset->id)->value('template_code'))->toBe('UPS');

    // equipment that joins the contract later is still in the plan
    $later = createAsset($this->ups, ['customer_id' => $this->customer->id]);
    ContractAsset::create(['contract_id' => $this->contract->id, 'asset_id' => $later->id]);
    expect(PmPlanAsset::where('pm_plan_id', $this->plan->id)->exists())->toBeFalse();
});

it('lists a plan only for equipment outside its contract', function () {
    $other = createContract($this->customer);
    $this->visit->update(['contract_id' => $other->id]);
    ($this->new)()->assertSessionHasNoErrors();
    $asset = Asset::where('serial_number', 'SN-NEW-01')->sole();

    expect(PmPlanAsset::where('pm_plan_id', $this->plan->id)->pluck('asset_id')->sort()->values()->all())
        ->toBe(collect(ContractAsset::where('contract_id', $this->contract->id)->pluck('asset_id'))->push($asset->id)->sort()->values()->all());
});

it('takes what is typed literally, the exact match first', function () {
    foreach (range(1, 25) as $n) {
        createAsset($this->ups, ['customer_id' => $this->customer->id, 'serial_number' => sprintf('AB%02dCD', $n)]);
    }
    $exact = createAsset($this->ups, ['customer_id' => $this->customer->id, 'serial_number' => 'AB_CD']);

    // "_" is not a wildcard: only the serial that has it
    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=AB_CD")
        ->assertJsonPath('found', fn ($found) => collect($found)->pluck('id')->all() === [$exact->id]);
    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=%25")->assertJsonPath('found', []);
    // more matches than the limit: the one scanned exactly still comes first
    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=AB25CD")->assertJsonPath('found.0.serial_number', 'AB25CD');
    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=CD")->assertJsonCount(20, 'found');
});

it('offers someone limited to a branch only the equipment of their branch (or of none)', function () {
    $mine = Branch::create(['code' => 'MINE', 'name' => 'สาขาเรา']);
    $theirs = Branch::create(['code' => 'THEIRS', 'name' => 'สาขาอื่น']);
    $staff = userWithRole('helpdesk', ['branch_id' => $mine->id]);
    setRoleScope('helpdesk', PermissionCatalog::SCOPE_BRANCH);
    $this->visit->update(['assignee_id' => $staff->id]);
    $here = createAsset($this->ups, ['customer_id' => $this->customer->id, 'branch_id' => $mine->id, 'serial_number' => 'SN-BR-1']);
    $there = createAsset($this->ups, ['customer_id' => $this->customer->id, 'branch_id' => $theirs->id, 'serial_number' => 'SN-BR-2']);

    $this->actingAs($staff)->getJson("{$this->url}/search?q=SN-BR")
        ->assertJsonPath('found', fn ($found) => collect($found)->pluck('id')->all() === [$here->id]);
    $this->actingAs($staff)->post("{$this->url}/existing", ['asset_ids' => [$there->id]])->assertSessionHasErrors('asset_ids');
    $this->actingAs($staff)->post("{$this->url}/existing", ['asset_ids' => [$here->id]])->assertSessionHasNoErrors();
    expect($this->visit->items()->pluck('asset_id')->all())->toBe([$this->onVisit->id, $here->id]);
});

it('refuses the same device twice and a form that is not published, keeping nothing', function () {
    ($this->new)(['serial_number' => 'sn-spare-01'])->assertSessionHasErrors('serial_number');
    $this->spare->update(['property_no' => 'P-100']);
    ($this->new)(['property_no' => 'P-100'])->assertSessionHasErrors('property_no');
    ($this->new)(['template_code' => 'NOPE'])->assertSessionHasErrors('template_code');
    ($this->new)(['category_id' => null])->assertSessionHasErrors('category_id');

    expect(Asset::where('needs_review', true)->exists())->toBeFalse()
        ->and($this->visit->items()->count())->toBe(1);
});

it('is for who works on the work order, while it is still to do', function () {
    $stranger = userWithRole('technician');
    $this->actingAs($stranger)->getJson("{$this->url}/options")->assertForbidden();
    $this->actingAs($stranger)->getJson("{$this->url}/search?q=UPS")->assertForbidden();
    $this->actingAs($stranger)->post($this->url, ['category_id' => $this->ups->id])->assertForbidden();

    $this->actingAs($this->tech)->getJson("{$this->url}/options")
        ->assertOk()
        ->assertJsonPath("categoryTemplates.{$this->ups->id}", 'UPS')
        ->assertJsonPath('copyFrom.0.model', 'SRT3000')
        ->assertJsonPath('hasPlan', true);
    $this->actingAs($this->tech)->get("/pm-work/{$this->visit->ulid}")->assertInertia(fn (Assert $page) => $page->where('visit.open', true));

    $this->visit->update(['status' => PmVisit::STATUS_CANCELLED]);
    ($this->new)()->assertSessionHasErrors('visit');
    expect(Asset::where('serial_number', 'SN-NEW-01')->exists())->toBeFalse();
});

it('lists equipment registered on site for the office to confirm', function () {
    ($this->new)()->assertSessionHasNoErrors();
    $asset = Asset::where('serial_number', 'SN-NEW-01')->sole();

    $this->actingAs($this->admin)->get('/assets?needs_review=1')->assertInertia(fn (Assert $page) => $page
        ->where('stats.needs_review', 1)
        ->where('assets.data', fn ($rows) => collect($rows)->pluck('ulid')->all() === [$asset->ulid])
        ->where('assets.data.0.needs_review', true));
    $this->actingAs($this->admin)->get("/assets/{$asset->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('asset.needs_review', true)
        ->where('asset.added_note', fn ($note) => str_contains($note, 'PM-0001')));

    // the technician may not confirm it; the office may
    $this->actingAs($this->tech)->put("/assets/{$asset->ulid}/review")->assertForbidden();
    $this->actingAs($this->admin)->put("/assets/{$asset->ulid}/review")->assertSessionHasNoErrors();
    expect($asset->fresh()->needs_review)->toBeFalse()
        ->and($asset->fresh()->added_note)->toContain('PM-0001');
    $this->actingAs($this->admin)->get('/assets?needs_review=1')->assertInertia(fn (Assert $page) => $page->where('assets.data', []));
});

it('does not reach a work order, equipment or a category of another tenant', function () {
    $other = createTenant('other');
    [$theirVisit, $theirAsset, $theirCategory] = asTenant($other, function () {
        $customer = createCustomer();
        $category = createAssetCategory(['name' => 'X', 'code_prefix' => 'X']);
        $asset = createAsset($category, ['customer_id' => $customer->id, 'serial_number' => 'SN-SPARE-THEIRS']);
        $visit = PmVisit::create(['customer_id' => $customer->id, 'visit_no' => 'PM-X', 'title' => 'X', 'due_on' => '2026-02-28', 'status' => PmVisit::STATUS_SCHEDULED]);

        return [$visit, $asset, $category];
    });

    $this->actingAs($this->tech)->getJson("/pm-visits/{$theirVisit->ulid}/site-equipment/options")->assertNotFound();
    $this->actingAs($this->tech)->post("/pm-visits/{$theirVisit->ulid}/site-equipment", ['category_id' => $this->ups->id])->assertNotFound();
    $this->actingAs($this->tech)->getJson("{$this->url}/search?q=SN-SPARE")
        ->assertJsonPath('found', fn ($found) => collect($found)->pluck('id')->all() === [$this->spare->id]);
    $this->actingAs($this->tech)->post("{$this->url}/existing", ['asset_ids' => [$theirAsset->id]])->assertSessionHasErrors('asset_ids');
    ($this->new)(['category_id' => $theirCategory->id])->assertSessionHasErrors('category_id');
    // their serial number is theirs: it does not block ours
    ($this->new)(['serial_number' => 'SN-SPARE-THEIRS'])->assertSessionHasNoErrors();

    expect(asTenant($other, fn () => Asset::count()))->toBe(1);
});
