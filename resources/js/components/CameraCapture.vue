<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Camera, X } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref } from 'vue';

/**
 * Takes photos with the back camera inside the page (open()), so the camera opens on every
 * phone: a file input with capture="environment" is ignored by some browsers and in-app browsers
 * (LINE, Facebook), and Android's photo picker then shows only the gallery. Up to `max` photos per
 * opening, each emitted as a JPEG at the camera's resolution. When the page cannot use the camera
 * (permission refused, no https) the phone's camera app is offered instead.
 */
const props = withDefaults(defineProps<{ max?: number }>(), { max: 1 });
const emit = defineEmits<{ taken: [file: File] }>();

const shown = ref(false);
const error = ref(false);
const busy = ref(false);
const count = ref(0);
const video = ref<HTMLVideoElement | null>(null);
let stream: MediaStream | null = null;
// How many photos this opening takes (`max` as it was when opened).
const limit = ref(1);

const stopStream = () => {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
};

const close = () => {
    stopStream();
    shown.value = false;
};

const open = async () => {
    error.value = false;
    count.value = 0;
    limit.value = props.max;
    shown.value = true;
    try {
        if (!navigator.mediaDevices?.getUserMedia) throw new Error('no camera');
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 2560 }, height: { ideal: 1440 } },
            audio: false,
        });
        if (!shown.value) return stopStream(); // closed while asking for the camera
        await nextTick();
        video.value!.srcObject = stream;
        await video.value!.play();
    } catch {
        stopStream();
        error.value = true;
    }
};

const shoot = async () => {
    const el = video.value;
    if (!el || !el.videoWidth || busy.value) return;
    busy.value = true;
    try {
        const canvas = document.createElement('canvas');
        canvas.width = el.videoWidth;
        canvas.height = el.videoHeight;
        canvas.getContext('2d')!.drawImage(el, 0, 0);
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
        if (blob) {
            navigator.vibrate?.(40);
            count.value++;
            emit('taken', new File([blob], `photo-${Date.now()}.jpg`, { type: 'image/jpeg', lastModified: Date.now() }));
        }
    } finally {
        busy.value = false;
    }
    if (count.value >= limit.value) close();
};

// The phone's camera app (when the page cannot use the camera).
const fromApp = (event: Event) => {
    const input = event.target as HTMLInputElement;
    Array.from(input.files ?? [])
        .slice(0, limit.value - count.value)
        .forEach((file) => emit('taken', file));
    input.value = '';
    close();
};

onBeforeUnmount(stopStream);
defineExpose({ open });
</script>

<template>
    <Teleport to="body">
        <div v-if="shown" class="fixed inset-0 z-50 flex flex-col bg-black text-white" role="dialog" :aria-label="t('camera.title')">
            <div class="flex items-center justify-between gap-2 p-2">
                <span class="px-2 text-sm">{{ limit > 1 ? t('camera.count', { count, max: limit }) : t('camera.title') }}</span>
                <button
                    type="button"
                    class="flex size-11 shrink-0 items-center justify-center rounded-full"
                    :aria-label="t('common.cancel')"
                    @click="close"
                >
                    <X class="size-6" />
                </button>
            </div>

            <div v-if="error" class="m-auto max-w-sm space-y-4 p-4 text-center">
                <p class="text-sm">{{ t('camera.unavailable') }}</p>
                <label
                    class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-white px-4 text-sm font-medium text-black"
                >
                    <Camera class="size-4" />
                    {{ t('camera.native') }}
                    <input type="file" accept="image/*" capture="environment" class="sr-only" @change="fromApp" />
                </label>
            </div>
            <video v-else ref="video" class="min-h-0 w-full flex-1 object-contain" playsinline muted />

            <div v-if="!error" class="flex items-center justify-between gap-4 p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <span class="w-20" />
                <button
                    type="button"
                    class="size-[4.5rem] rounded-full border-4 border-white bg-white/30 active:bg-white/70 disabled:opacity-50"
                    :disabled="busy"
                    :aria-label="t('camera.shoot')"
                    @click="shoot"
                />
                <button
                    type="button"
                    class="min-h-11 w-20 rounded-md text-sm font-medium"
                    :class="count ? 'bg-white text-black' : 'invisible'"
                    @click="close"
                >
                    {{ t('camera.done') }}
                </button>
            </div>
        </div>
    </Teleport>
</template>
