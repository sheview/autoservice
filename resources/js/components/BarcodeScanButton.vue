<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { IScannerControls } from '@zxing/browser';
import { Camera, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Reads a barcode or QR code with the phone's camera (back camera when there is one) and emits
 * its text, then closes. With `continuous` it stays open and emits every new code (one device
 * after another) until "done"; the same code is not sent twice. The reader is loaded only when
 * the camera opens. The camera needs HTTPS (or localhost).
 */
const props = defineProps<{ continuous?: boolean; hint?: string; label?: string }>();
const emit = defineEmits<{ scanned: [text: string] }>();

const open = ref(false);
const error = ref('');
const video = ref<HTMLVideoElement | null>(null);
// Continuous: what was read so far in this opening.
const read = ref<string[]>([]);
let controls: IScannerControls | null = null;

const stop = () => {
    controls?.stop();
    controls = null;
    open.value = false;
};

const take = (text: string) => {
    if (!text) return;
    if (props.continuous) {
        if (read.value.includes(text)) return;
        read.value.push(text);
        navigator.vibrate?.(60);
        emit('scanned', text);
        return;
    }
    navigator.vibrate?.(60);
    emit('scanned', text);
    stop();
};

const start = async () => {
    error.value = '';
    read.value = [];
    open.value = true;
    try {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        const reader = new BrowserMultiFormatReader();
        controls = await reader.decodeFromConstraints({ video: { facingMode: { ideal: 'environment' } } }, video.value!, (result) => {
            if (result) take(result.getText().trim());
        });
    } catch {
        error.value = t('scan_camera.unavailable');
        controls?.stop();
        controls = null;
    }
};

onBeforeUnmount(stop);
</script>

<template>
    <button
        type="button"
        class="inline-flex min-h-11 shrink-0 items-center justify-center gap-1.5 rounded-md border border-input px-3 text-sm sm:min-h-9"
        :aria-label="label ?? t('scan_camera.open')"
        @click="start"
    >
        <Camera class="size-4" />{{ label ?? t('scan_camera.open') }}
    </button>

    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex flex-col bg-black/90 p-4">
            <div class="flex items-center justify-between gap-2 text-white">
                <span class="text-sm">{{ hint ?? t('scan_camera.hint') }}</span>
                <button type="button" class="flex size-11 shrink-0 items-center justify-center rounded-full" :aria-label="t('common.cancel')" @click="stop">
                    <X class="size-6" />
                </button>
            </div>
            <video ref="video" class="mx-auto mt-4 w-full max-w-md rounded-lg" playsinline muted />
            <p v-if="error" class="mt-4 text-center text-sm text-red-300">{{ error }}</p>
            <!-- Continuous: what was read, and done -->
            <div v-if="continuous" class="mx-auto mt-4 w-full max-w-md space-y-3 text-white" aria-live="polite">
                <p class="text-sm">{{ t('scan_camera.read_count', { count: read.length }) }}</p>
                <p v-if="read.length" class="truncate font-mono text-xs opacity-80">{{ read.slice(-3).join(' · ') }}</p>
                <button type="button" class="min-h-11 w-full rounded-md bg-white px-4 text-sm font-medium text-black" @click="stop">
                    {{ t('scan_camera.done') }}
                </button>
            </div>
        </div>
    </Teleport>
</template>
