<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import { Download, FileText, PenLine, Printer, ShieldCheck } from 'lucide-vue-next';

// A customer's link to a round report: no sign-in, so no app layout or navigation. Not found reads like any other.
defineProps<{
    company: string | null;
    report: {
        customer: string | null;
        from: string;
        to: string;
        visits: {
            number: string;
            title: string | null;
            date: string;
            completed_at: string | null;
            total: number;
            passed: number;
            failed: number;
            not_inspected: number;
            signed: boolean;
            pdf: string;
        }[];
        pdf: string;
        allow_download: boolean;
        expires_at: string;
    } | null;
}>();
</script>

<template>
    <Head :title="t('pm_shared_round.title')" />

    <div class="min-h-svh bg-gradient-to-b from-teal-50 to-white px-4 py-10 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <div class="space-y-2 text-center">
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-teal-600 to-emerald-500 text-white shadow-lg"
                >
                    <ShieldCheck class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('pm_shared_round.title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-teal-700 dark:text-teal-300">{{ company }}</p>
            </div>

            <p v-if="!report" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('pm_shared.not_found') }}</p>

            <template v-else>
                <div class="space-y-3 rounded-2xl border bg-background p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold">{{ report.customer }}</h2>
                            <p class="text-sm text-muted-foreground">{{ t('pm_shared_round.period', { from: report.from, to: report.to }) }}</p>
                        </div>
                        <div v-if="report.visits.length" class="flex flex-wrap gap-2">
                            <Button variant="outline" as-child>
                                <a :href="report.pdf" target="_blank" rel="noopener"
                                    ><FileText class="h-4 w-4" />{{ t('pm_shared_round.open_pdf') }}</a
                                >
                            </Button>
                            <Button variant="outline" as-child>
                                <a :href="`${report.pdf}?print=1`" target="_blank" rel="noopener"
                                    ><Printer class="h-4 w-4" />{{ t('pm_shared_round.print') }}</a
                                >
                            </Button>
                            <Button v-if="report.allow_download" as-child>
                                <a :href="`${report.pdf}?download=1`"><Download class="h-4 w-4" />{{ t('pm_shared_round.download_pdf') }}</a>
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 rounded-2xl border bg-background p-5 shadow-sm">
                    <h3 class="font-semibold">{{ t('pm_shared_round.work_orders', { count: report.visits.length }) }}</h3>
                    <ul v-if="report.visits.length" class="divide-y text-sm">
                        <li v-for="visit in report.visits" :key="visit.number" class="flex flex-wrap items-start justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <span class="font-mono text-xs">{{ visit.number }}</span>
                                <span class="ml-2">{{ visit.title }}</span>
                                <span class="ml-2 text-xs text-muted-foreground">{{ visit.date }}</span>
                                <p class="text-xs" :class="visit.failed ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground'">
                                    {{
                                        t('pm_shared_round.result', {
                                            total: visit.total,
                                            passed: visit.passed,
                                            failed: visit.failed,
                                            not_inspected: visit.not_inspected,
                                        })
                                    }}
                                </p>
                                <p v-if="visit.signed" class="flex items-center gap-1 text-xs text-teal-700 dark:text-teal-300">
                                    <PenLine class="h-3 w-3" />{{ t('pm_shared_round.signed') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <a :href="visit.pdf" target="_blank" rel="noopener" class="text-xs text-primary underline-offset-4 hover:underline">{{
                                    t('pm_shared_round.sheet_pdf')
                                }}</a>
                                <a
                                    :href="`${visit.pdf}?print=1`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-xs text-primary underline-offset-4 hover:underline"
                                    >{{ t('pm_shared_round.sheet_print') }}</a
                                >
                                <a
                                    v-if="report.allow_download"
                                    :href="`${visit.pdf}?download=1`"
                                    class="text-xs text-primary underline-offset-4 hover:underline"
                                    >{{ t('pm_shared_round.download') }}</a
                                >
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">{{ t('pm_shared_round.none') }}</p>
                </div>

                <p class="text-center text-xs text-muted-foreground">{{ t('pm_shared.expires', { at: dateTime(report.expires_at) }) }}</p>
            </template>
        </div>
    </div>
</template>
