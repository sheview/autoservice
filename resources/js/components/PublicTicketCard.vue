<script setup lang="ts">
import StepProgress from '@/components/StepProgress.vue';
import { t } from '@/lib/i18n';
import { MessageSquareText, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';

export interface PublicTicket {
    ticket_no: string;
    state: 'reviewing' | 'received' | 'in_progress' | 'done' | 'rejected';
    step: number;
    updated_at: string;
    message: string | null;
}

/**
 * A ticket as a customer may see it (PublicTicketStatus): where it stands, when that changed,
 * and what the office wrote. Shared by the tracking search and the tracking link.
 */
const props = defineProps<{ ticket: PublicTicket }>();

const steps = computed(() => ['reviewing', 'received', 'in_progress', 'done'].map((key) => ({ key, label: t(`track.steps.${key}`) })));
const updated = computed(() => new Date(props.ticket.updated_at).toLocaleString('th-TH-u-ca-gregory', { dateStyle: 'medium', timeStyle: 'short' }));
</script>

<template>
    <article class="space-y-4 rounded-2xl border bg-white p-6 shadow-sm dark:bg-slate-950">
        <div class="flex items-start gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300"
            >
                <Wrench class="size-5" />
            </div>
            <div class="min-w-0">
                <div class="font-mono font-semibold">{{ ticket.ticket_no }}</div>
                <div class="text-sm text-muted-foreground">{{ t('track.updated_at') }}: {{ updated }}</div>
            </div>
        </div>

        <StepProgress v-if="ticket.state !== 'rejected'" :steps="steps" :current="ticket.step" :state="ticket.state === 'done' ? 'done' : 'active'" />
        <p v-else class="rounded-xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:bg-red-950 dark:text-red-200">
            {{ t('track.states.rejected') }}
        </p>

        <div v-if="ticket.message" class="flex gap-3 rounded-xl bg-violet-50 px-4 py-3 text-sm dark:bg-violet-950/50">
            <MessageSquareText class="mt-0.5 size-4 shrink-0 text-violet-700 dark:text-violet-300" />
            <div>
                <div class="font-semibold">{{ t('track.message') }}</div>
                <p class="whitespace-pre-line">{{ ticket.message }}</p>
            </div>
        </div>
    </article>
</template>
