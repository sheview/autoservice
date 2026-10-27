<script setup lang="ts">
import CameraCapture from '@/components/CameraCapture.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { PhotoError, prepareImage } from '@/lib/image';
import { pendingPhotos, uploadPhoto } from '@/lib/photoUpload';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Camera, Images, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// A record's photos as a fixed row of slots: the first is the main photo, the rest are extras.
// "action" is the address of a slot: GET shows it, POST uploads (replaces), DELETE removes.
interface PhotoSlot {
    slot: number;
    action: string;
    url: string | null;
}

const props = defineProps<{
    photos: PhotoSlot[];
    editable: boolean;
    // Photos picked on the create form of this record (lib/photoUpload: pendingPhotos), put into
    // the empty slots in order once the page opens.
    pendingKey?: string;
}>();

const page = usePage<SharedData>();
const busy = ref(false);
const progress = ref<{ done: number; total: number } | null>(null);
const error = ref<string | null>(null);
// Uploads that did not go through, kept to try again without picking the files again.
const failed = ref<{ slot: number; file: File; message: string }[]>([]);
// The failures are of photos picked when the record was created (it is saved, the photos are not).
const fromCreate = ref(false);

// A phone or tablet: an empty slot opens the camera in the page (CameraCapture: works where
// capture="environment" is ignored), with the gallery as the second button.
const touch = typeof window !== 'undefined' && (navigator.maxTouchPoints > 0 || !!window.matchMedia?.('(any-pointer: coarse)').matches);
const camera = ref<InstanceType<typeof CameraCapture> | null>(null);
// The slot the camera is taking a photo for.
const cameraSlot = ref(0);
const takePhoto = (slot: number) => {
    cameraSlot.value = slot;
    camera.value?.open();
};

const label = (slot: number) => (slot === 0 ? t('photos.main') : t('photos.extra', { number: slot }));
const actionOf = (slot: number) => props.photos.find((photo) => photo.slot === slot)!.action;

/** Sends each photo to its slot in turn (already made smaller), then shows the new photos. */
const send = async (jobs: { slot: number; file: File }[]) => {
    busy.value = true;
    progress.value = jobs.length > 1 ? { done: 0, total: jobs.length } : null;
    const missed: typeof failed.value = [];
    for (const job of jobs) {
        try {
            await uploadPhoto(actionOf(job.slot), job.file);
        } catch (e) {
            missed.push({ ...job, message: (e as Error).message });
        }
        if (progress.value) progress.value.done++;
    }
    failed.value = missed;
    busy.value = false;
    progress.value = null;
    router.reload({ only: ['photos'] });
};

const pick = (slot: number, event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) pickFile(slot, file);
};

const pickFile = async (slot: number, file: File) => {
    error.value = null;
    fromCreate.value = false;
    try {
        await send([{ slot, file: await prepareImage(file) }]);
    } catch (e) {
        error.value = e instanceof PhotoError ? e.message : t('photos.failed');
    }
};

const retry = () => send(failed.value.map(({ slot, file }) => ({ slot, file })));

const remove = (photo: PhotoSlot) => {
    if (confirm(t('common.confirm_delete', { name: label(photo.slot) }))) {
        router.delete(photo.action, { preserveScroll: true });
    }
};

// Photos from the create form: into the empty slots, the first one as the main photo.
watch(
    () => pendingPhotos.value,
    (pending) => {
        if (!props.pendingKey || !pending || pending.key !== props.pendingKey) return;
        pendingPhotos.value = null;
        const empty = props.photos.filter((photo) => !photo.url).map((photo) => photo.slot);
        fromCreate.value = true;
        send(pending.files.slice(0, empty.length).map((file, i) => ({ slot: empty[i], file })));
    },
    { immediate: true },
);

const serverError = computed(() => (page.props.errors as Record<string, string>).photo);
</script>

