<?php

use App\Modules\Document\Models\Manual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician');
    $this->pdf = fn (string $name = 'manual.pdf', int $kb = 10) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $kb * 1024)."\n%%EOF");
});

it('lets the admin add a manual with links and files', function () {
    $this->actingAs($this->admin)->post('/manuals', [
        'title' => 'ตั้งค่า Switch Cisco',
        'category' => 'Network',
        'description' => 'ขั้นตอนตั้งค่า VLAN',
        'links' => [['label' => 'Cisco docs', 'url' => 'https://www.cisco.com/c/en/us/support'], ['label' => '', 'url' => '']],
        'attachments' => [($this->pdf)()],
    ])->assertSessionHasNoErrors();

    $manual = Manual::sole();
    expect($manual->only(['title', 'category', 'created_by_name']))->toBe(['title' => 'ตั้งค่า Switch Cisco', 'category' => 'Network', 'created_by_name' => 'Admin Boss'])
        ->and($manual->links)->toEqual([['label' => 'Cisco docs', 'url' => 'https://www.cisco.com/c/en/us/support']])
        ->and($manual->getMedia('attachments'))->toHaveCount(1);

    $this->actingAs($this->tech)->get("/manuals/{$manual->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Document/Manuals/Show')
        ->has('attachments', 1)
        ->where('can.manage', false));

    $file = $manual->getFirstMedia('attachments');
    $this->actingAs($this->tech)->get("/manuals/{$manual->id}/attachments/{$file->id}")->assertOk();
});

it('takes manuals up to 10 MB, web links only', function () {
    $this->actingAs($this->admin)->post('/manuals', ['title' => 'Big', 'attachments' => [($this->pdf)('big.pdf', 5 * 1024)]])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/manuals', ['title' => 'Too big', 'attachments' => [($this->pdf)('huge.pdf', 11 * 1024)]])->assertSessionHasErrors('attachments.0');
    $this->actingAs($this->admin)->post('/manuals', ['title' => 'Bad link', 'links' => [['url' => 'javascript:alert(1)']]])->assertSessionHasErrors('links.0.url');
    $this->actingAs($this->admin)->post('/manuals', ['title' => ''])->assertSessionHasErrors('title');
});

it('lists manuals with search, a category filter, sort and pages', function () {
    foreach ([['A Network', 'Network'], ['B PC setup', 'PC'], ['C Printer', 'PC']] as [$title, $category]) {
        $this->actingAs($this->admin)->post('/manuals', ['title' => $title, 'category' => $category]);
    }

    $this->actingAs($this->tech)->get('/manuals?sort=title&direction=asc')->assertInertia(fn (Assert $page) => $page
        ->component('Document/Manuals/Index')
        ->where('manuals.total', 3)
        ->where('manuals.data.0.title', 'A Network')
        ->where('categories', ['Network', 'PC'])
        ->where('can.manage', false));
    $this->actingAs($this->tech)->get('/manuals?category=PC')->assertInertia(fn (Assert $page) => $page->where('manuals.total', 2));
    $this->actingAs($this->tech)->get('/manuals?search=printer')->assertInertia(fn (Assert $page) => $page
        ->where('manuals.total', 1)->where('manuals.data.0.title', 'C Printer'));
});

it('keeps manuals for the company: managed by the admin, never by others or another tenant', function () {
    $this->actingAs($this->admin)->post('/manuals', ['title' => 'Ours']);
    $manual = Manual::sole();

    $this->actingAs($this->tech)->get('/manuals/create')->assertForbidden();
    $this->actingAs($this->tech)->post('/manuals', ['title' => 'X'])->assertForbidden();
    $this->actingAs($this->tech)->put("/manuals/{$manual->id}", ['title' => 'X'])->assertForbidden();
    $this->actingAs($this->tech)->delete("/manuals/{$manual->id}")->assertForbidden();
    $this->actingAs($this->tech)->post("/manuals/{$manual->id}/attachments", ['attachments' => [($this->pdf)()]])->assertForbidden();

    // a customer account does not read the company's manuals
    $this->actingAs(userWithRole('customer_it', ['customer_id' => createCustomer()->id]))->get('/manuals')->assertForbidden();

    $other = createTenant('other');
    $foreign = asTenant($other, fn () => Manual::create(['title' => 'Theirs']));
    $this->actingAs($this->admin)->get("/manuals/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->admin)->get('/manuals')->assertInertia(fn (Assert $page) => $page->where('manuals.total', 1));

    $this->actingAs($this->admin)->put("/manuals/{$manual->id}", ['title' => 'Ours v2'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete("/manuals/{$manual->id}")->assertRedirect('/manuals');
    expect(Manual::count())->toBe(0)->and(Manual::withTrashed()->count())->toBe(1);
});
