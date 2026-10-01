<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string }>();

const classes: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    ordered: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    received: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`purchase_requests.statuses.${status}`) }}</span>
</template>
