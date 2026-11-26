<?php

use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketFieldLink;
use App\Modules\Tenancy\Support\CompanyCodes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/*
 * Working on a ticket without an account: the helpdesk (or the technician on the job) sends a link
 * to an outside technician, who fills in the work, adds photos and has the customer sign on the
 * screen or on the printed sheet; or the customer only signs off. Nothing closes the job: the
 * helpdesk checks it, closes and prints. One ticket only, while the link is valid.
 */

beforeEach(function () {
    CompanyCodes::forget();
    Storage::fake('public');
    config(['app.url' => 'http://localhost', 'tenancy.public_links' => 'path']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->ticket = openTicket($this->helpdesk, ['title' => 'Printer jam', 'contact_name' => 'Khun Customer'], reportFilled: false);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);
    $this->signature = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('s.png', 10, 10)->getContent());

    $this->link = function (string $mode = 'work', $user = null) {
        $this->actingAs($user ?? $this->helpdesk)->post("/tickets/{$this->ticket->ulid}/field-links", [
            'mode' => $mode, 'holder_name' => 'ช่างสมชาย', 'holder_company' => 'ร้านคอมเชียงใหม่', 'holder_phone' => '0899999999', 'days' => 3,
        ])->assertSessionHasNoErrors();
        auth()->logout();

        return TicketFieldLink::latest('id')->first();
    };
});

it('makes a link from the ticket page for whoever may change the ticket', function () {
    $link = ($this->link)('work', $this->tech);

    expect($link->only(['mode', 'holder_name', 'created_by_name']))->toBe(['mode' => 'work', 'holder_name' => 'ช่างสมชาย', 'created_by_name' => 'Tech One'])
        ->and($link->expires_at->isSameDay(now()->addDays(3)))->toBeTrue();
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('fieldLinks.0.url', "http://localhost/t/001/job/{$link->token}")->where('fieldLinks.0.usable', true));

    $this->actingAs(userWithRole('technician'))->post("/tickets/{$this->ticket->ulid}/field-links", ['mode' => 'work', 'holder_name' => 'x'])->assertForbidden();
});

it('needs the name, company and a phone number of whoever gets the link', function () {
    $url = "/tickets/{$this->ticket->ulid}/field-links";

    $this->actingAs($this->helpdesk)->post($url, ['mode' => 'work', 'holder_name' => 'ช่างสมชาย', 'days' => 3])
        ->assertSessionHasErrors(['holder_company', 'holder_phone']);
    $this->actingAs($this->helpdesk)->post($url, ['mode' => 'sign', 'holder_name' => 'คุณสุดา', 'holder_company' => 'ff', 'holder_phone' => '022', 'days' => 3])
        ->assertSessionHasErrors(['holder_phone']);
    expect(TicketFieldLink::count())->toBe(0);

    foreach (['02-111-1111', '089 999 9999', '+66899999999'] as $phone) {
        $this->actingAs($this->helpdesk)->post($url, ['mode' => 'work', 'holder_name' => 'ช่างสมชาย', 'holder_company' => 'ร้านคอม', 'holder_phone' => $phone, 'days' => 3])
            ->assertSessionHasNoErrors();
    }
    expect(TicketFieldLink::count())->toBe(3);
});

