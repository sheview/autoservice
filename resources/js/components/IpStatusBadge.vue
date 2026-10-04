<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string }>();

// Few colours: free stands out, conflict warns, the rest stay quiet.
const classes: Record<string, string> = {
    available: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    in_use: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    reserved: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    excluded: 'border border-dashed border-muted-foreground/40 text-muted-foreground',
    conflict: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`ip_check.statuses.${status}`) }}</span>
</template>
