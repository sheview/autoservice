<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Star } from 'lucide-vue-next';

// Shows a score as stars; with "editable" the stars are buttons that set it.
const props = withDefaults(defineProps<{ modelValue: number | null; max?: number; editable?: boolean; size?: 'sm' | 'lg' }>(), {
    max: 5,
    editable: false,
    size: 'sm',
});

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

const starClass = (star: number) => [
    props.size === 'lg' ? 'size-9' : 'size-4',
    star <= (props.modelValue ?? 0) ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/40',
];
</script>

<template>
    <span
        class="inline-flex items-center"
        :class="size === 'lg' ? 'gap-1' : 'gap-0.5'"
        :role="editable ? 'radiogroup' : 'img'"
        :aria-label="modelValue ? t('ticket_survey.stars', { score: modelValue, max }) : undefined"
    >
        <template v-for="star in max" :key="star">
            <button
                v-if="editable"
                type="button"
                role="radio"
                class="rounded p-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-checked="star === modelValue"
                :aria-label="t('ticket_survey.stars', { score: star, max })"
                @click="emit('update:modelValue', star)"
            >
                <Star :class="starClass(star)" />
            </button>
            <Star v-else :class="starClass(star)" />
        </template>
    </span>
</template>
