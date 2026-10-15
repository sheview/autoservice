<script setup lang="ts">
import PmResultBadge from '@/components/PmResultBadge.vue';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import { Download, FileText, ShieldCheck } from 'lucide-vue-next';

// A customer's link to a published PM report: no sign-in, so no app layout or navigation. Not found reads like any other.
defineProps<{
    company: string | null;
    report: {
        visit: {
            visit_no: string;
            title: string | null;
            round: number | null;
            summary: string | null;
            assignee: string | null;
            customer: string | null;
            contract_no: string | null;
            completed_at: string | null;
            approved_at: string | null;
        };
        version: number;
        totals: { total: number; ok: number; issue: number; skipped: number };
        items: {
            id: number;
            asset_code: string | null;
            name: string | null;
            category: string | null;
            result: string;
            failures: string[];
            note: string | null;
            pdf: string;
        }[];
        pdf: string;
        allow_download: boolean;
        expires_at: string;
    } | null;
}>();
</script>

<template>
    <Head :title="t('pm_shared.title')" />

    <div class="min-h-svh bg-gradient-to-b from-teal-50 to-white px-4 py-10 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <div class="space-y-2 text-center">
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-teal-600 to-emerald-500 text-white shadow-lg"
                >
                    <ShieldCheck class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('pm_shared.title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-teal-700 dark:text-teal-300">{{ company }}</p>
            </div>

            <p v-if="!report" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('pm_shared.not_found') }}</p>

            <template v-else>
                <div class="space-y-3 rounded-2xl border bg-background p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-mono text-sm text-muted-foreground">
                                {{ report.visit.visit_no }} · {{ t('pm_shared.version', { version: report.version }) }}
                            </p>
                            <h2 class="text-lg font-semibold">{{ report.visit.title }}</h2>
                            <p class="text-sm">
                                {{ report.visit.customer }}<template v-if="report.visit.contract_no"> · {{ report.visit.contract_no }}</template>
                            </p>
                            <p v-if="report.visit.approved_at" class="text-xs text-muted-foreground">
                                {{ t('pm_shared.approved_at', { at: dateTime(report.visit.approved_at) }) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" as-child>
                                <a :href="report.pdf" target="_blank" rel="noopener"><FileText class="h-4 w-4" />{{ t('pm_shared.open_pdf') }}</a>
                            </Button>
                            <Button v-if="report.allow_download" as-child>
                                <a :href="`${report.pdf}?download=1`"><Download class="h-4 w-4" />{{ t('pm_shared.download_pdf') }}</a>
                            </Button>
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ t('pm_shared.totals', report.totals) }}</p>
                    <p v-if="report.visit.summary" class="whitespace-pre-line rounded-md bg-muted/40 p-3 text-sm">{{ report.visit.summary }}</p>
                </div>

                <div class="space-y-2 rounded-2xl border bg-background p-5 shadow-sm">
                    <h3 class="font-semibold">{{ t('pm_shared.equipment') }}</h3>
                    <ul class="divide-y text-sm">
                        <li v-for="item in report.items" :key="item.id" class="flex flex-wrap items-start justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <span class="font-mono text-xs">{{ item.asset_code }}</span>
                                <span class="ml-2">{{ item.name }}</span>
                                <span v-if="item.category" class="ml-2 text-xs text-muted-foreground">{{ item.category }}</span>
                                <p v-if="item.failures.length" class="text-xs text-red-700 dark:text-red-400">
                                    {{ t('pm_shared.failures', { items: item.failures.join(', ') }) }}
                                </p>
                                <p v-if="item.note" class="text-xs text-muted-foreground">{{ item.note }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <PmResultBadge :result="item.result" />
                                <a :href="item.pdf" target="_blank" rel="noopener" class="text-xs text-primary underline-offset-4 hover:underline">{{
                                    t('pm_shared.equipment_pdf')
                                }}</a>
                            </div>
                        </li>
                    </ul>
                </div>

                <p class="text-center text-xs text-muted-foreground">{{ t('pm_shared.expires', { at: dateTime(report.expires_at) }) }}</p>
            </template>
        </div>
    </div>
</template>
