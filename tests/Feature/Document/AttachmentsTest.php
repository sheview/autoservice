<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Document\Models\Media;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->admin = userWithRole('admin_company');
    $this->category = createAssetCategory();
});

function attachmentPdf(string $name = 'report.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
}

/** A Word or Excel file: a zip archive, as docx and xlsx are. */
function attachmentOffice(string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'office');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->close();

    return new UploadedFile($path, $name, null, null, true);
}

it('attaches Word, Excel and PDF files when an asset is saved', function () {
    $this->actingAs($this->admin)->post('/assets', [
        'category_id' => $this->category->id, 'name' => 'Notebook', 'status' => 'in_use', 'owner' => 'company', 'location' => 'Stock',
        'serials' => ['A', 'B'],
        'attachments' => [attachmentPdf('invoice.pdf'), attachmentOffice('spec.docx'), attachmentOffice('list.xlsx')],
    ])->assertSessionHasNoErrors();

    $assets = Asset::orderBy('asset_code')->get();
    expect($assets)->toHaveCount(1)
        ->and($assets[0]->getMedia('attachments')->pluck('file_name')->sort()->values()->all())->toBe(['invoice.pdf', 'list.xlsx', 'spec.docx']);

    $this->actingAs($this->admin)->get("/assets/{$assets[0]->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('attachments', 3)->where('attachments.0.name', 'invoice.pdf'));

    // more files on edit; an edit without files keeps them
    $this->actingAs($this->admin)->post("/assets/{$assets[0]->ulid}", [
        '_method' => 'put', 'category_id' => $this->category->id, 'name' => 'Notebook', 'status' => 'in_use', 'owner' => 'company', 'location' => 'Stock',
        'attachments' => [attachmentPdf('warranty.pdf')],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put("/assets/{$assets[0]->ulid}", ['category_id' => $this->category->id, 'name' => 'Notebook', 'status' => 'in_use', 'owner' => 'company', 'location' => 'Stock'])
        ->assertSessionHasNoErrors();

    expect($assets[0]->fresh()->getMedia('attachments'))->toHaveCount(4);
});

it('refuses other types, files over 2 MB and too many files', function () {
    $base = ['category_id' => $this->category->id, 'name' => 'X', 'status' => 'in_use', 'owner' => 'company', 'location' => 'Stock'];

    // A program renamed to .pdf: a real file, so its type is read from the content (a fake file
    // would take it from the name).
    $program = tempnam(sys_get_temp_dir(), 'exe');
    file_put_contents($program, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 48).'PE');

    $this->actingAs($this->admin)->post('/assets', [...$base, 'attachments' => [
        UploadedFile::fake()->image('photo.jpg'),                           // pictures are for contracts only
        new UploadedFile($program, 'virus.pdf', null, null, true),         // not really a PDF
        UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf'),   // over 2 MB
    ]])->assertSessionHasErrors(['attachments.0', 'attachments.1', 'attachments.2']);

    $this->actingAs($this->admin)->post('/assets', [...$base, 'attachments' => array_map(fn () => attachmentPdf(), range(1, 11))])
        ->assertSessionHasErrors('attachments');

    expect(Asset::count())->toBe(0)->and(Media::count())->toBe(0);
});

it('lets whoever may see an asset open its files, and only editors add or delete them', function () {
    $asset = createAsset($this->category);
    $this->actingAs($this->admin)->post("/assets/{$asset->ulid}/attachments", ['attachments' => [attachmentPdf(), attachmentOffice('quote.xlsx')]])
        ->assertSessionHasNoErrors();
    [$pdf, $excel] = $asset->fresh()->getMedia('attachments')->all();

    $viewer = userWithRole('user');
    $this->actingAs($viewer)->get("/assets/{$asset->ulid}/attachments/{$pdf->id}")->assertOk()->assertHeader('content-disposition', 'inline; filename=report.pdf');
    // Word and Excel are downloaded, not opened in the browser
    expect($this->actingAs($viewer)->get("/assets/{$asset->ulid}/attachments/{$excel->id}")->headers->get('content-disposition'))->toStartWith('attachment');

    $this->actingAs($viewer)->post("/assets/{$asset->ulid}/attachments", ['attachments' => [attachmentPdf()]])->assertForbidden();
    $this->actingAs($viewer)->delete("/assets/{$asset->ulid}/attachments/{$pdf->id}")->assertForbidden();

    $this->actingAs($this->admin)->delete("/assets/{$asset->ulid}/attachments/{$pdf->id}")->assertSessionHasNoErrors();
    expect($asset->fresh()->getMedia('attachments'))->toHaveCount(1);

    // a file of another asset is not found through this one
    $other = createAsset($this->category);
    $this->actingAs($this->admin)->get("/assets/{$other->ulid}/attachments/{$excel->id}")->assertNotFound();
});

it('attaches files to a ticket when it is opened, and lets the customer add more', function () {
    $customer = createCustomer();
    $client = userWithRole('customer', ['customer_id' => $customer->id]);

    $device = ['device_name' => 'Printer', 'device_brand' => 'HP', 'device_model' => 'M404', 'device_serial_unknown' => true];
    $this->actingAs($client)->post('/tickets', ['title' => 'Printer', 'priority' => 'low', 'source' => 'portal', ...$device, 'attachments' => [attachmentOffice('error-log.docx')]])
        ->assertSessionHasNoErrors();
    $ticket = Ticket::sole();
    expect($ticket->getMedia('attachments')->pluck('file_name')->all())->toBe(['error-log.docx']);

    $this->actingAs($client)->post("/tickets/{$ticket->ulid}/attachments", ['attachments' => [attachmentPdf('photo-report.pdf')]])->assertSessionHasNoErrors();
    $this->actingAs($client)->get("/tickets/{$ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('attachments', 2)
        ->where('can.deleteAttachments', false));

    // staff may delete; the customer may not
    $media = $ticket->fresh()->getMedia('attachments')->first();
    $this->actingAs($client)->delete("/tickets/{$ticket->ulid}/attachments/{$media->id}")->assertForbidden();
    $this->actingAs($this->admin)->delete("/tickets/{$ticket->ulid}/attachments/{$media->id}")->assertSessionHasNoErrors();

    // edit form: more files
    $this->actingAs($this->admin)->post("/tickets/{$ticket->ulid}", [
        '_method' => 'put', 'title' => 'Printer', 'priority' => 'low', 'source' => 'portal', 'attachments' => [attachmentPdf('quote.pdf')],
    ])->assertSessionHasNoErrors();
    expect($ticket->fresh()->getMedia('attachments')->pluck('file_name')->sort()->values()->all())->toBe(['photo-report.pdf', 'quote.pdf']);
});

it('attaches files to a customer and a contract from their forms', function () {
    $this->actingAs($this->admin)->post('/customers', ['code' => 'C1', 'name' => 'Acme', 'attachments' => [attachmentPdf('vat.pdf')]])
        ->assertSessionHasNoErrors();
    $customer = Customer::sole();
    expect($customer->getMedia('attachments')->pluck('file_name')->all())->toBe(['vat.pdf']);

    $this->actingAs($this->admin)->get("/customers/{$customer->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('attachments.0.name', 'vat.pdf')
        ->where('attachments.0.url', route('contract.customers.attachments.show', [$customer, $customer->getMedia('attachments')->first()->id])));

    $this->actingAs($this->admin)->post('/contracts', [
        'customer_id' => $customer->id, 'contract_no' => 'MA-1', 'title' => 'MA', 'status' => 'active',
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'service_window' => '8x5', 'notify_days_before' => 60,
        'attachments' => [attachmentOffice('scope.docx'), UploadedFile::fake()->image('signed.png')],
    ])->assertSessionHasNoErrors();

    expect(Contract::sole()->getMedia(Contract::DOCUMENTS)->pluck('file_name')->sort()->values()->all())->toBe(['scope.docx', 'signed.png']);
});

it('keeps attachments per tenant', function () {
    $asset = createAsset($this->category);
    $this->actingAs($this->admin)->post("/assets/{$asset->ulid}/attachments", ['attachments' => [attachmentPdf()]]);
    $media = $asset->fresh()->getMedia('attachments')->first();

    $other = createTenant('other');
    $otherAdmin = userWithRole('admin_company', [], $other);
    $theirAsset = asTenant($other, fn () => createAsset(createAssetCategory()));

    // their own asset, our file id: not found; our asset: not found for them
    $this->actingAs($otherAdmin)->get("/assets/{$theirAsset->ulid}/attachments/{$media->id}")->assertNotFound();
    $this->actingAs($otherAdmin)->get("/assets/{$asset->ulid}/attachments/{$media->id}")->assertNotFound();
});
