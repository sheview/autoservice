<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Document\Models\Media;
use App\Modules\Document\Support\PhotoSlots;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->tech = userWithRole('technician');
    $customer = createCustomer();
    $contract = createContract($customer);
    $this->asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
    $contract->contractAssets()->create(['asset_id' => $this->asset->id]);
    createPmTemplate(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'check']]]);

    $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->visit = app(StartPmVisit::class)->handle($plan->visits()->orderBy('round')->first(), $this->tech);
    $this->item = $this->visit->items()->first();
    $this->url = "/pm-visits/{$this->visit->ulid}/items/{$this->item->id}/photos";
});

$reportPhotos = fn () => Media::where('model_type', (new PmVisitItem)->getMorphClass())->count();
$assetPhotos = fn () => Media::where('model_type', (new Asset)->getMorphClass())->count();

it('puts a site photo in the PM report by default', function () use ($reportPhotos, $assetPhotos) {
    $this->actingAs($this->tech)->post($this->url, ['photo' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasNoErrors();

    expect($reportPhotos())->toBe(1)->and($assetPhotos())->toBe(0);
});

it('puts a site photo in the asset register only, in its first empty slot', function () use ($reportPhotos) {
    $this->actingAs($this->tech)->post($this->url, ['photo' => UploadedFile::fake()->image('a.jpg'), 'target' => 'asset'])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post($this->url, ['photo' => UploadedFile::fake()->image('b.jpg'), 'target' => 'asset'])->assertSessionHasNoErrors();

    $asset = $this->asset->fresh();
    expect($reportPhotos())->toBe(0)
        ->and(PhotoSlots::find($asset, 0)?->file_name)->toBe('a.jpg')
        ->and(PhotoSlots::find($asset, 1)?->file_name)->toBe('b.jpg');
    $this->actingAs($this->tech)->get("/assets/{$asset->ulid}/photos/0")->assertOk();
});

it('puts a site photo, of a question too, in both places', function () use ($reportPhotos) {
    $this->actingAs($this->tech)->post($this->url, ['photo' => UploadedFile::fake()->image('both.jpg'), 'question' => 'look', 'target' => 'both'])
        ->assertSessionHasNoErrors();

    $report = Media::where('model_type', (new PmVisitItem)->getMorphClass())->sole();
    $assetPhoto = PhotoSlots::find($this->asset->fresh(), 0);
    expect($reportPhotos())->toBe(1)
        ->and($report->getCustomProperty('question'))->toBe('look')
        ->and($assetPhoto?->file_name)->toBe('both.jpg')
        ->and(Storage::disk($assetPhoto->disk)->exists($assetPhoto->getPathRelativeToRoot()))->toBeTrue()
        ->and(Storage::disk($report->disk)->exists($report->getPathRelativeToRoot()))->toBeTrue();
});

it('saves the photo nowhere when the asset has no empty slot, and refuses unknown targets', function () use ($reportPhotos) {
    foreach (range(0, PhotoSlots::COUNT - 1) as $slot) {
        $this->asset->addMedia(UploadedFile::fake()->image("s{$slot}.jpg"))->withCustomProperties(['slot' => $slot])->toMediaCollection(PhotoSlots::COLLECTION);
    }

    $this->actingAs($this->tech)->post($this->url, ['photo' => UploadedFile::fake()->image('x.jpg'), 'target' => 'both'])->assertSessionHasErrors('photo');
    $this->actingAs($this->tech)->post($this->url, ['photo' => UploadedFile::fake()->image('x.jpg'), 'target' => 'elsewhere'])->assertSessionHasErrors('target');

    expect($reportPhotos())->toBe(0)
        ->and($this->asset->fresh()->getMedia(PhotoSlots::COLLECTION)->count())->toBe(PhotoSlots::COUNT);
});

it('does not add a photo to an asset of another tenant', function () use ($assetPhotos) {
    [$visitUlid, $itemId] = asTenant(createTenant('other'), function () {
        $tech = userWithRole('technician');
        $customer = createCustomer();
        $contract = createContract($customer);
        $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
        $contract->contractAssets()->create(['asset_id' => $asset->id]);
        $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6, 'assignee_id' => $tech->id]);

        createPmTemplate(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'check']]]);
        $visit = app(StartPmVisit::class)->handle($plan->visits()->first(), $tech);

        return [$visit->ulid, $visit->items()->first()->id];
    });

    $this->actingAs($this->tech)->post("/pm-visits/{$visitUlid}/items/{$itemId}/photos", ['photo' => UploadedFile::fake()->image('x.jpg'), 'target' => 'asset'])
        ->assertNotFound();
    expect($assetPhotos())->toBe(0);
});
