<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { ImageOff } from 'lucide-vue-next';
import { ref } from 'vue';

// A record's photos as a fixed row of slots: the first is the main photo, the rest are extras.
// "action" is the address of a slot: GET shows it, POST uploads (replaces), DELETE removes.
interface PhotoSlot {
    slot: number;
    action: string;
    url: string | null;
}

defineProps<{ photos: PhotoSlot[]; editable: boolean }>();

const page = usePage<SharedData>();
const busy = ref<number | null>(null);

const label = (slot: number) => (slot === 0 ? t('photos.main') : t('photos.extra', { number: slot }));

const upload = (photo: PhotoSlot, event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    busy.value = photo.slot;
    router.post(
        photo.action,
        { photo: file },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
                input.value = '';
            },
        },
    );
};

const remove = (photo: PhotoSlot) => {
    if (confirm(t('common.confirm_delete', { name: label(photo.slot) }))) {
        router.delete(photo.action, { preserveScroll: true });
    }
};
</script>

<template>
    <section class="space-y-2">
        <h3 class="text-sm font-semibold">{{ t('photos.title') }}</h3>
        <div class="flex flex-wrap gap-4">
            <div v-for="photo in photos" :key="photo.slot" class="w-28 text-center text-xs">
                <a
                    v-if="photo.url"
                    :href="photo.url"
                    target="_blank"
                    rel="noopener"
                    class="block size-28 overflow-hidden rounded-lg border"
                    :title="label(photo.slot)"
                >
                    <img :src="photo.url" :alt="label(photo.slot)" class="size-full object-cover" loading="lazy" />
                </a>
                <div v-else class="flex size-28 items-center justify-center rounded-lg border border-dashed bg-muted/40">
                    <ImageOff class="size-6 text-muted-foreground/60" />
                </div>

                <p class="mt-1 text-muted-foreground">{{ label(photo.slot) }}</p>

                <div v-if="editable" class="flex justify-center gap-2">
                    <label class="cursor-pointer text-primary underline-offset-4 hover:underline" :class="{ 'opacity-50': busy === photo.slot }">
                        {{ busy === photo.slot ? t('photos.uploading') : photo.url ? t('photos.replace') : t('photos.upload') }}
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="sr-only"
                            :disabled="busy !== null"
                            @change="upload(photo, $event)"
                        />
                    </label>
                    <button
                        v-if="photo.url"
                        type="button"
                        class="text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                        @click="remove(photo)"
                    >
                        {{ t('common.delete') }}
                    </button>
                </div>
            </div>
        </div>
        <p v-if="editable" class="text-xs text-muted-foreground">{{ t('photos.hint') }}</p>
        <InputError :message="(page.props.errors as Record<string, string>).photo" />
    </section>
</template>
