<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Check, Pause, X } from 'lucide-vue-next';

export interface Step {
    key: string;
    label: string;
    // When the step was reached, already formatted for display.
    at?: string | null;
}

/**
 * Horizontal step bar: steps before `current` are done, `current` is where the work is now.
 * state "done" marks `current` itself as done (the whole process finished),
 * "paused" / "cancelled" mark the work as stopped at `current`.
 */
const props = withDefaults(defineProps<{ steps: Step[]; current: number; state?: 'active' | 'done' | 'paused' | 'cancelled' }>(), {
    state: 'active',
});

const isDone = (index: number) => index < props.current || (index === props.current && props.state === 'done');

const circleClass = (index: number) => {
    if (isDone(index)) {
        return 'bg-green-600 text-white';
    }
    if (index !== props.current) {
        return 'bg-muted text-muted-foreground';
    }
    return {
        active: 'border-2 border-green-600 bg-background text-green-700 dark:text-green-400',
        paused: 'bg-amber-500 text-white',
        cancelled: 'bg-red-600 text-white',
        done: '',
    }[props.state];
};

// The line into a step is green once the work has reached that step.
const lineClass = (index: number) => (index <= props.current ? 'bg-green-600' : 'bg-muted');
</script>

<template>
    <nav :aria-label="t('steps.label')" class="rounded-md border px-2 py-4">
        <ol class="flex">
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="relative flex flex-1 flex-col items-center gap-1 px-1 text-center"
                :aria-current="index === current && state !== 'done' ? 'step' : undefined"
            >
                <span v-if="index > 0" class="absolute right-1/2 top-[13px] h-0.5 w-full" :class="lineClass(index)" aria-hidden="true" />
                <span class="relative z-10 flex size-7 items-center justify-center rounded-full text-xs font-semibold" :class="circleClass(index)">
                    <Check v-if="isDone(index)" class="size-4" />
                    <Pause v-else-if="index === current && state === 'paused'" class="size-3.5" />
                    <X v-else-if="index === current && state === 'cancelled'" class="size-4" />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <span class="text-xs leading-tight" :class="index === current ? 'font-semibold' : 'text-muted-foreground'">{{ step.label }}</span>
                <span
                    v-if="index === current && (state === 'paused' || state === 'cancelled')"
                    class="text-xs"
                    :class="state === 'paused' ? 'text-amber-600' : 'text-red-600'"
                >
                    {{ t(`steps.${state}`) }}
                </span>
                <span v-else-if="step.at" class="hidden text-[11px] text-muted-foreground sm:block">{{ step.at }}</span>
            </li>
        </ol>
    </nav>
</template>
