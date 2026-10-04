<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Label, type LabelProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

// required: marks a field that must be filled in with a red asterisk.
const props = defineProps<LabelProps & { class?: HTMLAttributes['class']; required?: boolean }>();

const delegatedProps = computed(() => {
    const { class: _, required: __, ...delegated } = props;

    return delegated;
});
</script>

<template>
    <Label
        v-bind="delegatedProps"
        :class="cn('text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70', props.class)"
    >
        <slot />
        <span v-if="required" class="ml-0.5 text-red-600" aria-hidden="true">*</span>
    </Label>
</template>
