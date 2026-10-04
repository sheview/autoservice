<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import SummaryItems from '@/components/SummaryItems.vue';
import SummaryTotalsCards from '@/components/SummaryTotalsCards.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { personParams, type SummaryCheckout, type SummaryPurchase, type SummaryTotals } from '@/types/summary';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    person: { user_id: number | null; outside_name: string | null; name: string };
    totals: SummaryTotals;
    filters: { search: string; status: 'all' | 'open'; direction: 'asc' | 'desc' };
    checkouts: Paginated<SummaryCheckout> | null;
    partCheckouts: Paginated<SummaryCheckout> | null;
    purchases: Paginated<SummaryPurchase> | null;
    // Ticket work by month (staff only); null = not shown.
    kpi: {
        year: number;
        years: number[];
        months: { opened: number; resolved: number; on_time: number; with_due: number; avg_hours: number | null }[];
    } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('summary.people_title'), href: route('reporting.people.index') },
    { title: props.person.name, href: route('reporting.people.show', personParams(props.person)) },
];

// The person stays in the query string while the filters change.
const filters = useListFilters('reporting.people.show', {
    ...props.filters,
    ...personParams(props.person),
    ...(props.kpi ? { year: props.kpi.year } : {}),
});

const monthName = (m: number) => new Date(2000, m, 1).toLocaleDateString('th-TH', { month: 'short' });
const kpiTotal = computed(() => {
    const months = props.kpi?.months ?? [];
    const sum = (k: 'opened' | 'resolved' | 'on_time' | 'with_due') => months.reduce((s, m) => s + m[k], 0);
    return { opened: sum('opened'), resolved: sum('resolved'), on_time: sum('on_time'), with_due: sum('with_due') };
});
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${t('summary.people_title')} · ${person.name}`" />

        <div class="space-y-6 p-4">
            <Heading :title="person.name" :description="person.user_id ? t('summary.people_title') : t('summary.outside')" />

            <!-- Ticket KPI of the year, by month -->
            <section v-if="kpi" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold">{{ t('summary.kpi_title', { year: kpi.year }) }}</h3>
                    <select v-model.number="filters.year" :class="selectClass" :aria-label="t('summary.kpi_year')">
                        <option v-for="year in kpi.years" :key="year" :value="year">{{ t('summary.kpi_year') }} {{ year }}</option>
                    </select>
                </div>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ t('summary.kpi_month') }}</th>
                                <th v-for="(m, i) in kpi.months" :key="i" class="px-2 py-2 text-right font-semibold">{{ monthName(i) }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('summary.kpi_total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            <tr class="border-t">
                                <td class="px-3 py-2">{{ t('summary.kpi_opened') }}</td>
                                <td v-for="(m, i) in kpi.months" :key="i" class="px-2 py-2 text-right">{{ m.opened || '-' }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ kpiTotal.opened }}</td>
                            </tr>
                            <tr class="border-t">
                                <td class="px-3 py-2">{{ t('summary.kpi_resolved') }}</td>
                                <td v-for="(m, i) in kpi.months" :key="i" class="px-2 py-2 text-right">{{ m.resolved || '-' }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ kpiTotal.resolved }}</td>
                            </tr>
                            <tr class="border-t">
                                <td class="px-3 py-2">{{ t('summary.kpi_on_time') }}</td>
                                <td v-for="(m, i) in kpi.months" :key="i" class="px-2 py-2 text-right">
                                    {{ m.with_due ? `${m.on_time}/${m.with_due}` : '-' }}
                                </td>
                                <td class="px-3 py-2 text-right font-semibold">
                                    {{ kpiTotal.with_due ? `${Math.round((kpiTotal.on_time * 100) / kpiTotal.with_due)}%` : '-' }}
                                </td>
                            </tr>
                            <tr class="border-t">
                                <td class="px-3 py-2">{{ t('summary.kpi_avg_hours') }}</td>
                                <td v-for="(m, i) in kpi.months" :key="i" class="px-2 py-2 text-right">{{ m.avg_hours ?? '-' }}</td>
                                <td class="px-3 py-2" />
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('summary.kpi_note') }}</p>
            </section>

            <SummaryTotalsCards :totals="totals" :purchases="!!person.user_id" />

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('summary.search_items')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('summary.status')">
                    <option v-for="key in ['all', 'open']" :key="key" :value="key">{{ t(`summary.list_status.${key}`) }}</option>
                </select>
                <select v-model="filters.direction" :class="selectClass" :aria-label="t('summary.date')">
                    <option value="desc">{{ t('summary.newest') }}</option>
                    <option value="asc">{{ t('summary.oldest') }}</option>
                </select>
            </div>

            <SummaryItems
                by="person"
                :checkouts="checkouts"
                :part-checkouts="partCheckouts"
                :purchases="purchases"
                :purchases-note="person.user_id ? undefined : t('summary.outside_purchase_note')"
            />

            <Button variant="outline" as-child>
                <Link :href="route('reporting.people.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
