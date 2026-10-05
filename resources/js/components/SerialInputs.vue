<script setup lang="ts">
import { t } from '@/lib/i18n';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Serial numbers typed one box per piece: with a count, exactly that many boxes; without, boxes
 * are added as they fill. Enter (as a barcode scanner sends) moves to the next box; several
 * lines pasted into any box (from Excel) fill it and the boxes after it.
 */
const props = defineProps<{ count?: number | null; id?: string }>();
const model = defineModel<string[]>({ required: true });

const boxes = ref<HTMLInputElement[]>([]);
const rows = computed(() => {
    if (props.count != null) return Math.max(props.count, 0);
    return Math.max(model.value.length + 1, 1);
});
const filled = computed(() => model.value.filter((serial) => serial.trim() !== '').length);

// Keep exactly one value per box when a count is given.
watch(
    () => props.count,
    (count) => {
        if (count != null && model.value.length !== count) {
            model.value = Array.from({ length: count }, (_, i) => model.value[i] ?? '');
        }
    },
    { immediate: true },
);

const set = (index: number, value: string) => {
    const next = [...model.value];
    while (next.length <= index) next.push('');
    next[index] = value;
    model.value = props.count != null ? next.slice(0, props.count) : trimEnd(next);
};
const trimEnd = (list: string[]) => {
    const copy = [...list];
    while (copy.length && copy[copy.length - 1].trim() === '') copy.pop();
    return copy;
};

const focus = async (index: number) => {
    await nextTick();
    boxes.value[index]?.focus();
};

const onPaste = (index: number, event: ClipboardEvent) => {
    const text = event.clipboardData?.getData('text') ?? '';
    const lines = text.split(/[\r\n\t,;]+/).map((line) => line.trim()).filter(Boolean);
    if (lines.length < 2) return;
    event.preventDefault();
    const next = [...model.value];
    lines.forEach((line, i) => {
        while (next.length <= index + i) next.push('');
        next[index + i] = line;
    });
    model.value = props.count != null ? next.slice(0, Math.max(props.count, 0)) : trimEnd(next);
    focus(Math.min(index + lines.length, rows.value - 1));
};

const clear = () => {
    model.value = props.count != null ? Array.from({ length: props.count }, () => '') : [];
    focus(0);
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-sm font-medium">
                {{ count != null ? t('part_units.serials_label', { count }) : t('part_units.serials_label_free') }}
            </span>
            <span class="text-xs" :class="count != null && filled !== count ? 'text-amber-700' : 'text-muted-foreground'">
                {{ count != null ? t('part_units.serials_count', { given: filled, count }) : t('part_units.serials_count_free', { given: filled }) }}
                · <button type="button" class="underline-offset-4 hover:underline" @click="clear">{{ t('part_units.clear') }}</button>
            </span>
        </div>
        <div class="grid max-h-80 gap-1.5 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="index in rows" :key="index" class="flex items-center gap-2">
                <span class="w-8 shrink-0 text-right text-xs text-muted-foreground">{{ index }}.</span>
                <input
                    :id="index === 1 ? id : undefined"
                    :ref="(el) => (boxes[index - 1] = el as HTMLInputElement)"
                    :value="model[index - 1] ?? ''"
                    maxlength="100"
                    autocomplete="off"
                    class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 font-mono text-sm"
                    @input="set(index - 1, ($event.target as HTMLInputElement).value)"
                    @paste="onPaste(index - 1, $event)"
                    @keydown.enter.prevent="focus(index)"
                />
            </div>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('part_units.serials_hint') }}</p>
    </div>
</template>
