<script setup lang="ts">
import PublicTicketCard, { type PublicTicket } from '@/components/PublicTicketCard.vue';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CircleCheck, Copy, Search } from 'lucide-vue-next';
import { ref } from 'vue';

// A ticket's tracking link: no sign-in, so no app layout or navigation. Not found reads like any other.
defineProps<{ company: string | null; ticket: PublicTicket | null }>();

// Just reported from a QR code (or a job was already running): say so, and offer the link to keep.
const flash = usePage<SharedData>().props.flash as { reported?: string; already?: string };
const copied = ref(false);
const copy = async () => {
    await navigator.clipboard.writeText(window.location.href);
    copied.value = true;
};
</script>

<template>
    <Head :title="t('track.title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-10 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-2xl space-y-6">
            <div class="space-y-3 text-center">
                <div
                    class="mx-auto flex size-16 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white shadow-lg shadow-violet-500/30"
                >
                    <Search class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('track.title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-violet-700 dark:text-violet-300">{{ company }}</p>
            </div>

            <div
                v-if="ticket && (flash.reported || flash.already)"
                class="space-y-3 rounded-2xl border border-green-300 bg-green-50 p-5 dark:border-green-800 dark:bg-green-950"
            >
                <p class="flex items-start gap-2 font-semibold">
                    <CircleCheck class="mt-0.5 size-5 shrink-0 text-green-700" />
                    {{ flash.reported ? t('qr.received', { no: flash.reported }) : t('qr.already', { no: flash.already ?? '' }) }}
                </p>
                <p class="text-sm">{{ t('qr.received_hint') }}</p>
                <button
                    type="button"
                    class="flex min-h-12 items-center gap-2 rounded-xl border bg-white px-4 text-sm font-semibold dark:bg-slate-900"
                    @click="copy"
                >
                    <Copy class="size-4" />{{ copied ? t('qr.copied') : t('qr.copy_link') }}
                </button>
            </div>

            <PublicTicketCard v-if="ticket" :ticket="ticket" />
            <p v-else class="rounded-2xl border border-dashed bg-white p-6 text-center text-sm text-muted-foreground dark:bg-slate-950">
                {{ t('track.not_found') }}
            </p>

            <div class="text-center">
                <Link :href="route('service.track')" class="text-sm text-violet-700 hover:underline dark:text-violet-300">{{
                    t('track.search_other')
                }}</Link>
            </div>
        </div>
    </div>
</template>
