<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string; overdue?: boolean }>();

const classes: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    returned: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`checkouts.statuses.${status}`) }}</span>
        <span v-if="overdue" class="whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">
            {{ t('checkouts.overdue') }}
        </span>
    </span>
</template>
