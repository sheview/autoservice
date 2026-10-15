<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Maintenance\Actions\CreatePmSignLink;
use App\Modules\Maintenance\Actions\MarkPmVisitArrival;
use App\Modules\Maintenance\Actions\MarkPmVisitDeparture;
use App\Modules\Maintenance\Actions\PassPmItems;
use App\Modules\Maintenance\Actions\PmInstrumentOptions;
use App\Modules\Maintenance\Actions\PmSignSummary;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\SavePmItemDraft;
use App\Modules\Maintenance\Http\Requests\PmItemDraftRequest;
use App\Modules\Maintenance\Http\Requests\RecordPmItemRequest;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\PmWorkflow;
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
 * equipment's form at a time — or, for templates laid out as a table (PC, Network), a row per piece
 * saved as it is filled in, "ผ่านทั้งหมด" on many at once — "arrived" / "left", and autosave of what
 * is typed. Only who may do the
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
        // The layout of each template, read once (the item keeps only a copy of the questions).
        $layouts = PmChecklist::withTrashed()->whereIn('id', $items->pluck('pm_checklist_id')->filter()->unique()->values())->pluck('layout', 'id');
        $layoutOf = fn (PmVisitItem $item) => $layouts[$item->pm_checklist_id] ?? PmChecklist::LAYOUT_PER_ASSET;

        return Inertia::render('Maintenance/Field/Visit', [
            'visit' => $this->visitSummary($visit) + ['customer' => $customer['name'] ?? null],
            'items' => $items->map(fn (PmVisitItem $item) => [
                ...$this->row($item),
                'layout' => $layoutOf($item),
                'template_code' => $item->template_code,
                // only what it has: a work order can have hundreds of rows
                'asset' => isset($assets[$item->asset_id])
                    ? collect($assets[$item->asset_id])->only(['id', 'ulid', 'parent_asset_id', ...ChecklistItems::ASSET_SOURCES])->filter(fn ($value) => $value !== null && $value !== '')->all()
                    : null,
            ])->values(),
            // The questions of each table template, once (not per row).
            'tables' => (object) $items->filter(fn (PmVisitItem $item) => $layoutOf($item) === PmChecklist::LAYOUT_GROUPED_TABLE)
                ->unique('template_code')
                ->mapWithKeys(fn (PmVisitItem $item) => [$item->template_code => $item->checklist ?? []])
                ->all(),
            'notInspectedReasons' => PmVisitItem::NOT_INSPECTED_REASONS,
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

    /**
     * Hand in and sign on the technician's device: the summary of the round, the technician's
     * signature, the customer's here — or a link (QR) for them to sign on their own phone.
     */
    public function sign(PmVisit $visit, PmSignSummary $summary): Response
    {
        Gate::authorize('perform', $visit);
        $settings = PmSettings::of();

        return Inertia::render('Maintenance/Field/Sign', [
            'visit' => $this->visitSummary($visit),
            'work' => $summary->handle($visit),
            'can' => [
                'submit' => PmWorkflow::allows($visit, 'submit') && ! $visit->isLocked(),
                'link' => ! $visit->isLocked() && in_array($visit->status, CreatePmSignLink::STATUSES, true),
            ],
            'signing' => ['technician' => $settings->requireTechnicianSignature, 'customer' => $settings->requireCustomerSignature],
            'levels' => PmSignature::SATISFACTION_LEVELS,
            'surveyOn' => $this->modules->enabled('survey'),
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
     * A row of the table saved as it is filled in (fetch, JSON): the result and the answers of its
     * columns, through RecordPmItem like the full form. What the row does not show (notes per
     * question, the instrument, answers of other questions) is kept.
     */
    public function saveRow(RecordPmItemRequest $request, PmVisit $visit, PmVisitItem $item, RecordPmItem $record): JsonResponse
    {
        $data = $request->validated();
        $record->handle($item, $request->user(), [
            ...$data,
            'answers' => [...($item->answers ?? []), ...($data['answers'] ?? [])],
            'remarks' => $data['remarks'] ?? $item->remarks ?? [],
            'instrument_id' => array_key_exists('instrument_id', $data) ? $data['instrument_id'] : $item->instrument_id,
        ]);

        return response()->json(['item' => $this->row($item->fresh())]);
    }

    /**
     * "ผ่านทั้งหมด" for the rows the technician sees, or for one set (PassPmItems).
     */
    public function passAll(Request $request, PmVisit $visit, PassPmItems $pass): JsonResponse
    {
        Gate::authorize('perform', $visit);
        $data = $request->validate(['item_ids' => ['required', 'array', 'max:2000'], 'item_ids.*' => ['integer']]);

        $done = $pass->handle($visit, $request->user(), array_map('intval', $data['item_ids']));

        return response()->json([
            'items' => $visit->items()->whereIn('id', $done['passed'])->get()->map(fn (PmVisitItem $item) => $this->row($item))->values(),
            'skipped' => $done['skipped'],
            'message' => __('maintenance.field.passed_all', ['passed' => count($done['passed']), 'skipped' => count($done['skipped'])]),
        ]);
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
     * What a row of the work order shows of its item: the autosaved answers over the recorded ones.
     *
     * @return array<string, mixed>
     */
    private function row(PmVisitItem $item): array
    {
        return [
            ...$item->only(['id', 'result', 'note', 'not_inspected_reason']),
            'answers' => $item->draft['answers'] ?? $item->answers ?? (object) [],
            'has_draft' => $item->draft !== null,
        ];
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
            'locked_at' => $visit->locked_at?->toIso8601String(),
        ];
    }
}
