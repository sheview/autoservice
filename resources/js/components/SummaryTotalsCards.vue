<script setup lang="ts">
import StatTile from '@/components/StatTile.vue';
import { t } from '@/lib/i18n';
import type { SummaryTotals } from '@/types/summary';
import { Banknote, HandCoins, PackageOpen, ShoppingCart } from 'lucide-vue-next';

// The top of a person's or project's summary: issues, loans and purchases, with how many are still open.
defineProps<{ totals: SummaryTotals; purchases?: boolean }>();

const money = (baht: string | undefined) => Number(baht ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 2 });
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile :label="t('summary.issues')" :value="totals.issues" :icon="PackageOpen" tone="blue">
            <div class="text-xs text-amber-700 dark:text-amber-400">{{ t('summary.open_count', { count: totals.issues_open }) }}</div>
        </StatTile>
        <StatTile :label="t('summary.loans')" :value="totals.loans" :icon="HandCoins" tone="teal">
            <div class="text-xs text-amber-700 dark:text-amber-400">{{ t('summary.open_count', { count: totals.loans_open }) }}</div>
        </StatTile>
        <template v-if="purchases !== false">
            <StatTile :label="t('summary.purchases')" :value="totals.purchases ?? 0" :icon="ShoppingCart" tone="violet">
                <div class="text-xs text-amber-700 dark:text-amber-400">{{ t('summary.open_count', { count: totals.purchases_open ?? 0 }) }}</div>
            </StatTile>
            <StatTile :label="t('summary.purchase_amount')" :value="money(totals.purchase_amount)" :icon="Banknote" tone="emerald" />
        </template>
    </div>
</template>
