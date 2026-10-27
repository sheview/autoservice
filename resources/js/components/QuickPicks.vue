<script setup lang="ts">
import { Check, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

// Values used most for a field, as buttons under it: a tap fills the field (typing still works).
const props = withDefaults(defineProps<{ options: string[]; max?: number }>(), { max: 12 });
const model = defineModel<string | null>();

const shown = computed(() => props.options.filter((option) => option.trim() !== '').slice(0, props.max));
const isPicked = (option: string) => (model.value ?? '').trim().toLowerCase() === option.trim().toLowerCase();
</script>

<template>
    <div v-if="shown.length" class="flex flex-wrap gap-1.5">
        <button
            v-for="option in shown"
            :key="option"
            type="button"
            class="inline-flex min-h-9 items-center gap-1 rounded-full border px-3 text-xs sm:min-h-7"
            :class="
                isPicked(option)
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-dashed text-muted-foreground hover:border-primary hover:text-primary'
            "
            :aria-pressed="isPicked(option)"
            @click="model = option"
        >
            <Check v-if="isPicked(option)" class="size-3" />
            <Plus v-else class="size-3" />
            {{ option }}
        </button>
    </div>
</template>