it('lets the outside technician send the work back without an account, leaving the job for the helpdesk', function () {
    Queue::fake();
    app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => ['ticket_field_reported'],
        'line' => ['enabled' => true, 'to' => 'Cgroup', 'token' => 'line-token'],
        'telegram' => ['enabled' => false, 'chat_id' => '', 'token' => ''],
        'mail' => ['enabled' => false, 'recipients' => []],
    ]);
    $link = ($this->link)();

    $this->get("/t/001/job/{$link->token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/FieldLink')
        ->where('page.ticket.title', 'Printer jam')->where('page.link.usable', true)
        ->where('page.presets.symptom', fn ($list) => count($list) > 0));

    $this->post("/t/001/job/{$link->token}", [
        'symptoms' => ['กระดาษติด'], 'solutions' => ['ทำความสะอาด'], 'parts' => 'ลูกยางดึงกระดาษ 1 ชุด', 'note' => 'ลูกยางสึก',
        'signer_name' => 'Khun Customer', 'tech_signature' => $this->signature, 'signature' => $this->signature,
        'before' => [UploadedFile::fake()->image('b.jpg')], 'after' => [UploadedFile::fake()->image('a.jpg')],
    ])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    expect($ticket->status)->toBe(Ticket::STATUS_ASSIGNED)
        ->and($ticket->cause)->toContain('กระดาษติด')->toContain('ทำความสะอาด')->toContain('ลูกยางดึงกระดาษ')->toContain('ลูกยางสึก')
        ->and($ticket->approver_name)->toBe('Khun Customer')
        ->and($ticket->getFirstMedia(Ticket::SIGNATURE)->getCustomProperty('signer'))->toBe('Khun Customer')
        // the technician signed too, under the name the link was made for
        ->and($ticket->getFirstMedia(Ticket::TECH_SIGNATURE)->getCustomProperty('signer'))->toBe('ช่างสมชาย')
        ->and($ticket->getMedia(Ticket::PHOTOS)->map->getCustomProperty('stage')->sort()->values()->all())->toBe(['after', 'before'])
        ->and($ticket->events()->latest('id')->first()->only(['user_id', 'user_name', 'is_internal']))
        ->toBe(['user_id' => null, 'user_name' => 'ช่างสมชาย (ร้านคอมเชียงใหม่ · ผ่านลิงก์)', 'is_internal' => true])
        ->and($link->fresh()->submitted_at)->not->toBeNull();
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'ticket_field_reported' && str_contains($job->body, 'ช่างสมชาย'));

    // The helpdesk sees it waiting, closes the job as usual, and the sheet carries the signature.
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('fieldLinks.0.submitted_at', fn ($at) => $at !== null)->where('fieldLinks.0.reviewed_at', null));
    app(MoveTicket::class)->handle($ticket, 'start', $this->tech);
    app(MoveTicket::class)->handle($ticket->fresh(), 'resolve', $this->helpdesk);
    expect($link->fresh()->reviewed_by_name)->toBe('Desk One');
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}/print")->assertInertia(fn (Assert $page) => $page
        ->where('signature.signer', 'Khun Customer')->where('signature.image', fn ($img) => str_starts_with($img, 'data:image/png;base64,'))
        ->where('techSignature.signer', 'ช่างสมชาย')->where('techSignature.image', fn ($img) => str_starts_with($img, 'data:image/png;base64,')));
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('techSignature.signer', 'ช่างสมชาย'));
});

it('takes the work on the screen only with a solution, the technician\'s signature, then the customer\'s', function () {
    $link = ($this->link)();
    $work = ['symptoms' => ['กระดาษติด'], 'solutions' => ['ทำความสะอาดลูกยาง'], 'signer_name' => 'Khun Customer'];

    // nothing signed at all: not sent
    $this->post("/t/001/job/{$link->token}", ['symptoms' => ['กระดาษติด'], 'note' => 'ยังไม่เสร็จ'])
        ->assertSessionHasErrors(['solutions', 'tech_signature', 'signature']);
    // the customer signed, the technician not
    $this->post("/t/001/job/{$link->token}", [...$work, 'signature' => $this->signature])
        ->assertSessionHasErrors('tech_signature')->assertSessionDoesntHaveErrors('signature');
    // the technician signed, the customer not
    $this->post("/t/001/job/{$link->token}", [...$work, 'tech_signature' => $this->signature])
        ->assertSessionHasErrors('signature')->assertSessionDoesntHaveErrors('tech_signature');
    // both signed, but what was done is not said
    $this->post("/t/001/job/{$link->token}", [...$work, 'solutions' => [], 'tech_signature' => $this->signature, 'signature' => $this->signature])
        ->assertSessionHasErrors('solutions');
    // a solution typed in (not in the list) counts
    $this->post("/t/001/job/{$link->token}", [...$work, 'solutions' => ['เปลี่ยนเฟืองขับกระดาษ'], 'tech_signature' => $this->signature, 'signature' => $this->signature])
        ->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    expect($ticket->cause)->toContain('เปลี่ยนเฟืองขับกระดาษ')
        ->and($ticket->getFirstMedia(Ticket::TECH_SIGNATURE))->not->toBeNull()
        ->and($ticket->getFirstMedia(Ticket::SIGNATURE))->not->toBeNull()
        ->and($link->fresh()->submitted_at)->not->toBeNull();
});

it('takes the sign-off on paper: the printed sheet, then a photo of it', function () {
    $link = ($this->link)();

    $this->get("/t/001/job/{$link->token}/print")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/Tickets/Print')->where('publicView', true)->where('ticket.title', 'Printer jam'));

    $this->post("/t/001/job/{$link->token}", ['signed_sheet' => UploadedFile::fake()->image('sheet.jpg')])->assertSessionHasErrors('signer_name');
    // without the photo of the signed sheet (nor signatures on the screen) the work is not sent
    $this->post("/t/001/job/{$link->token}", ['note' => 'ซ่อมเสร็จ', 'signer_name' => 'Khun Customer'])->assertSessionHasErrors('signature');
    // on paper: what was done is written on the sheet, no solution or signatures needed here
    $this->post("/t/001/job/{$link->token}", ['note' => 'ซ่อมเสร็จ', 'signer_name' => 'Khun Customer', 'signed_sheet' => UploadedFile::fake()->image('sheet.jpg')])
        ->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    expect($ticket->getMedia(Ticket::PHOTOS)->sole()->getCustomProperty('stage'))->toBe('signed_sheet')
        ->and($ticket->approver_name)->toBe('Khun Customer')
        ->and($ticket->getFirstMedia(Ticket::TECH_SIGNATURE))->toBeNull();
});

