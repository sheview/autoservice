<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutFulfillment;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The receiver's word on what was handed out to them, for every hand-out of the request not
 * confirmed yet: got it all (received), or — with $issue — short or never came, which the stock
 * hears about at once (alert "checkout_receipt_issue") to sort out. A hand-out reported as wrong
 * can still be confirmed later, once sorted out (the report stays on it).
 */
class ConfirmCheckoutReceipt
{
    public function handle(CheckoutRequest $request, User $actor, ?string $issue = null): CheckoutRequest
    {
        $issue = $issue === null ? null : trim($issue);
        if ($issue === '') {
            throw ValidationException::withMessages(['receipt_issue' => __('asset.requests.reason_required')]);
        }

        return DB::transaction(function () use ($request, $actor, $issue) {
            $open = $this->unconfirmed($request)
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
