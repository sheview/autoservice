<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\RequestAlert;
use App\Modules\Platform\Support\AlertSettings;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Alerts (once each) about issue/loan requests that are late, with the company's thresholds
 * (AlertSettings::thresholds): waiting for approval too many hours; backordered or partly handed
 * out for too many days, or close to the day they are needed; lent assets past their due date.
 */
class NotifyCheckoutDelays
{
    public function __construct(private TenantContext $context) {}

    /** @return int how many alerts went out */
    public function handle(): int
    {
        $tenant = $this->context->tenant();
        if ($tenant === null) {
            return 0;
        }
        $limits = AlertSettings::thresholds($tenant);
        $sent = 0;

        CheckoutRequest::query()
            ->where('status', CheckoutRequest::STATUS_PENDING)
            ->whereNull('approval_alerted_at')
            ->where('submitted_at', '<=', now()->subHours($limits['approval_hours']))
            ->each(function (CheckoutRequest $request) use (&$sent) {
                RequestAlert::send('checkout_approval_overdue', $request);
                $request->update(['approval_alerted_at' => now()]);
                $sent++;
            });

        CheckoutItem::query()
            ->with('request')
            ->whereIn('status', [CheckoutItem::STATUS_BACKORDERED, CheckoutItem::STATUS_PARTIAL])
            ->whereNull('backorder_alerted_at')
            ->whereHas('request', fn ($q) => $q->whereIn('status', CheckoutRequest::OPEN_STATUSES)->where(fn ($q) => $q
                ->where('approved_at', '<=', now()->subDays($limits['backorder_days']))
                ->orWhere('needed_by', '<=', today()->addDays($limits['needed_soon_days'])->toDateString())))
            ->each(function (CheckoutItem $item) use (&$sent) {
                RequestAlert::send('checkout_backorder_overdue', $item->request, null, null, $item);
                $item->update(['backorder_alerted_at' => now()]);
                $sent++;
            });

        CheckoutItem::query()
            ->with('request')
            ->where('item_type', CheckoutItem::TYPE_ASSET)
            ->where('checkout_type', CheckoutItem::LOAN)
            ->whereNull('overdue_alerted_at')
            ->whereNotNull('due_return_date')
            ->where('due_return_date', '<', today()->toDateString())
            ->whereColumn('qty_fulfilled', '>', 'qty_returned')
            ->each(function (CheckoutItem $item) use (&$sent) {
                RequestAlert::send('checkout_return_overdue', $item->request, null, $item->due_return_date?->format('d/m/Y'), $item);
                $item->update(['overdue_alerted_at' => now()]);
                $sent++;
            });

        return $sent;
    }
}
