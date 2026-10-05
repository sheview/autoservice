<script setup lang="ts">
import { t } from '@/lib/i18n';

/** The status of a server room request, coloured. */
defineProps<{ status: string }>();

const colours: Record<string, string> = {
    draft: 'bg-muted text-foreground',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    inside: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    exited: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    cancelled: 'bg-muted text-muted-foreground',
    overdue: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
</script>

<template>
    <span class="whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium" :class="colours[status] ?? 'bg-muted'">{{
        t(`room_requests.statuses.${status}`)
    }}</span>
</template>
