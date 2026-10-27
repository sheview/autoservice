<script setup lang="ts">
import CameraCapture from '@/components/CameraCapture.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { PhotoError, prepareImage } from '@/lib/image';
import { Camera, Images, Plus, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Photos picked on a create form, before the record exists: made smaller at once (prepareImage)
 * and kept in the v-model; the form hands them to the record's page (lib/photoUpload:
 * pendingPhotos), which uploads them once saved. The first one is the main photo.
 * Each photo as taken (full size) is also emitted as "picked", for reading its barcodes.
 */
const props = withDefaults(defineProps<{ max?: number; hint?: string }>(), { max: 4 });
const emit = defineEmits<{ picked: [file: File] }>();
const files = defineModel<File[]>({ required: true });

const previews = ref<string[]>([]);
const error = ref<string | null>(null);
const camera = ref<InstanceType<typeof CameraCapture> | null>(null);
// A phone or tablet: the camera first (inside the page), the gallery as the second button.
const touch = typeof window !== 'undefined' && (navigator.maxTouchPoints > 0 || !!window.matchMedia?.('(any-pointer: coarse)').matches);

watch(
    files,
    (list, _, onCleanup) => {
        previews.value = list.map((file) => URL.createObjectURL(file));
        const made = previews.value;
        onCleanup(() => made.forEach((url) => URL.revokeObjectURL(url)));
    },
    { immediate: true },
);
onBeforeUnmount(() => previews.value.forEach((url) => URL.revokeObjectURL(url)));

const label = (index: number) => (index === 0 ? t('photos.main') : t('photos.extra', { number: index }));

const addFiles = async (picked: File[]) => {
    error.value = null;
    const room = props.max - files.value.length;
    const problems: string[] = picked.length > room ? [t('photos.too_many', { max: props.max })] : [];
    const ready: File[] = [];
    for (const file of picked.slice(0, Math.max(room, 0))) {
        try {
            ready.push(await prepareImage(file));
            emit('picked', file);
        } catch (e) {
            problems.push(e instanceof PhotoError ? e.message : t('photos.failed'));
        }
    }
    files.value = [...files.value, ...ready];
    error.value = problems.join(' ') || null;
};

const add = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = '';
    addFiles(picked);
};

const remove = (index: number) => (files.value = files.value.filter((_, i) => i !== index));
</script>

<template>
    <section class="space-y-3">
        <h3 class="text-sm font-semibold">{{ t('photos.title') }}</h3>
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        <div class="grid grid-cols-2 gap-4 sm:flex sm:flex-wrap">
            <div v-for="(url, index) in previews" :key="url" class="text-center text-xs sm:w-32">
                <img :src="url" :alt="label(index)" class="aspect-square w-full rounded-lg border object-cover" />
                <p class="mt-1 text-muted-foreground">{{ label(index) }}</p>
                <Button type="button" variant="outline" class="mt-1 min-h-11 w-full text-red-700 dark:text-red-400" @click="remove(index)">
                    <X class="size-4" />
                    {{ t('photos.remove') }}
                </Button>
            </div>
            <div v-if="files.length < max" class="grid content-start gap-1 text-center text-xs sm:w-32">
                <!-- Phone: the box opens the camera in the page (works where capture="environment" is ignored) -->
                <button
                    v-if="touch"
                    type="button"
                    class="flex aspect-square w-full flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-muted-foreground hover:border-primary hover:text-primary"
                    @click="camera?.open()"
                >
                    <Camera class="size-6" />
                    <span class="text-sm font-medium">{{ t('photos.take') }}</span>
                </button>
                <label
                    v-else
                    class="flex aspect-square w-full cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-muted-foreground hover:border-primary hover:text-primary"
                >
                    <Plus class="size-6" />
                    <span class="text-sm font-medium">{{ t('photos.add') }}</span>
                    <input type="file" accept="image/*" multiple class="sr-only" @change="add" />
                </label>
                <label
                    v-if="touch"
                    class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-md border px-2 text-sm font-medium hover:bg-accent"
                >
                    <Images class="size-4" />
                    {{ t('photos.gallery') }}
                    <input type="file" accept="image/*" multiple class="sr-only" @change="add" />
                </label>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('photos.main_first') }} · {{ t('photos.hint') }}</p>
        <InputError :message="error ?? undefined" />
        <CameraCapture ref="camera" :max="max - files.length" @taken="(file) => addFiles([file])" />
    </section>
</template>
