<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { IScannerControls } from '@zxing/browser';
import { Camera, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Reads a barcode or QR code with the phone's camera (back camera when there is one) and emits
 * its text once, then closes. The reader is loaded only when the camera opens.
 */
const emit = defineEmits<{ scanned: [text: string] }>();

const open = ref(false);
const error = ref('');
const video = ref<HTMLVideoElement | null>(null);
let controls: IScannerControls | null = null;

const stop = () => {
    controls?.stop();
    controls = null;
    open.value = false;
};

const start = async () => {
    error.value = '';
    open.value = true;
    try {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        const reader = new BrowserMultiFormatReader();
        controls = await reader.decodeFromConstraints({ video: { facingMode: { ideal: 'environment' } } }, video.value!, (result) => {
            if (result) {
                emit('scanned', result.getText().trim());
                stop();
            }
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
        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border border-input px-3 text-sm"
        :aria-label="t('scan_camera.open')"
        @click="start"
    >
        <Camera class="size-4" />{{ t('scan_camera.open') }}
    </button>

    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex flex-col bg-black/90 p-4">
            <div class="flex items-center justify-between text-white">
                <span class="text-sm">{{ t('scan_camera.hint') }}</span>
                <button type="button" class="rounded-full p-2" :aria-label="t('common.cancel')" @click="stop"><X class="size-6" /></button>
            </div>
            <video ref="video" class="mx-auto mt-4 w-full max-w-md rounded-lg" playsinline muted />
            <p v-if="error" class="mt-4 text-center text-sm text-red-300">{{ error }}</p>
        </div>
    </Teleport>
</template>
