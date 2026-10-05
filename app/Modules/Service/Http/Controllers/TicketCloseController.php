<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\SignatureRequirement;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\QuickCloseTicket;
use App\Modules\Service\Actions\RepairPresetList;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketRemovedPart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Closing a job on a phone, on one scrolling page (QuickCloseTicket): who may finish the job
 * (TicketPolicy::resolve — the assignee or a dispatcher) may use it. Parts need parts.issue too.
 */
class TicketCloseController extends Controller
{
    public const MAX_PHOTOS = 6;

    public const PHOTO_KB = 5120;

    public function __construct(private Modules $modules) {}

    public function show(Request $request, Ticket $ticket, RepairPresetList $presets, SignatureRequirement $signature, AssetDetails $assetDetails, PartsForCheckout $parts): Response
    {
        Gate::authorize('resolve', $ticket);
        $user = $request->user();
        $asset = $ticket->asset_id ? ($assetDetails->handle([$ticket->asset_id])[$ticket->asset_id] ?? null) : null;
        $search = $request->string('part_search')->trim()->value();

        return Inertia::render('Service/Tickets/Close', [
            'ticket' => [
                ...$ticket->only(['ulid', 'ticket_no', 'title', 'status', 'contact_name', 'device_name']),
                'asset' => $asset ? $asset['asset_code'].' '.$asset['name'] : null,
                'warranty_checked' => $ticket->warranty_checked_at !== null,
                'closable' => in_array($ticket->status, QuickCloseTicket::CLOSABLE, true),
            ],
            'presets' => $presets->handle(),
            'signatureRequired' => $signature->handle($ticket->customer_id, $ticket->contract_id),
            'canIssueParts' => $this->modules->enabled('inventory') && $user->can('parts.issue'),
            'partOptions' => fn () => $search === '' || ! $this->modules->enabled('inventory') ? [] : array_values($parts->handle(null, $search)),
            'warrantyStatuses' => Ticket::WARRANTY_STATUSES,
            'dispositions' => TicketRemovedPart::DISPOSITIONS,
            'limits' => ['photos' => self::MAX_PHOTOS, 'photo_kb' => self::PHOTO_KB],
        ]);
    }

    public function store(Request $request, Ticket $ticket, QuickCloseTicket $close): RedirectResponse
    {
        Gate::authorize('resolve', $ticket);
        $user = $request->user();

        $data = $request->validate([
            'symptoms' => ['array', 'max:20'],
            'symptoms.*' => ['string', 'max:100'],
            'solutions' => ['array', 'max:20'],
            'solutions.*' => ['string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'warranty_status' => ['nullable', Rule::in(Ticket::WARRANTY_STATUSES)],
            'parts' => ['array', 'max:30'],
            'parts.*.part_id' => ['required', 'integer'],
            'parts.*.qty' => ['required', 'integer', 'min:1', 'max:10000'],
            // A part followed by serial number: the pieces used (scanned or chosen).
            'parts.*.unit_ids' => ['array', 'max:10000'],
            'parts.*.unit_ids.*' => ['integer'],
            // Pieces taken out of the device: a note, not stock.
            'removed' => ['array', 'max:20'],
            'removed.*.item_name' => ['required', 'string', 'max:255'],
            'removed.*.serial_number' => ['nullable', 'string', 'max:100'],
            'removed.*.problem' => ['nullable', 'string', 'max:1000'],
            'removed.*.disposition' => ['required', Rule::in(TicketRemovedPart::DISPOSITIONS)],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'approver_name' => ['nullable', 'string', 'max:255'],
            // A PNG drawn on the screen, as a data URL, at most about 300 KB.
            'signature' => ['nullable', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'before' => ['array', 'max:'.self::MAX_PHOTOS],
            'before.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_KB],
            'after' => ['array', 'max:'.self::MAX_PHOTOS],
            'after.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_KB],
        ], attributes: __('service.quick_close.fields'));

        if (! empty($data['parts']) && ! ($this->modules->enabled('inventory') && $user->can('parts.issue'))) {
            abort(403);
        }
        if (blank($data['symptoms'] ?? []) && blank($data['solutions'] ?? []) && blank($data['note'] ?? null)) {
            return back()->withErrors(['symptoms' => __('service.quick_close.what_required')]);
        }

        $close->handle($ticket, $user, [
            ...$data,
            'symptoms' => $data['symptoms'] ?? [],
            'solutions' => $data['solutions'] ?? [],
        ], ['before' => $request->file('before', []), 'after' => $request->file('after', [])]);

        return redirect()->route('service.tickets.show', $ticket)->with('success', __('service.quick_close.done', ['no' => $ticket->ticket_no]));
    }
}
