<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ type: string }>();

const classes: Record<string, string> = {
    receive: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    issue: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    loan: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    spare: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    return: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    adjust: 'bg-muted text-foreground',
};
</script>

<template>
    <span class="whitespace-nowrap rounded px-1.5 py-0.5 text-xs" :class="classes[type] ?? 'bg-muted'">
        {{ t(`stock_movements.types.${type}`) }}
    </span>
</template>
