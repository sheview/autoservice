<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string }>();

const classes: Record<string, string> = {
    pending_review: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    new: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    assigned: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    on_hold: 'bg-muted text-muted-foreground',
    resolved: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    closed: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`tickets.statuses.${status}`) }}</span>
</template>
