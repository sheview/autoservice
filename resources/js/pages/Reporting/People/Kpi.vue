<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import PeopleSummaryTabs from '@/components/PeopleSummaryTabs.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface KpiRow {
    user_id: number;
    name: string;
    opened: number;
    resolved: number;
    on_time: number;
    with_due: number;
    on_time_rate: number | null;
    avg_hours: number | null;
}

interface Filters {
    year: number;
    search: string;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{ people: Paginated<KpiRow>; filters: Filters; years: number[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('summary.people_title'), href: route('reporting.people.index') },
    { title: t('summary.tab_kpi'), href: route('reporting.people.kpi') },
];
const filters = useListFilters('reporting.people.kpi', { ...props.filters });

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const columns = [
    { key: 'opened', label: 'summary.kpi_opened' },
    { key: 'resolved', label: 'summary.kpi_resolved' },
    { key: 'on_time_rate', label: 'summary.kpi_on_time' },
    { key: 'avg_hours', label: 'summary.kpi_avg_hours' },
];
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${t('summary.people_title')} · ${t('summary.tab_kpi')}`" />

        <div class="space-y-6 p-4">
            <Heading :title="t('summary.people_title')" :description="t('summary.kpi_description')" />
            <PeopleSummaryTabs active="kpi" />

            <div class="flex flex-wrap items-center gap-3">
                <select v-model.number="filters.year" :class="selectClass" :aria-label="t('summary.kpi_year')">
                    <option v-for="year in years" :key="year" :value="year">{{ t('summary.kpi_year') }} {{ year }}</option>
                </select>
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('summary.search_people')" />
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-semibold">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('name')">
                                    {{ t('summary.person') }}
                                    <template v-if="filters.sort === 'name'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th v-for="col in columns" :key="col.key" class="px-4 py-2 text-right font-semibold">
                                <button type="button" class="ml-auto flex items-center gap-1" @click="sortBy(col.key)">
                                    {{ t(col.label) }}
                                    <template v-if="filters.sort === col.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in people.data" :key="row.user_id" class="border-t">
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('reporting.people.show', { user: row.user_id, year: filters.year })"
                                    class="text-primary hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ row.opened }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ row.resolved }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                <template v-if="row.on_time_rate !== null">{{ row.on_time_rate }}% ({{ row.on_time }}/{{ row.with_due }})</template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ row.avg_hours ?? '-' }}</td>
                        </tr>
                        <tr v-if="!people.data.length">
                            <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ t('summary.kpi_empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="people" />
            <p class="text-xs text-muted-foreground">{{ t('summary.kpi_note') }}</p>
        </div>
    </AppLayout>
</template>
