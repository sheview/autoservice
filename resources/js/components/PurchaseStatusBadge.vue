<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string }>();

const classes: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    ordered: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    partially_received: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950 dark:text-cyan-200',
    received: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    registered: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    issued: 'bg-emerald-200 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`purchase_requests.statuses.${status}`) }}</span>
</template>
