<script setup lang="ts">
import RequestStatusBadge from '@/components/RequestStatusBadge.vue';
import { t } from '@/lib/i18n';

export interface SharedRequestRow {
    id: number;
    company: string | null;
    ticket_no: string | null;
    request_no: string | null;
    by: string | null;
    at: string;
    status: string | null;
    reject_reason?: string | null;
    approved_by_name?: string | null;
    items: { item_name: string; unit: string | null; qty_requested: number; qty_approved: number | null; qty_fulfilled: number; status: string }[];
}

/**
 * Requests made in other companies (through a share), as they stand there: read-only here,
 * since the other company approves and hands out.
 */
defineProps<{ requests: SharedRequestRow[]; showTicket?: boolean }>();
</script>

<template>
    <ul class="divide-y rounded-md border text-sm">
        <li v-for="r in requests" :key="r.id" class="space-y-1.5 p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>
                    <span class="font-semibold">{{ r.company }}</span>
                    <span class="ml-2 font-mono text-xs">{{ r.request_no }}</span>
                    <span v-if="showTicket && r.ticket_no" class="ml-2 text-xs text-muted-foreground">{{
                        t('shares.asked_for_ticket', { no: r.ticket_no })
                    }}</span>
                </span>
                <RequestStatusBadge v-if="r.status" :status="r.status" />
            </div>
            <ul class="space-y-0.5 text-xs">
                <li v-for="(item, i) in r.items" :key="i" class="flex justify-between gap-2">
                    <span>{{ item.item_name }}</span>
                    <span class="tabular-nums text-muted-foreground">
                        {{ t('shares.qty_line', { asked: item.qty_requested, approved: item.qty_approved ?? '-', given: item.qty_fulfilled }) }}
                        {{ item.unit ?? '' }}
                    </span>
                </li>
            </ul>
            <p v-if="r.reject_reason" class="text-xs text-red-700 dark:text-red-400">{{ r.reject_reason }}</p>
            <p class="text-xs text-muted-foreground">
                {{ r.by }} · {{ new Date(r.at).toLocaleString('th-TH-u-ca-gregory', { dateStyle: 'medium', timeStyle: 'short' }) }}
            </p>
        </li>
    </ul>
</template>
