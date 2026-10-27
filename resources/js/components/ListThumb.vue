<script setup lang="ts">
import { t } from '@/lib/i18n';
import { ImageOff, X } from 'lucide-vue-next';
import { ref } from 'vue';

// The main photo of a row in a list (a small copy: "?thumb=1"); a tap shows it full size over the page.
const props = defineProps<{ src: string | null; alt: string }>();

const open = ref(false);
const full = () => props.src!.replace('thumb=1&', '');
</script>

<template>
    <button
        v-if="src"
        type="button"
        class="size-10 shrink-0 overflow-hidden rounded-md border bg-muted"
        :aria-label="t('photos.view', { name: alt })"
        @click="open = true"
    >
        <img :src="src" :alt="alt" class="size-full object-cover" loading="lazy" />
    </button>
    <span
        v-else
        class="flex size-10 shrink-0 items-center justify-center rounded-md border border-dashed text-muted-foreground/50"
        :title="t('photos.none')"
    >
        <ImageOff class="size-4" />
    </span>

    <Teleport to="body">
        <div
            v-if="open && src"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4"
            role="dialog"
            :aria-label="alt"
            @click="open = false"
        >
            <button
                type="button"
                class="absolute right-2 top-2 flex size-11 items-center justify-center rounded-full text-white"
                :aria-label="t('photos.close')"
            >
                <X class="size-6" />
            </button>
            <img :src="full()" :alt="alt" class="max-h-full max-w-full rounded-md object-contain" />
        </div>
    </Teleport>
</template>
