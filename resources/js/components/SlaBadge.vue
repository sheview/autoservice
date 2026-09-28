<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{ state: string; label?: string }>();

const classes: Record<string, string> = {
    none: 'text-muted-foreground',
    pending: 'text-blue-700 dark:text-blue-400',
    met: 'text-green-700 dark:text-green-400',
    breached: 'font-medium text-red-700 dark:text-red-400',
    paused: 'text-muted-foreground',
};
</script>

<template>
    <span class="whitespace-nowrap text-xs" :class="classes[state]">
        <template v-if="label">{{ label }}: </template>{{ t(`tickets.sla_states.${state}`) }}
    </span>
</template>
