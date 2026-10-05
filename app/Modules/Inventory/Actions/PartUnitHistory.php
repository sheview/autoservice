<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Asset\Actions\AssetLabels;
use App\Modules\Asset\Actions\CheckoutLineLabels;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\TicketLabels;

/**
 * Everything that happened to some pieces, oldest first: received when and from where, out on
 * which document to whom, back, taken off, corrected; with the ticket, device and request each
 * time (from their modules, when those are on).
 */
class PartUnitHistory
{
    public function __construct(private Modules $modules) {}

    /**
     * @param  list<int>  $unitIds
     * @return array<int, list<array<string, mixed>>> keyed by piece id
     */
    public function handle(array $unitIds): array
    {
        $events = PartUnitEvent::query()->whereIn('part_unit_id', $unitIds)->orderBy('created_at')->orderBy('id')->get();

        $tickets = $this->modules->enabled('service') ? app(TicketLabels::class)->handle($events->pluck('ticket_id')->all()) : [];
        $assets = $this->modules->enabled('asset') ? app(AssetLabels::class)->handle($events->pluck('asset_id')->all()) : [];
        $lines = $this->modules->enabled('asset') ? app(CheckoutLineLabels::class)->handle($events->pluck('checkout_item_id')->all()) : [];

        return $events->groupBy('part_unit_id')->map(fn ($group) => $group->map(fn (PartUnitEvent $event) => [
            ...$event->only(['id', 'action', 'from_status', 'to_status', 'serial_number', 'reference', 'reason', 'user_name']),
            'ticket' => $tickets[$event->ticket_id] ?? null,
            'asset' => $assets[$event->asset_id] ?? null,
            'request' => $lines[$event->checkout_item_id] ?? null,
            'at' => $event->created_at->toIso8601String(),
        ])->values()->all())->all();
    }

    /**
     * One piece as a row of a list, with where it is now.
     *
     * @return array<string, mixed>
     */
    public static function row(PartUnit $unit): array
    {
        return [
            ...$unit->only(['id', 'part_id', 'serial_number', 'status', 'source', 'supplier', 'ticket_id', 'asset_id', 'checkout_item_id', 'note']),
            'unit_cost' => Money::toBaht($unit->unit_cost),
            'received_on' => $unit->received_on?->toDateString(),
            'warranty_until' => $unit->warranty_until?->toDateString(),
        ];
    }
}
