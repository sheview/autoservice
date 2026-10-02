<script setup lang="ts">
import { t } from '@/lib/i18n';

// The status of an issue/loan request ("request") or of one of its lines ("line"), plus an
// "overdue" mark for a lent asset past its due date.
withDefaults(defineProps<{ status: string; kind?: 'request' | 'line'; overdue?: boolean }>(), { kind: 'request' });

const classes: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    partial: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    backordered: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    fulfilled: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    closed: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1">
        <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">
            {{ t(kind === 'line' ? `requests.line_statuses.${status}` : `requests.statuses.${status}`) }}
        </span>
        <span v-if="overdue" class="whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">
            {{ t('requests.overdue') }}
        </span>
    </span>
</template>
