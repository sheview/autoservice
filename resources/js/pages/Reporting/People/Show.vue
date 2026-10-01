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

const props = defineProps<{
    person: { user_id: number | null; outside_name: string | null; name: string };
    totals: SummaryTotals;
    filters: { search: string; status: 'all' | 'open'; direction: 'asc' | 'desc' };
    checkouts: Paginated<SummaryCheckout> | null;
    purchases: Paginated<SummaryPurchase> | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('summary.people_title'), href: route('reporting.people.index') },
    { title: props.person.name, href: route('reporting.people.show', personParams(props.person)) },
];

// The person stays in the query string while the filters change.
const filters = useListFilters('reporting.people.show', { ...props.filters, ...personParams(props.person) });
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${t('summary.people_title')} · ${person.name}`" />

        <div class="space-y-6 p-4">
            <Heading :title="person.name" :description="person.user_id ? t('summary.people_title') : t('summary.outside')" />

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
                :purchases="purchases"
                :purchases-note="person.user_id ? undefined : t('summary.outside_purchase_note')"
            />

            <Button variant="outline" as-child>
                <Link :href="route('reporting.people.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
