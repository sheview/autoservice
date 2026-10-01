<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { personParams, type PersonSummary } from '@/types/summary';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface Filters {
    search: string;
    show: 'all' | 'open';
    kind: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    people: Paginated<PersonSummary>;
    filters: Filters;
    kinds: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('summary.people_title'), href: route('reporting.people.index') }];
const filters = useListFilters('reporting.people.index', { ...props.filters });

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const money = (baht: string) => (Number(baht) === 0 ? '-' : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('summary.people_title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('summary.people_title')" :description="t('summary.people_description')" />

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('summary.search_people')" />
                <select v-model="filters.kind" :class="selectClass" :aria-label="t('summary.all_kinds')">
                    <option :value="null">{{ t('summary.all_kinds') }}</option>
                    <option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`summary.kinds.${kind}`) }}</option>
                </select>
                <select v-model="filters.show" :class="selectClass" :aria-label="t('summary.open')">
                    <option v-for="key in ['all', 'open']" :key="key" :value="key">{{ t(`summary.show.${key}`) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('name')">
                                    {{ t('summary.person') }}
                                    <template v-if="filters.sort === 'name'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.issues') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.loans') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.purchases') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.purchase_amount') }}</th>
                            <th class="px-4 py-2 text-right font-medium">
                                <button type="button" class="ml-auto flex items-center gap-1" @click="sortBy('open_count')">
                                    {{ t('summary.open') }}
                                    <template v-if="filters.sort === 'open_count'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                <button type="button" class="ml-auto flex items-center gap-1" @click="sortBy('total')">
                                    {{ t('summary.total') }}
                                    <template v-if="filters.sort === 'total'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('last_at')">
                                    {{ t('summary.last_at') }}
                                    <template v-if="filters.sort === 'last_at'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in people.data" :key="row.user_id ?? `n:${row.outside_name}`" class="border-t">
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('reporting.people.show', personParams(row))"
                                    class="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                                <div v-if="!row.user_id" class="text-xs text-muted-foreground">{{ t('summary.outside') }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                {{ row.issues
                                }}<span v-if="row.issues_open" class="text-xs text-amber-700 dark:text-amber-400"> ({{ row.issues_open }})</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                {{ row.loans
                                }}<span v-if="row.loans_open" class="text-xs text-amber-700 dark:text-amber-400"> ({{ row.loans_open }})</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                {{ row.purchases
                                }}<span v-if="row.purchases_open" class="text-xs text-amber-700 dark:text-amber-400">
                                    ({{ row.purchases_open }})</span
                                >
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ money(row.purchase_amount) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{{ row.open_count }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ row.total }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ dateTime(row.last_at) }}</td>
                        </tr>
                        <tr v-if="people.data.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ t('summary.no_people') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="people" />
        </div>
    </AppLayout>
</template>
