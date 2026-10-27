<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { PhotoError, prepareImage } from '@/lib/image';
import { Camera, Plus, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Photos picked on a create form, before the record exists: made smaller at once (prepareImage)
 * and kept in the v-model; the form hands them to the record's page (lib/photoUpload:
 * pendingPhotos), which uploads them once saved. The first one is the main photo.
 */
const props = withDefaults(defineProps<{ max?: number }>(), { max: 4 });
const files = defineModel<File[]>({ required: true });

const previews = ref<string[]>([]);
const error = ref<string | null>(null);
const touch = typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches;

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

const add = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = '';
    error.value = null;
    const room = props.max - files.value.length;
    const problems: string[] = picked.length > room ? [t('photos.too_many', { max: props.max })] : [];
    const ready: File[] = [];
    for (const file of picked.slice(0, Math.max(room, 0))) {
        try {
            ready.push(await prepareImage(file));
        } catch (e) {
            problems.push(e instanceof PhotoError ? e.message : t('photos.failed'));
        }
    }
    files.value = [...files.value, ...ready];
    error.value = problems.join(' ') || null;
};

const remove = (index: number) => (files.value = files.value.filter((_, i) => i !== index));
</script>

<template>
    <section class="space-y-3">
        <h3 class="text-sm font-semibold">{{ t('photos.title') }}</h3>
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
                <!-- On a phone the system offers camera or gallery; several photos at once -->
                <label
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
                    <Camera class="size-4" />
                    {{ t('photos.take') }}
                    <input type="file" accept="image/*" capture="environment" class="sr-only" @change="add" />
                </label>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('photos.main_first') }} · {{ t('photos.hint') }}</p>
        <InputError :message="error ?? undefined" />
    </section>
</template>
