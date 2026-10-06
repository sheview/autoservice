<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Eraser } from 'lucide-vue-next';
import { nextTick, onMounted, ref } from 'vue';

/**
 * A signature drawn with a finger or a pen on the screen (phone, iPad), as a PNG data URL;
 * null until something is drawn, and again after "clear".
 */
const model = defineModel<string | null>({ required: true });

const pad = ref<HTMLCanvasElement | null>(null);
let drawing = false;
const point = (e: PointerEvent): [number, number] => {
    const r = pad.value!.getBoundingClientRect();
    return [((e.clientX - r.left) * pad.value!.width) / r.width, ((e.clientY - r.top) * pad.value!.height) / r.height];
};
const down = (e: PointerEvent) => {
    drawing = true;
    const ctx = pad.value!.getContext('2d')!;
    ctx.beginPath();
    ctx.moveTo(...point(e));
    pad.value!.setPointerCapture(e.pointerId);
};
const move = (e: PointerEvent) => {
    if (!drawing) return;
    const ctx = pad.value!.getContext('2d')!;
    ctx.lineTo(...point(e));
    ctx.stroke();
};
const up = () => {
    if (!drawing) return;
    drawing = false;
    model.value = pad.value!.toDataURL('image/png');
};
const clear = () => {
    pad.value!.getContext('2d')!.clearRect(0, 0, pad.value!.width, pad.value!.height);
    model.value = null;
};
onMounted(async () => {
    await nextTick();
    const ctx = pad.value?.getContext('2d');
    if (ctx) {
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111827';
    }
});
</script>

<template>
    <div class="space-y-1">
        <canvas
            ref="pad"
            width="800"
            height="300"
            class="h-44 w-full touch-none rounded-xl border-2 border-dashed bg-white"
            @pointerdown="down"
            @pointermove="move"
            @pointerup="up"
            @pointerleave="up"
        />
        <button type="button" class="flex min-h-11 items-center gap-2 text-sm text-muted-foreground" @click="clear">
            <Eraser class="size-4" />{{ t('close.clear') }}
        </button>
    </div>
</template>
