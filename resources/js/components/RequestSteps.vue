<script setup lang="ts">
import StepDots from '@/components/StepDots.vue';
import StepProgress, { type Step } from '@/components/StepProgress.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { CheckoutRequestStatus } from '@/types/checkout';
import { computed } from 'vue';

// Where an issue/loan request stands: draft → sent → approved → partly handed out → handed out →
// closed. A rejected request stops at the approval; a cancelled one where it was. "compact" = dots.
const props = defineProps<{
    request: {
        status: CheckoutRequestStatus;
        requested_at?: string | null;
        submitted_at?: string | null;
        approved_at?: string | null;
        closed_at?: string | null;
    };
    compact?: boolean;
}>();

const order: CheckoutRequestStatus[] = ['draft', 'pending', 'approved', 'partial', 'fulfilled', 'closed'];

const bar = computed(() => {
    const r = props.request;
    const at = (iso?: string | null) => (iso ? dateTime(iso) : null);
    const steps: Step[] = [
        { key: 'draft', label: t('requests.steps.draft'), at: at(r.requested_at) },
        { key: 'pending', label: t('requests.steps.pending'), at: at(r.submitted_at) },
        { key: 'approved', label: r.status === 'rejected' ? t('requests.steps.rejected') : t('requests.steps.approved'), at: at(r.approved_at) },
        { key: 'partial', label: t('requests.steps.partial') },
        { key: 'fulfilled', label: t('requests.steps.fulfilled') },
        { key: 'closed', label: t('requests.steps.closed'), at: at(r.closed_at) },
    ];

    if (r.status === 'rejected') return { steps, current: 2, state: 'cancelled' as const };
    if (r.status === 'cancelled') return { steps, current: r.approved_at ? 3 : r.submitted_at ? 2 : 1, state: 'cancelled' as const };

    const current = order.indexOf(r.status);
    return { steps, current, state: r.status === 'closed' ? ('done' as const) : ('active' as const) };
});
</script>

<template>
    <StepDots v-if="compact" v-bind="bar" />
    <StepProgress v-else v-bind="bar" />
</template>
