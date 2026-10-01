<?php

use App\Modules\Contract\Models\Contract;
use App\Modules\Document\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = userWithRole('admin_company');
    $this->contract = createContract(createCustomer());
});

function pdfUpload(string $name = 'signed.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");
}

it('attaches a file in the tenant folder and lists it', function () {
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/documents", ['attachments' => [pdfUpload()]])
        ->assertSessionHasNoErrors();

    $media = Media::first();
    expect($media->tenant_id)->toBe($this->tenant->id)
        ->and($media->collection_name)->toBe(Contract::DOCUMENTS)
        ->and($media->getPathRelativeToRoot())->toStartWith("tenants/{$this->tenant->id}/");
    Storage::disk('local')->assertExists($media->getPathRelativeToRoot());

    $this->actingAs($this->admin)->get("/contracts/{$this->contract->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.0.name', 'signed.pdf')
            ->where('documents.0.url', route('contract.contracts.documents.show', [$this->contract, $media->id])));
});

it('serves the file only through the contract', function () {
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/documents", ['attachments' => [pdfUpload()]]);
    $media = Media::first();
    $otherContract = createContract(createCustomer());

    $response = $this->actingAs($this->admin)->get("/contracts/{$this->contract->id}/documents/{$media->id}")->assertOk();
    expect($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('content-disposition'))->toContain('inline');

    $this->actingAs($this->admin)->get("/contracts/{$otherContract->id}/documents/{$media->id}")->assertNotFound();
    $this->actingAs(userWithRole('user'))->get("/contracts/{$this->contract->id}/documents/{$media->id}")->assertForbidden();
});

it('takes a scan, rejects other file types and files over 2 MB, and deletes a file', function () {
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/documents", ['attachments' => [UploadedFile::fake()->image('scan.jpg')]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/documents", ['attachments' => [UploadedFile::fake()->create('run.exe', 10)]])
        ->assertSessionHasErrors('attachments.0');
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/documents", ['attachments' => [UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf')]])
        ->assertSessionHasErrors('attachments.0');

    expect(Media::count())->toBe(1);
    $media = Media::first();
    $path = $media->getPathRelativeToRoot();

    $this->actingAs($this->admin)->delete("/contracts/{$this->contract->id}/documents/{$media->id}")->assertSessionHasNoErrors();

    expect(Media::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('does not let a viewer upload or delete', function () {
    $technician = userWithRole('technician');

    $this->actingAs($technician)->post("/contracts/{$this->contract->id}/documents", ['attachments' => [pdfUpload()]])->assertForbidden();
});
