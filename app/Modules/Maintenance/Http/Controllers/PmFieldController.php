<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Maintenance\Actions\MarkPmVisitArrival;
use App\Modules\Maintenance\Actions\MarkPmVisitDeparture;
use App\Modules\Maintenance\Actions\PmInstrumentOptions;
use App\Modules\Maintenance\Actions\SavePmItemDraft;
use App\Modules\Maintenance\Http\Requests\PmItemDraftRequest;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketLabels;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A work order on the technician's phone (or tablet): the equipment by floor and room, one piece of
 * equipment's form at a time, "arrived" / "left", and autosave of what is typed. Only who may do the
 * work (perform: the technician given it, or a PM manager). Saving a result, photos and repair
 * tickets go through the work order's own routes (PmVisitController, PmPhotoController).
 */
class PmFieldController extends Controller
{
    public function __construct(private AssetDetails $assetDetails, private Modules $modules) {}

    public function visit(PmVisit $visit, ListCustomers $listCustomers): Response
    {
        Gate::authorize('perform', $visit);

        $items = $visit->items()->orderBy('id')->get();
        $assets = $this->assetDetails->handle($items->pluck('asset_id')->all());
        $customer = collect($listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id);

        return Inertia::render('Maintenance/Field/Visit', [
            'visit' => $this->visitSummary($visit) + ['customer' => $customer['name'] ?? null],
            'items' => $items->map(fn (PmVisitItem $item) => [
                'id' => $item->id,
                'result' => $item->result,
                'has_draft' => $item->draft !== null,
                'asset' => isset($assets[$item->asset_id])
                    ? collect($assets[$item->asset_id])->only(['asset_code', 'name', 'brand', 'model', 'serial_number', 'property_no', 'location', 'floor', 'room'])->all()
                    : null,
            ])->values(),
        ]);
    }

    public function item(Request $request, PmVisit $visit, PmVisitItem $item, TicketLabels $ticketLabels, UserNames $userNames): Response
    {
        Gate::authorize('perform', $visit);

        $ids = $visit->items()->orderBy('id')->pluck('id')->all();
        $at = array_search($item->id, $ids, true);
        $asset = $this->assetDetails->handle([$item->asset_id])[$item->asset_id] ?? null;
        $ticket = $item->ticket_id && $this->modules->enabled('service') ? ($ticketLabels->handle([$item->ticket_id])[$item->ticket_id] ?? null) : null;

        return Inertia::render('Maintenance/Field/Item', [
            'visit' => $this->visitSummary($visit),
            'item' => [
                ...$item->only(['id', 'result', 'answers', 'note', 'checklist', 'draft', 'instrument_id', 'not_inspected_reason']),
                'remarks' => $item->remarks ?? (object) [],
                'asset' => $asset ? [...collect($asset)->only(['ulid', 'asset_code', 'name', ...ChecklistItems::ASSET_SOURCES])->all(), 'can_view' => $request->user()->can('assets.view')] : null,
                'ticket' => $ticket,
                'photos' => $item->getMedia(PmVisitItem::PHOTOS)->map(fn ($media) => [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'question' => $media->getCustomProperty('question'),
                    'url' => route('maintenance.visits.items.photos.show', [$visit, $item, $media->id]),
                ])->values(),
                'checked_by' => $item->checked_by ? ($userNames->handle([$item->checked_by])[$item->checked_by] ?? null) : null,
                'checked_at' => $item->checked_at?->toIso8601String(),
                'removable' => false,
            ],
            'position' => ['index' => $at + 1, 'total' => count($ids)],
            'previous' => $at > 0 ? $ids[$at - 1] : null,
            'next' => $at < count($ids) - 1 ? $ids[$at + 1] : null,
            'instruments' => app(PmInstrumentOptions::class)->handle(),
            'notInspectedReasons' => PmVisitItem::NOT_INSPECTED_REASONS,
            'priorities' => Ticket::PRIORITIES,
            'canOpenTicket' => $this->modules->enabled('service'),
        ]);
    }

    public function arrive(Request $request, PmVisit $visit, MarkPmVisitArrival $arrive): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $arrive->handle($visit, $request->user());

        return back()->with('success', __('maintenance.field.arrived'));
    }

    public function depart(Request $request, PmVisit $visit, MarkPmVisitDeparture $depart): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $depart->handle($visit, $request->user());

        return back()->with('success', __('maintenance.field.departed'));
    }

    /**
     * Autosave: answers the page sends while the technician types (fetch, JSON).
     */
    public function draft(PmItemDraftRequest $request, PmVisit $visit, PmVisitItem $item, SavePmItemDraft $saveDraft): JsonResponse
    {
        $savedAt = $saveDraft->handle($item, $request->validated());

        return response()->json(['saved_at' => $savedAt->toIso8601String()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function visitSummary(PmVisit $visit): array
    {
        return [
            ...$visit->only(['ulid', 'visit_no', 'status']),
            'title' => $visit->displayTitle(),
            'due_on' => $visit->due_on?->toDateString(),
            'scheduled_on' => $visit->scheduled_on?->toDateString(),
            'onsite_at' => $visit->onsite_at?->toIso8601String(),
            'departed_at' => $visit->departed_at?->toIso8601String(),
            'working' => $visit->isWorking(),
            'can_start' => $visit->status === PmVisit::STATUS_SCHEDULED,
        ];
    }
}
