<?php

use App\Modules\Document\Support\ThaiDate;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Service\Actions\AssignTicket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    $this->travelTo('2026-06-15 10:00');

    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $this->asset = createAsset(createAssetCategory(), ['name' => 'Core switch', 'customer_id' => $this->customer->id]);
});

/** The HTML page sent to Gotenberg by the last request. */
function sentHtml(): string
{
    $html = '';
    Http::assertSent(function (HttpRequest $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'files') {
                $html = $part['contents'];
            }
        }

        return str_ends_with($request->url(), '/forms/chromium/convert/html');
    });

    return $html;
}

it('formats document dates in Thai with the Buddhist year', function () {
    expect(ThaiDate::format('2026-06-15'))->toBe('15 มิ.ย. 2569')
        ->and(ThaiDate::format('2026-01-02 03:04:00', true))->toBe('2 ม.ค. 2569 03:04')
        ->and(ThaiDate::format(null))->toBe('-');
});

it('makes the job sheet PDF of a ticket through Gotenberg, without internal notes', function () {
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $ticket = openTicket($this->helpdesk, ['title' => 'Printer jam', 'customer_id' => $this->customer->id, 'asset_id' => $this->asset->id]);
    app(AssignTicket::class)->handle($ticket, $this->tech->id, $this->helpdesk);
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/comments", ['body' => 'เปลี่ยนลูกยาง']);
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/comments", ['body' => 'ลูกค้าค้างชำระ', 'is_internal' => true]);

    $response = $this->actingAs($this->tech)->get("/tickets/{$ticket->ulid}/pdf")->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain("{$ticket->ticket_no}.pdf")
        ->and($response->getContent())->toBe('%PDF-1.7 fake');

    $html = sentHtml();
    expect($html)->toContain($ticket->ticket_no, 'Printer jam', 'Acme', 'Core switch', 'Tech One', 'เปลี่ยนลูกยาง', '15 มิ.ย. 2569')
        ->not->toContain('ลูกค้าค้างชำระ')
        ->not->toContain('2026');
});

it('makes the PM report PDF of a round with every asset and its checklist answers', function () {
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 pm', 200)]);

    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $this->asset->id]);
    PmChecklist::create(['name' => 'ทั่วไป', 'asset_category_id' => null, 'items' => [
        ['key' => 'clean', 'label' => 'ทำความสะอาด', 'type' => 'check'],
        ['key' => 'temp', 'label' => 'อุณหภูมิ', 'type' => 'number'],
    ]]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $visit = app(StartPmVisit::class)->handle($plan->visits()->orderBy('round')->first(), $this->tech);
    $item = $visit->items()->first();
    $item->update(['result' => PmVisitItem::RESULT_ISSUE, 'answers' => ['clean' => true, 'temp' => 41], 'note' => 'พัดลมเสียงดัง']);

    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}/pdf")->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(sentHtml())->toContain($visit->visit_no, 'PM Acme', 'Acme', 'Core switch', 'ทำความสะอาด', 'อุณหภูมิ', '41', 'พัดลมเสียงดัง', 'พบปัญหา')
        ->toContain('ทั้งหมด 1 เครื่อง');
});

it('sends the user back with a message when Gotenberg is not available', function () {
    Http::fake(['gotenberg.test:3000/*' => Http::response('boom', 500)]);
    $ticket = openTicket($this->helpdesk);

    $this->actingAs($this->helpdesk)->from("/tickets/{$ticket->ulid}")->get("/tickets/{$ticket->ulid}/pdf")
        ->assertRedirect("/tickets/{$ticket->ulid}")
        ->assertSessionHas('error', __('document.unavailable'));

    Http::fake(fn () => throw new ConnectionException('refused'));
    $this->actingAs($this->helpdesk)->from("/tickets/{$ticket->ulid}")->get("/tickets/{$ticket->ulid}/pdf")
        ->assertSessionHas('error');
});

it('only makes documents the user may see', function () {
    Http::fake();
    $ticket = openTicket($this->helpdesk, ['customer_id' => $this->customer->id]);

    $this->get("/tickets/{$ticket->ulid}/pdf")->assertRedirect('/login');
    $this->actingAs(userWithRole('customer', ['customer_id' => createCustomer()->id]))->get("/tickets/{$ticket->ulid}/pdf")->assertForbidden();

    $other = createTenant('other');
    $theirs = asTenant($other, fn () => openTicket(userWithRole('helpdesk')));
    $this->actingAs($this->helpdesk)->get("/tickets/{$theirs->ulid}/pdf")->assertNotFound();

    Http::assertNothingSent();
});
