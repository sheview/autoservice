<script setup lang="ts">
import PublicTicketCard, { type PublicTicket } from '@/components/PublicTicketCard.vue';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';

// A ticket's tracking link: no sign-in, so no app layout or navigation. Not found reads like any other.
defineProps<{ company: string | null; ticket: PublicTicket | null }>();
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