<template>
    <section class="space-y-3">
        <h3 class="text-sm font-semibold">{{ t('photos.title') }}</h3>

        <div class="grid grid-cols-2 gap-4 sm:flex sm:flex-wrap">
            <div v-for="photo in photos" :key="photo.slot" class="text-center text-xs sm:w-32">
                <a
                    v-if="photo.url"
                    :href="photo.url"
                    target="_blank"
                    rel="noopener"
                    class="block aspect-square w-full overflow-hidden rounded-lg border"
                    :title="label(photo.slot)"
                >
                    <img :src="photo.url" :alt="label(photo.slot)" class="size-full object-cover" loading="lazy" />
                </a>
                <!-- Empty, phone: the box opens the camera -->
                <button
                    v-else-if="editable && touch"
                    type="button"
                    class="flex aspect-square w-full flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-muted-foreground hover:border-primary hover:text-primary"
                    :disabled="busy"
                    :class="{ 'opacity-50': busy }"
                    @click="takePhoto(photo.slot)"
                >
                    <Camera class="size-6" />
                    <span class="text-sm font-medium">{{ t('photos.take') }}</span>
                </button>
                <!-- Empty, computer: the box picks a file -->
                <label
                    v-else-if="editable"
                    class="flex aspect-square w-full cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-muted-foreground hover:border-primary hover:text-primary"
                    :class="{ 'pointer-events-none opacity-50': busy }"
                >
                    <Plus class="size-6" />
                    <span class="text-sm font-medium">{{ t('photos.add') }}</span>
                    <input type="file" accept="image/*" class="sr-only" :disabled="busy" @change="pick(photo.slot, $event)" />
                </label>
                <div v-else class="flex aspect-square w-full items-center justify-center rounded-lg border border-dashed bg-muted/40" />

                <p class="mt-1 text-muted-foreground">{{ label(photo.slot) }}</p>

                <div v-if="editable" class="mt-1 grid gap-1">
                    <label
                        v-if="touch || photo.url"
                        class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-md border px-2 text-sm font-medium hover:bg-accent"
                        :class="{ 'pointer-events-none opacity-50': busy }"
                    >
                        <Images v-if="!photo.url" class="size-4" />
                        {{ photo.url ? t('photos.replace') : t('photos.gallery') }}
                        <!-- Empty slot on a phone: the gallery; a filled one: camera or gallery as the phone offers -->
                        <input type="file" accept="image/*" class="sr-only" :disabled="busy" @change="pick(photo.slot, $event)" />
                    </label>
                    <Button
                        v-if="photo.url"
                        type="button"
                        variant="outline"
                        class="min-h-11 text-red-700 dark:text-red-400"
                        :disabled="busy"
                        @click="remove(photo)"
                    >
                        {{ t('photos.remove') }}
                    </Button>
                </div>
            </div>
        </div>

        <p v-if="progress" class="text-sm text-muted-foreground" aria-live="polite">{{ t('photos.progress', progress) }}</p>
        <p v-else-if="busy" class="text-sm text-muted-foreground" aria-live="polite">{{ t('photos.uploading') }}</p>
        <p v-else-if="editable" class="text-xs text-muted-foreground">{{ t('photos.hint') }}</p>

        <div
            v-if="failed.length"
            role="alert"
            class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
        >
            <p class="font-medium">
                {{ fromCreate ? t('photos.saved_but_failed', { count: failed.length }) : t('photos.failed') }}
            </p>
            <ul class="list-inside list-disc text-xs">
                <li v-for="item in failed" :key="item.slot">{{ label(item.slot) }}: {{ item.message }}</li>
            </ul>
            <div class="flex flex-wrap gap-2">
                <Button type="button" class="min-h-11" :disabled="busy" @click="retry">{{ t('photos.retry') }}</Button>
                <Button type="button" variant="outline" class="min-h-11" :disabled="busy" @click="failed = []">{{ t('photos.skip') }}</Button>
            </div>
        </div>
        <InputError :message="error ?? serverError" />
        <CameraCapture ref="camera" @taken="(file) => pickFile(cameraSlot, file)" />
    </section>
</template>