it('lets the customer only sign off through a sign link', function () {
    $ticket = $this->ticket;
    $ticket->forceFill(['cause' => 'เปลี่ยนลูกยาง'])->save();
    $link = ($this->link)('sign');

    $this->get("/t/001/job/{$link->token}")->assertInertia(fn (Assert $page) => $page->where('page.link.mode', 'sign')->where('page.presets', null)
        ->where('page.ticket.report.cause', 'เปลี่ยนลูกยาง'));
    $this->post("/t/001/job/{$link->token}", ['signer_name' => 'Khun Customer'])->assertSessionHasErrors('signature');
    // A sign link cannot change the report.
    $this->post("/t/001/job/{$link->token}", ['note' => 'แก้ทับ', 'signer_name' => 'Khun Customer', 'signature' => $this->signature])->assertSessionHasNoErrors();

    expect($ticket->fresh()->cause)->toBe('เปลี่ยนลูกยาง')
        ->and($ticket->fresh()->getFirstMedia(Ticket::SIGNATURE))->not->toBeNull();
});

it('works for that ticket only, until revoked, expired or the job is closed', function () {
    $link = ($this->link)();

    $this->get('/t/001/job/'.str_repeat('x', 40))->assertInertia(fn (Assert $page) => $page->where('page', null));
    $other = createTenant('other');
    CompanyCodes::forget();
    $this->get('/t/'.$other->fresh()->company_code."/job/{$link->token}")->assertInertia(fn (Assert $page) => $page->where('page', null));

    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/field-links/{$link->id}/revoke")->assertSessionHasNoErrors();
    auth()->logout();
    $this->get("/t/001/job/{$link->token}")->assertInertia(fn (Assert $page) => $page->where('page.link.usable', false));
    $this->post("/t/001/job/{$link->token}", ['note' => 'x', 'signer_name' => 'y', 'signature' => $this->signature])->assertSessionHasErrors('link');
    $this->get("/t/001/job/{$link->token}/print")->assertNotFound();

    $fresh = ($this->link)();
    $this->travel(4)->days();
    $this->post("/t/001/job/{$fresh->token}", ['note' => 'x'])->assertSessionHasErrors('link');
});

it('gives the outside technician the repair fee claim form, with their name and the job on it', function () {
    Feature::for($this->tenant)->activate(Modules::feature('repair_claim'));
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 claim', 200)]);
    $link = ($this->link)();

    $this->get("/t/001/job/{$link->token}/claim-form")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="แบบฟอร์มขอเบิกค่าซ่อม.pdf"');

    $html = '';
    Http::assertSent(function (HttpRequest $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'files' && ($part['filename'] ?? null) === 'index.html') {
                $html = $part['contents'];
            }
        }

        return true;
    });
    expect($html)
        ->toContain('แบบฟอร์มขอเบิกค่าซ่อมคอมพิวเตอร์ต่างจังหวัด')
        ->toContain($this->tenant->name)
        ->toContain('ช่างสมชาย')->toContain('ร้านคอมเชียงใหม่')->toContain('0899999999')
        ->toContain($this->ticket->ticket_no.' Printer jam')
        ->toContain('ภ.พ.20');
});

it('shows the claim form to print from the browser when the PDF service is down', function () {
    Feature::for($this->tenant)->activate(Modules::feature('repair_claim'));
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(fn () => throw new ConnectionException('refused'));
    $link = ($this->link)();

    $this->get("/t/001/job/{$link->token}/claim-form")->assertOk()
        ->assertSee('แบบฟอร์มขอเบิกค่าซ่อมคอมพิวเตอร์ต่างจังหวัด')->assertSee('ช่างสมชาย')->assertSee('window.print()', false);
});

it('gives the claim form only to companies that switched it on, for a usable work link of theirs', function () {
    Http::fake(['*' => Http::response('%PDF-1.7 claim', 200)]);
    $off = ($this->link)();
    $this->get("/t/001/job/{$off->token}")->assertInertia(fn (Assert $page) => $page->where('page.claim_form', false));
    $this->get("/t/001/job/{$off->token}/claim-form")->assertNotFound();

    Feature::for($this->tenant)->activate(Modules::feature('repair_claim'));
    $this->get("/t/001/job/{$off->token}")->assertInertia(fn (Assert $page) => $page->where('page.claim_form', true));
    $sign = ($this->link)('sign');
    $this->get("/t/001/job/{$sign->token}/claim-form")->assertNotFound();

    $link = ($this->link)();
    $other = createTenant('other');
    CompanyCodes::forget();
    $this->get('/t/'.$other->fresh()->company_code."/job/{$link->token}/claim-form")->assertNotFound();

    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/field-links/{$link->id}/revoke")->assertSessionHasNoErrors();
    auth()->logout();
    $this->get("/t/001/job/{$link->token}/claim-form")->assertNotFound();
});
