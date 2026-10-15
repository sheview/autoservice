<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string; overdue?: boolean }>();

const classes: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    scheduled: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    revision_required: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    submitted: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    pending_approval: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    approved: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    published: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    closed: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`pm_visits.statuses.${status}`) }}</span>
        <span v-if="overdue" class="whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">
            {{ t('pm_visits.overdue') }}
        </span>
    </span>
</template>
