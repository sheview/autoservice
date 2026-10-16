<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed } from 'vue';

export type StatTone = 'violet' | 'indigo' | 'blue' | 'sky' | 'teal' | 'emerald' | 'amber' | 'orange' | 'rose' | 'slate';

/**
 * A figure on a dashboard: a coloured strip and icon by its tone, the label and the number.
 * "alert" (something needs doing now) turns it red, whatever its tone. A link when "href" is given.
 */
const props = withDefaults(
    defineProps<{ label: string; value: string | number; icon?: Component; tone?: StatTone; href?: string; alert?: boolean; hint?: string }>(),
    { tone: 'violet', icon: undefined, href: undefined, alert: false, hint: undefined },
);

// Whole class names, so Tailwind keeps them.
const TONES: Record<StatTone, { strip: string; icon: string }> = {
    violet: { strip: 'from-violet-500 to-fuchsia-400', icon: 'bg-violet-100 text-violet-600 dark:bg-violet-950 dark:text-violet-300' },
    indigo: { strip: 'from-indigo-500 to-violet-400', icon: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-300' },
    blue: { strip: 'from-blue-500 to-cyan-400', icon: 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300' },
    sky: { strip: 'from-sky-500 to-cyan-300', icon: 'bg-sky-100 text-sky-600 dark:bg-sky-950 dark:text-sky-300' },
    teal: { strip: 'from-teal-500 to-emerald-300', icon: 'bg-teal-100 text-teal-600 dark:bg-teal-950 dark:text-teal-300' },
    emerald: { strip: 'from-emerald-500 to-lime-300', icon: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300' },
    amber: { strip: 'from-amber-400 to-yellow-300', icon: 'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-300' },
    orange: { strip: 'from-orange-500 to-amber-300', icon: 'bg-orange-100 text-orange-600 dark:bg-orange-950 dark:text-orange-300' },
    rose: { strip: 'from-rose-500 to-pink-400', icon: 'bg-rose-100 text-rose-600 dark:bg-rose-950 dark:text-rose-300' },
    slate: { strip: 'from-slate-400 to-slate-300', icon: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
};

const tone = computed(() => TONES[props.alert ? 'rose' : props.tone]);
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="group relative flex items-center gap-3 overflow-hidden rounded-xl border bg-card p-4 shadow-sm transition"
        :class="[
            href ? 'hover:-translate-y-0.5 hover:shadow-md' : '',
            alert ? 'border-rose-200 bg-rose-50/60 dark:border-rose-900 dark:bg-rose-950/30' : '',
        ]"
    >
        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r" :class="tone.strip" />
        <span v-if="icon" class="flex size-11 shrink-0 items-center justify-center rounded-xl" :class="tone.icon">
            <component :is="icon" class="size-5" />
        </span>
        <div class="min-w-0">
            <div class="line-clamp-2 text-sm leading-snug text-muted-foreground">{{ label }}</div>
            <div class="text-2xl font-bold tabular-nums leading-tight" :class="{ 'text-rose-700 dark:text-rose-400': alert }">{{ value }}</div>
            <div v-if="hint" class="truncate text-xs text-muted-foreground">{{ hint }}</div>
            <!-- Extra lines under the number (a breakdown, a note) -->
            <slot />
        </div>
    </component>
</template>
