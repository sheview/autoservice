<script setup lang="ts">
import StepDots from '@/components/StepDots.vue';
import StepProgress, { type Step } from '@/components/StepProgress.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { CheckoutRow } from '@/types/checkout';
import { computed } from 'vue';

// Where an issue/loan form stands: asked for → approved and handed over → given back.
// Rejected or cancelled forms stop at the approval step. "compact" = the dots for a table cell.
const props = defineProps<{ checkout: CheckoutRow; compact?: boolean }>();

const bar = computed(() => {
    const c = props.checkout;
    const steps: Step[] = [
        { key: 'requested', label: t(`checkouts.steps.requested_${c.type}`), at: c.requested_at ? dateTime(c.requested_at) : null },
        {
            key: 'decided',
            label: c.status === 'rejected' ? t('checkouts.steps.rejected') : t('checkouts.steps.approved'),
            at: c.decided_at ? dateTime(c.decided_at) : null,
        },
    ];
    // Issued parts are used up: their form ends at the hand-over.
    const consumed = c.kind === 'part' && c.type === 'issue';
    if (!consumed) {
        steps.push({ key: 'returned', label: t('checkouts.steps.returned'), at: c.returned_at ? dateTime(c.returned_at) : null });
    }
    const current = { pending: 1, approved: consumed ? 1 : 2, returned: 2, rejected: 1, cancelled: 1 }[c.status];
    const done = c.status === 'returned' || (consumed && c.status === 'approved');
    const state = done ? 'done' : c.status === 'rejected' || c.status === 'cancelled' ? 'cancelled' : 'active';

    return { steps, current, state: state as 'done' | 'cancelled' | 'active' };
});
</script>

<template>
    <StepDots v-if="compact" v-bind="bar" />
    <StepProgress v-else v-bind="bar" />
</template>
