<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import SummaryItems from '@/components/SummaryItems.vue';
import SummaryTotalsCards from '@/components/SummaryTotalsCards.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCan } from '@/composables/useCan';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import type { SummaryCheckout, SummaryPurchase, SummaryTotals } from '@/types/summary';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{
    project: { id: number; contract_no: string; title: string; status: string; customer: string | null; starts_on: string; ends_on: string };
    totals: SummaryTotals;
    filters: { search: string; status: 'all' | 'open'; direction: 'asc' | 'desc' };
    checkouts: Paginated<SummaryCheckout> | null;
    partCheckouts: Paginated<SummaryCheckout> | null;
    purchases: Paginated<SummaryPurchase> | null;
    // False for customer accounts: no purchases or amounts.
    showsPurchases: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('summary.projects_title'), href: route('reporting.projects.index') },
    { title: props.project.contract_no, href: route('reporting.projects.show', props.project.id) },
];

const can = useCan();
const filters = useListFilters('reporting.projects.show', { ...props.filters }, { contract: props.project.id });
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${t('summary.projects_title')} · ${project.contract_no}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-muted-foreground">{{ project.contract_no }}</p>
                    <Heading :title="project.title" :description="`${project.customer ?? ''} · ${project.starts_on} – ${project.ends_on}`" />
                </div>
                <Button v-if="can('contracts.view')" variant="outline" as-child>
                    <Link :href="route('contract.contracts.show', project.id)">{{ t('nav.contracts') }}</Link>
                </Button>
            </div>

            <SummaryTotalsCards :totals="totals" :purchases="showsPurchases" />

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

            <SummaryItems by="project" :checkouts="checkouts" :part-checkouts="partCheckouts" :purchases="purchases" />

            <Button variant="outline" as-child>
                <Link :href="route('reporting.projects.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
