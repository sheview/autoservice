<script setup lang="ts">
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import { ClipboardCheck } from 'lucide-vue-next';

/** Where a PM work order stands, from the QR on its sheet: status and dates only, never results. No sign-in. */
defineProps<{
    company: string | null;
    work: {
        number: string;
        title: string | null;
        status: string;
        scheduled_on: string | null;
        completed_at: string | null;
        locked_at: string | null;
        items: number;
    } | null;
}>();

const day = (value: string | null) =>
    value ? new Date(`${value}T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
</script>

<template>
    <Head :title="t('pm_track.title')" />

    <div class="min-h-svh bg-gradient-to-b from-teal-50 to-white px-4 py-10 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-md space-y-5">
            <div class="space-y-2 text-center">
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-teal-600 to-emerald-500 text-white shadow-lg"
                >
                    <ClipboardCheck class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('pm_track.title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-teal-700 dark:text-teal-300">{{ company }}</p>
            </div>

            <p v-if="!work" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('pm_track.not_found') }}</p>
            <div v-else class="space-y-3 rounded-2xl border bg-background p-5 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-mono text-sm text-muted-foreground">{{ work.number }}</p>
                        <h2 class="font-semibold">{{ work.title }}</h2>
                    </div>
                    <PmVisitStatusBadge :status="work.status" />
                </div>
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">{{ t('pm_track.scheduled_on') }}</dt>
                    <dd>{{ day(work.scheduled_on) }}</dd>
                    <dt class="text-muted-foreground">{{ t('pm_track.items') }}</dt>
                    <dd>{{ work.items }}</dd>
                    <dt class="text-muted-foreground">{{ t('pm_track.completed_at') }}</dt>
                    <dd>{{ work.completed_at ? dateTime(work.completed_at) : '-' }}</dd>
                    <dt class="text-muted-foreground">{{ t('pm_track.locked_at') }}</dt>
                    <dd>{{ work.locked_at ? dateTime(work.locked_at) : '-' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</template>
