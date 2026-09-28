<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ phase: string }>();

const classes: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    upcoming: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    expiring: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    expired: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[phase]">{{ t(`contracts.phases.${phase}`) }}</span>
</template>
