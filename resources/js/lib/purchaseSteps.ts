import type { Step } from '@/components/StepProgress.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { PurchaseRequestRow, PurchaseStatus } from '@/types/purchase';

/**
 * Where a purchase request stands: asked for → approved → ordered → received → registered →
 * handed out. A rejection stops at the approval step; a cancellation stops where the request was
 * when it was withdrawn. For StepProgress (the request page) and StepDots (the list).
 */
export function purchaseSteps(r: PurchaseRequestRow): { steps: Step[]; current: number; state: 'done' | 'cancelled' | 'active' } {
    const steps: Step[] = [
        { key: 'requested', label: t('purchase_requests.step_requested'), at: r.requested_at ? dateTime(r.requested_at) : null },
        { key: 'decided', label: t('purchase_requests.step_decided'), at: r.decided_at ? dateTime(r.decided_at) : null },
        { key: 'ordered', label: t('purchase_requests.step_ordered'), at: r.ordered_at ? dateTime(r.ordered_at) : null },
        { key: 'received', label: t('purchase_requests.step_received'), at: r.received_at ? dateTime(r.received_at) : null },
        { key: 'registered', label: t('purchase_requests.step_registered'), at: null },
        { key: 'issued', label: t('purchase_requests.step_issued'), at: null },
    ];
    const current: Record<PurchaseStatus, number> = {
        pending: 1,
        approved: 2,
        ordered: 3,
        partially_received: 3,
        received: 4,
        registered: 5,
        issued: 5,
        rejected: 1,
        cancelled: r.ordered_at ? 3 : r.decided_at ? 2 : 1,
    };
    const state = r.status === 'issued' ? 'done' : r.status === 'rejected' || r.status === 'cancelled' ? 'cancelled' : 'active';

    return { steps, current: current[r.status], state };
}
