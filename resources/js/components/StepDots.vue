<script setup lang="ts">
import type { Step } from '@/components/StepProgress.vue';
import { t } from '@/lib/i18n';
import { Check, X } from 'lucide-vue-next';

// The small form of StepProgress for a table cell: numbered dots joined by a line, with the step
// name (and when it was reached) on hover. Same meaning of current and state as StepProgress.
const props = withDefaults(defineProps<{ steps: Step[]; current: number; state?: 'active' | 'done' | 'paused' | 'cancelled' }>(), {
    state: 'active',
});

const done = (index: number) => index < props.current || (index === props.current && props.state === 'done');
const dotClass = (index: number) => {
    if (done(index)) return 'bg-green-600 text-white';
    if (index !== props.current) return 'bg-muted text-muted-foreground';
    if (props.state === 'cancelled') return 'bg-red-600 text-white';
    if (props.state === 'paused') return 'bg-amber-500 text-white';
    return 'border-2 border-green-600 text-green-700 dark:text-green-400';
};
</script>

<template>
    <ol class="flex items-center" :aria-label="t('steps.label')">
        <li v-for="(step, index) in steps" :key="step.key" class="flex items-center" :title="step.at ? `${step.label} · ${step.at}` : step.label">
            <span v-if="index > 0" class="h-0.5 w-4" :class="index <= current ? 'bg-green-600' : 'bg-muted'" aria-hidden="true" />
            <span class="flex size-5 items-center justify-center rounded-full text-[10px] font-semibold" :class="dotClass(index)">
                <Check v-if="done(index)" class="size-3" />
                <X v-else-if="index === current && state === 'cancelled'" class="size-3" />
                <template v-else>{{ index + 1 }}</template>
                <span class="sr-only">{{ step.label }}</span>
            </span>
        </li>
    </ol>
</template>
