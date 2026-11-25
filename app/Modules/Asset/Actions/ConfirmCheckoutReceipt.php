<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutFulfillment;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The receiver's word on what was handed out to them — the hand-outs they ticked ($fulfillmentIds,
 * default: every one not confirmed yet): got them (received), or — with $issue — short or never
 * came, which the stock hears about at once (alert "checkout_receipt_issue") to sort out. A
 * hand-out reported as wrong can still be confirmed later, once sorted out (the report stays on it).
 *
 * The last confirmation of a request that is all handed out (and whose loans are back) closes it.
 */
class ConfirmCheckoutReceipt
{
    public function __construct(private CloseCheckoutRequest $close) {}

    /**
     * @param  list<int>|null  $fulfillmentIds  the hand-outs of this request ticked; null = all not confirmed
     */
    public function handle(CheckoutRequest $request, User $actor, ?string $issue = null, ?array $fulfillmentIds = null): CheckoutRequest
    {
        $issue = $issue === null ? null : trim($issue);
        if ($issue === '') {
            throw ValidationException::withMessages(['receipt_issue' => __('asset.requests.reason_required')]);
        }

        return DB::transaction(function () use ($request, $actor, $issue, $fulfillmentIds) {
            $open = $this->unconfirmed($request)
                // Only hand-outs of this request: an id of another one is not even looked at.
                ->when($fulfillmentIds !== null, fn (Collection $rows) => $rows->whereIn('id', $fulfillmentIds))
                // A report is made once: a hand-out already reported waits to be confirmed.
                ->when($issue !== null, fn (Collection $rows) => $rows->filter(fn (CheckoutFulfillment $f) => $f->awaitsReceipt()));
            if ($open->isEmpty()) {
                throw ValidationException::withMessages(['request' => __('asset.requests.nothing_to_confirm')]);
            }

            CheckoutFulfillment::query()->whereIn('id', $open->pluck('id'))->update($issue === null
                ? ['received_at' => now(), 'received_by' => $actor->id, 'received_by_name' => $actor->name]
                : ['receipt_issue' => $issue, 'received_by' => $actor->id, 'received_by_name' => $actor->name]);

            $lines = $open->map(fn (CheckoutFulfillment $f) => trim("{$f->item->item_name} × {$f->qty} ".($f->item->unit ?? '')))->implode(', ');
            activity()->performedOn($request)->causedBy($actor)->event($issue === null ? 'checkout_receipt_confirmed' : 'checkout_receipt_issue')
                ->withProperties(['request_no' => $request->request_no, 'items' => $lines, 'issue' => $issue])
                ->log(($issue === null ? 'ยืนยันรับของ ' : 'แจ้งปัญหาการรับของ ').$request->request_no.': '.$lines);

            if ($issue !== null) {
                RequestAlert::send('checkout_receipt_issue', $request, $actor->name, $issue);
            } elseif (CheckoutStatus::settled($request->refresh())) {
                // Everything arrived (and nothing lent is out): the job is done.
                $this->close->handle($request, $actor);
            }

            return $request;
        });
    }

    /**
     * Hand-outs of the request not confirmed yet (reported as wrong or not), locked meanwhile.
     *
     * @return Collection<int, CheckoutFulfillment>
     */
    private function unconfirmed(CheckoutRequest $request): Collection
    {
        return CheckoutFulfillment::query()
            ->with('item:id,item_name,unit')
            ->whereIn('item_id', $request->items()->select('id'))
            ->whereNull('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
