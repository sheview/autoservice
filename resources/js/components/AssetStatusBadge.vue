<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ status: string }>();

const classes: Record<string, string> = {
    in_use: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    spare: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    in_repair: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    retired: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="classes[status]">{{ t(`assets.statuses.${status}`) }}</span>
</template>
