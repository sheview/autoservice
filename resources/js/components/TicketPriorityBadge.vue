<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ priority: string }>();

const classes: Record<string, string> = {
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    medium: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200',
    low: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <span class="whitespace-nowrap rounded px-1.5 py-0.5 text-xs" :class="classes[priority]">{{ t(`tickets.priorities.${priority}`) }}</span>
</template>
