<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { SummaryTotals } from '@/types/summary';

// The top of a person's or project's summary: issues, loans and purchases, with how many are still open.
defineProps<{ totals: SummaryTotals; purchases?: boolean }>();

const money = (baht: string | undefined) => Number(baht ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 2 });
</script>

<template>
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-md border p-4">
            <dt class="text-xs text-muted-foreground">{{ t('summary.issues') }}</dt>
            <dd class="text-2xl font-semibold tabular-nums">{{ totals.issues }}</dd>
            <dd class="text-xs text-amber-700 dark:text-amber-400">{{ t('summary.open_count', { count: totals.issues_open }) }}</dd>
        </div>
        <div class="rounded-md border p-4">
            <dt class="text-xs text-muted-foreground">{{ t('summary.loans') }}</dt>
            <dd class="text-2xl font-semibold tabular-nums">{{ totals.loans }}</dd>
            <dd class="text-xs text-amber-700 dark:text-amber-400">{{ t('summary.open_count', { count: totals.loans_open }) }}</dd>
        </div>
        <template v-if="purchases !== false">
            <div class="rounded-md border p-4">
                <dt class="text-xs text-muted-foreground">{{ t('summary.purchases') }}</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ totals.purchases }}</dd>
                <dd class="text-xs text-amber-700 dark:text-amber-400">{{ t('summary.open_count', { count: totals.purchases_open ?? 0 }) }}</dd>
            </div>
            <div class="rounded-md border p-4">
                <dt class="text-xs text-muted-foreground">{{ t('summary.purchase_amount') }}</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ money(totals.purchase_amount) }}</dd>
            </div>
        </template>
    </dl>
</template>
