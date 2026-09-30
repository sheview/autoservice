<?php

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Notifications\LowStockNotification;
use App\Modules\Platform\Support\Modules;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Store keeper']);
    $this->tech = userWithRole('technician');

    $this->ram = createPart(['code' => 'RAM', 'name' => 'Memory', 'min_qty' => 5], stock: 3);  // low
    $this->psu = createPart(['code' => 'PSU', 'name' => 'Power supply', 'min_qty' => 1]);      // out
    createPart(['code' => 'SSD', 'min_qty' => 2], stock: 10);                                  // fine
    createPart(['code' => 'FAN'], stock: 0);                                                   // not watched
    createPart(['code' => 'OLD', 'min_qty' => 5, 'is_active' => false]);                       // inactive
});

it('e-mails the staff who restock about low parts, once per shortage', function () {
    Notification::fake();

    $this->artisan('inventory:notify-low-stock')->assertSuccessful();

    Notification::assertSentTo($this->admin, LowStockNotification::class, fn ($n) => $n->parts->pluck('code')->all() === ['PSU', 'RAM']);
    // a technician takes parts but does not restock
    Notification::assertNotSentTo($this->tech, LowStockNotification::class);
    expect(Part::whereNotNull('low_stock_notified_at')->pluck('code')->sort()->values()->all())->toBe(['PSU', 'RAM']);

    // nobody is e-mailed twice about the same shortage, even when it gets worse
    $this->actingAs($this->tech)->post("/parts/{$this->ram->id}/movements", ['type' => 'issue', 'quantity' => 1]);
    $this->artisan('inventory:notify-low-stock');
    Notification::assertSentToTimes($this->admin, LowStockNotification::class, 1);

    // restocking above the reorder point re-arms it: the next shortage is e-mailed again
    $this->actingAs($this->admin)->post("/parts/{$this->ram->id}/movements", ['type' => 'receive', 'quantity' => 10]);
    expect($this->ram->fresh()->low_stock_notified_at)->toBeNull();
    $this->actingAs($this->admin)->post("/parts/{$this->ram->id}/movements", ['type' => 'issue', 'quantity' => 9]);
    $this->artisan('inventory:notify-low-stock');
    Notification::assertSentTo($this->admin, LowStockNotification::class, fn ($n) => $n->parts->pluck('code')->all() === ['RAM']);

    // so does a new reorder point
    $this->actingAs($this->admin)->put("/parts/{$this->psu->id}", ['code' => 'PSU', 'name' => 'Power supply', 'unit' => 'pcs', 'min_qty' => 3]);
    expect($this->psu->fresh()->low_stock_notified_at)->toBeNull();
});

it('writes the mail with what is left and links to the part list', function () {
    $mail = (new LowStockNotification(collect([$this->ram, $this->psu])))->toMail($this->admin);

    expect($mail->subject)->toBe('อะไหล่ใกล้หมด 2 รายการ')
        ->and($mail->greeting)->toContain('Store keeper')
        ->and(implode("\n", $mail->introLines))
        ->toContain('RAM Memory — คงเหลือ 3 pcs (จุดสั่งซื้อ 5)')
        ->toContain('PSU Power supply — หมดสต็อก')
        ->and($mail->actionUrl)->toContain('/parts?sort=qty_on_hand');
});

it('skips tenants with the module off and keeps tenants apart', function () {
    Notification::fake();

    $other = createTenant('other');
    $theirAdmin = userWithRole('admin_company', [], $other);
    asTenant($other, fn () => createPart(['code' => 'THEIRS', 'min_qty' => 2]));

    Feature::for($this->tenant)->deactivate(Modules::feature('inventory'));
    $this->artisan('inventory:notify-low-stock');

    Notification::assertNotSentTo($this->admin, LowStockNotification::class);
    Notification::assertSentTo($theirAdmin, LowStockNotification::class, fn ($n) => $n->parts->pluck('code')->all() === ['THEIRS']);
    expect(Part::whereNotNull('low_stock_notified_at')->count())->toBe(0);
});

it('leaves parts unmarked while nobody can restock', function () {
    Notification::fake();
    $this->admin->update(['is_active' => false]);

    $this->artisan('inventory:notify-low-stock');

    Notification::assertNothingSent();
    expect(Part::whereNotNull('low_stock_notified_at')->count())->toBe(0);
});
