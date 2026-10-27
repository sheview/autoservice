<script setup lang="ts">
import StepDots from '@/components/StepDots.vue';
import StepProgress, { type Step } from '@/components/StepProgress.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { CheckoutRequestStatus } from '@/types/checkout';
import { computed } from 'vue';

// Where an issue/loan request stands, in four steps: asked → approved → handed out → closed. The
// step being worked on says how it stands (draft, waiting approval, partly handed out, loan still
// out). A rejected request stops at the approval; a cancelled one where it was. "compact" = dots.
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

// The step each status is working on.
const current: Partial<Record<CheckoutRequestStatus, number>> = { draft: 0, pending: 1, approved: 2, partial: 2, fulfilled: 3, closed: 3 };

const bar = computed(() => {
    const r = props.request;
    const at = (iso?: string | null) => (iso ? dateTime(iso) : null);
    const label = (key: string, now: CheckoutRequestStatus[], nowKey: string) => t(`requests.steps.${now.includes(r.status) ? nowKey : key}`);
    const steps: Step[] = [
        { key: 'request', label: label('request', ['draft'], 'draft'), at: at(r.submitted_at ?? r.requested_at) },
        {
            key: 'approve',
            label: r.status === 'rejected' ? t('requests.steps.rejected') : label('approve', ['pending'], 'pending'),
            at: at(r.approved_at),
        },
        { key: 'hand_out', label: t(`requests.steps.${r.status === 'approved' ? 'to_hand_out' : r.status === 'partial' ? 'partial' : 'hand_out'}`) },
        { key: 'closed', label: label('closed', ['fulfilled'], 'loan_out'), at: at(r.closed_at) },
    ];

    if (r.status === 'rejected') return { steps, current: 1, state: 'cancelled' as const };
    if (r.status === 'cancelled') return { steps, current: r.approved_at ? 2 : r.submitted_at ? 1 : 0, state: 'cancelled' as const };

    return { steps, current: current[r.status] ?? 0, state: r.status === 'closed' ? ('done' as const) : ('active' as const) };
});
</script>

<template>
    <StepDots v-if="compact" v-bind="bar" />
    <StepProgress v-else v-bind="bar" />
</template>
