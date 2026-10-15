<script setup lang="ts">
import PmSignForm from '@/components/pm/PmSignForm.vue';
import PmSignSummary, { type PmSignWork } from '@/components/pm/PmSignSummary.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import { CircleCheck, PenLine } from 'lucide-vue-next';

/**
 * The customer signs a PM work order on their own phone (a sign link: once, until it expires), with
 * their satisfaction. No sign-in, so no app layout. Not found reads like any other.
 */
defineProps<{ company: string | null; work: PmSignWork | null; levels: string[]; signed: boolean }>();

const url = () => window.location.pathname;
</script>

<template>
    <Head :title="t('pm_sign.public_title')" />

    <div class="min-h-svh bg-gradient-to-b from-teal-50 to-white px-4 py-8 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-xl space-y-5">
            <div class="space-y-2 text-center">
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-teal-600 to-emerald-500 text-white shadow-lg"
                >
                    <CircleCheck v-if="signed" class="size-7" />
                    <PenLine v-else class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('pm_sign.public_title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-teal-700 dark:text-teal-300">{{ company }}</p>
            </div>

            <p v-if="signed" class="rounded-2xl border bg-background p-6 text-center text-lg">{{ t('pm_sign.done') }}</p>
            <p v-else-if="!work" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('pm_sign.not_found') }}</p>

            <template v-else>
                <div class="rounded-2xl border bg-background p-5 shadow-sm">
                    <PmSignSummary :work="work" />
                    <p v-if="work.expires_at" class="mt-3 text-xs text-muted-foreground">
                        {{ t('pm_sign.expires', { at: dateTime(work.expires_at) }) }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-background p-5 shadow-sm">
                    <PmSignForm :url="url()" :levels="levels" />
                </div>
            </template>
        </div>
    </div>
</template>
