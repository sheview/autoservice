<script setup lang="ts">
import { t } from '@/lib/i18n';

// Photos taken on the job (sent by the customer, before and after the repair) and the signatures:
// the technician's (through a ticket's link) and the customer's.
type Signature = { url: string; signer: string | null } | null;
defineProps<{ photos: { url: string; stage: string | null }[]; signature: Signature; techSignature?: Signature }>();
</script>

<template>
    <section v-if="photos.length || signature || techSignature" class="space-y-2">
        <h3 class="text-sm font-semibold">{{ t('job_photos.title') }}</h3>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <a v-for="photo in photos" :key="photo.url" :href="photo.url" target="_blank" rel="noopener" class="relative block">
                <img :src="photo.url" class="aspect-square w-full rounded-md object-cover" alt="" loading="lazy" />
                <span v-if="photo.stage" class="absolute bottom-1 left-1 rounded bg-black/60 px-1.5 text-xs text-white">{{
                    t(`job_photos.stages.${photo.stage}`)
                }}</span>
            </a>
        </div>
        <div class="flex flex-wrap gap-4">
            <figure v-if="techSignature" class="space-y-1">
                <img :src="techSignature.url" class="h-24 rounded-md border bg-white" alt="" />
                <figcaption class="text-xs text-muted-foreground">{{ t('job_photos.tech_signature', { name: techSignature.signer ?? '' }) }}</figcaption>
            </figure>
            <figure v-if="signature" class="space-y-1">
                <img :src="signature.url" class="h-24 rounded-md border bg-white" alt="" />
                <figcaption class="text-xs text-muted-foreground">{{ t('job_photos.signature', { name: signature.signer ?? '' }) }}</figcaption>
            </figure>
        </div>
    </section>
</template>
