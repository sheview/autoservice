<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ result: string }>();

const classes: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    ok: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    issue: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    skipped: 'bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[result]">{{ t(`pm_visits.results.${result}`) }}</span>
</template>
