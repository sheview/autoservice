<?php

use App\Modules\Document\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = userWithRole('admin_company');
    $this->customer = createCustomer();
    $this->asset = createAsset(createAssetCategory(), ['customer_id' => $this->customer->id]);
    $this->part = createPart(['code' => 'RAM']);

    $this->photo = fn (string $name = 'photo.jpg') => ['photo' => UploadedFile::fake()->image($name, 640, 480)];
});

// The same four slots on an asset and on a part.
dataset('records', [
    'asset' => [fn () => "/assets/{$this->asset->ulid}"],
    'part' => [fn () => "/parts/{$this->part->id}"],
]);

it('keeps a main photo and three extras, one per slot', function (string $base) {
    $this->actingAs($this->admin)->get($base)->assertInertia(fn (Assert $page) => $page
        ->has('photos', 4)
        ->where('photos.0', ['slot' => 0, 'action' => url("{$base}/photos/0"), 'url' => null])
        ->where('photos.3.slot', 3));

    $this->actingAs($this->admin)->post("{$base}/photos/0", ($this->photo)('front.jpg'))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("{$base}/photos/2", ($this->photo)('back.png'))->assertSessionHasNoErrors();

    $media = Media::orderBy('id')->get();
    expect($media)->toHaveCount(2)
        ->and($media[0]->getCustomProperty('slot'))->toBe(0)
        ->and($media[0]->tenant_id)->toBe($this->tenant->id)
        ->and($media[0]->getPathRelativeToRoot())->toStartWith("tenants/{$this->tenant->id}/");

    $this->actingAs($this->admin)->get($base)->assertInertia(fn (Assert $page) => $page
        ->where('photos.0.url', url("{$base}/photos/0")."?v={$media[0]->id}")
        ->where('photos.1.url', null)
        ->where('photos.2.url', url("{$base}/photos/2")."?v={$media[1]->id}"));

    $this->actingAs($this->admin)->get("{$base}/photos/0")->assertOk()->assertHeader('content-type', 'image/jpeg');
    $this->actingAs($this->admin)->get("{$base}/photos/2")->assertOk()->assertHeader('content-type', 'image/png');
    $this->actingAs($this->admin)->get("{$base}/photos/1")->assertNotFound();
    $this->actingAs($this->admin)->get("{$base}/photos/4")->assertNotFound();
})->with('records');

it('replaces the photo of a slot and deletes it', function (string $base) {
    $this->actingAs($this->admin)->post("{$base}/photos/1", ($this->photo)('old.jpg'));
    $old = Media::sole();

    $this->actingAs($this->admin)->post("{$base}/photos/1", ($this->photo)('new.jpg'))->assertSessionHasNoErrors();
    expect(Media::sole()->only(['name']))->toBe(['name' => 'new'])
        ->and(Media::sole()->id)->not->toBe($old->id);

    $this->actingAs($this->admin)->delete("{$base}/photos/1")->assertSessionHasNoErrors();
    expect(Media::count())->toBe(0);
    // deleting an empty slot is not an error
    $this->actingAs($this->admin)->delete("{$base}/photos/1")->assertSessionHasNoErrors();
})->with('records');

it('accepts images only', function (string $base) {
    $this->actingAs($this->admin)->post("{$base}/photos/0", ['photo' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('photo');
    $this->actingAs($this->admin)->post("{$base}/photos/0", ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
    $this->actingAs($this->admin)->post("{$base}/photos/0", [])->assertSessionHasErrors('photo');
    $this->actingAs($this->admin)->post("{$base}/photos/9", ($this->photo)())->assertNotFound();

    expect(Media::count())->toBe(0);
})->with('records');

it('lets whoever may edit the record change its photos, and whoever may see it look', function () {
    $assetUrl = "/assets/{$this->asset->ulid}/photos/0";
    $partUrl = "/parts/{$this->part->id}/photos/0";
    $this->actingAs($this->admin)->post($assetUrl, ($this->photo)());
    $this->actingAs($this->admin)->post($partUrl, ($this->photo)());

    // the helpdesk edits assets (assets.update); a technician only looks (permissions.json)
    $this->actingAs(userWithRole('helpdesk'))->post("/assets/{$this->asset->ulid}/photos/1", ($this->photo)())->assertSessionHasNoErrors();
    $tech = userWithRole('technician');
    $this->actingAs($tech)->post("/assets/{$this->asset->ulid}/photos/2", ($this->photo)())->assertForbidden();
    $this->actingAs($tech)->get($partUrl)->assertOk();
    $this->actingAs($tech)->post($partUrl, ($this->photo)())->assertForbidden();
    $this->actingAs($tech)->delete($partUrl)->assertForbidden();
    $this->actingAs($tech)->get("/parts/{$this->part->id}")->assertInertia(fn (Assert $page) => $page->where('can.update', false));

    // a customer account sees the photos of its own assets only, and changes nothing
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get($assetUrl)->assertOk();
    $this->actingAs($client)->post($assetUrl, ($this->photo)())->assertForbidden();
    $this->actingAs($client)->delete($assetUrl)->assertForbidden();
    $this->actingAs($client)->get($partUrl)->assertForbidden();
    $otherClient = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    $this->actingAs($otherClient)->get($assetUrl)->assertForbidden();

    expect(Media::count())->toBe(3);
});

it('keeps photos per tenant', function () {
    $this->actingAs($this->admin)->post("/assets/{$this->asset->ulid}/photos/0", ($this->photo)());

    $other = createTenant('other');
    [$theirAsset, $theirPart] = asTenant($other, function () {
        $asset = createAsset(createAssetCategory());
        $part = createPart();
        $asset->addMedia(UploadedFile::fake()->image('theirs.jpg'))->withCustomProperties(['slot' => 0])->toMediaCollection('photos');

        return [$asset, $part];
    });

    $this->actingAs($this->admin)->get("/assets/{$theirAsset->ulid}/photos/0")->assertNotFound();
    $this->actingAs($this->admin)->post("/assets/{$theirAsset->ulid}/photos/0", ($this->photo)())->assertNotFound();
    $this->actingAs($this->admin)->delete("/assets/{$theirAsset->ulid}/photos/0")->assertNotFound();
    $this->actingAs($this->admin)->post("/parts/{$theirPart->id}/photos/0", ($this->photo)())->assertNotFound();

    expect(Media::count())->toBe(1);
    asTenant($other, fn () => expect(Media::sole()->name)->toBe('theirs'));
});
