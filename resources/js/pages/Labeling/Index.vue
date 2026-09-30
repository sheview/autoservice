<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface AssetRow {
    ulid: string;
    asset_code: string;
    name: string;
    category: string | null;
    branch: string | null;
    serial_number: string | null;
    location: string | null;
    customer: string | null;
    last_printed_at: string | null;
}

interface Filters {
    search: string;
    customer_id: number | null;
    category_id: number | null;
    printed: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    assets: Paginated<AssetRow>;
    filters: Filters;
    customers: { id: number; code: string; name: string }[];
    categories: { id: number; name: string }[];
    templates: { key: string }[];
    maxLabels: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('labels.title'), href: route('labeling.labels.index') }];

// Only the filters the server knows; other asset-list filters keep their defaults.
const filters = useListFilters('labeling.labels.index', {
    search: props.filters.search,
    customer_id: props.filters.customer_id,
    category_id: props.filters.category_id,
    printed: props.filters.printed,
    sort: props.filters.sort,
    direction: props.filters.direction,
} as Filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

// The selection survives paging and filtering (the page component is kept).
const selected = ref<string[]>([]);
const template = ref(props.templates[0]?.key ?? '');
const pageUlids = computed(() => props.assets.data.map((a) => a.ulid));
const allOnPage = computed(() => pageUlids.value.length > 0 && pageUlids.value.every((u) => selected.value.includes(u)));

const toggle = (ulid: string) => {
    selected.value = selected.value.includes(ulid) ? selected.value.filter((u) => u !== ulid) : [...selected.value, ulid];
};
const togglePage = () => {
    selected.value = allOnPage.value
        ? selected.value.filter((u) => !pageUlids.value.includes(u))
        : [...new Set([...selected.value, ...pageUlids.value])];
};

const printUrl = computed(() => route('labeling.labels.print', { assets: selected.value.join(','), template: template.value }));

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('labels.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('labels.title')" :description="t('labels.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('labels.search_placeholder')" />
                <select v-if="customers.length" v-model="filters.customer_id" :class="selectClass" :aria-label="t('labels.customer')">
                    <option :value="null">{{ t('labels.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-model="filters.category_id" :class="selectClass" :aria-label="t('labels.category')">
                    <option :value="null">{{ t('labels.all_categories') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <select v-model="filters.printed" :class="selectClass" :aria-label="t('labels.printed_filter')">
                    <option :value="null">{{ t('labels.printed_all') }}</option>
                    <option value="no">{{ t('labels.printed_no') }}</option>
                    <option value="yes">{{ t('labels.printed_yes') }}</option>
                </select>
            </div>

            <!-- Selection bar -->
            <div class="flex flex-wrap items-center gap-3 rounded-md border bg-muted/30 px-4 py-3">
                <span class="text-sm font-medium">{{ t('labels.selected', { count: selected.length }) }}</span>
                <Button v-if="selected.length" variant="ghost" size="sm" @click="selected = []">{{ t('labels.clear') }}</Button>
                <span class="flex-1" />
                <select v-model="template" :class="selectClass" :aria-label="t('labels.template')">
                    <option v-for="tpl in templates" :key="tpl.key" :value="tpl.key">{{ t(`labels.templates.${tpl.key}`) }}</option>
                </select>
                <Button :disabled="selected.length === 0 || selected.length > maxLabels" as-child>
                    <a :href="selected.length && selected.length <= maxLabels ? printUrl : undefined" target="_blank" rel="noopener">
                        <Printer class="h-4 w-4" />
                        {{ t('labels.print') }}
                    </a>
                </Button>
                <p v-if="selected.length > maxLabels" class="w-full text-xs text-red-600">{{ t('labels.max_hint', { max: maxLabels }) }}</p>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-10 px-4 py-2">
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="allOnPage"
                                    :aria-label="t('labels.select_page')"
                                    @change="togglePage"
                                />
                            </th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('asset_code')">
                                    {{ t('labels.asset_code') }}
                                    <template v-if="filters.sort === 'asset_code'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('name')">
                                    {{ t('labels.name') }}
                                    <template v-if="filters.sort === 'name'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('labels.customer') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('labels.last_printed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="asset in assets.data"
                            :key="asset.ulid"
                            class="cursor-pointer border-t hover:bg-muted/30"
                            @click="toggle(asset.ulid)"
                        >
                            <td class="px-4 py-2" @click.stop>
                                <input type="checkbox" class="size-4" :checked="selected.includes(asset.ulid)" @change="toggle(asset.ulid)" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ asset.asset_code }}</td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ asset.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ [asset.category, asset.branch, asset.location].filter(Boolean).join(' · ') }}
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ asset.customer ?? t('common.none') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">
                                {{ asset.last_printed_at ? dateTime(asset.last_printed_at) : t('labels.never') }}
                            </td>
                        </tr>
                        <tr v-if="assets.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="assets" />
        </div>
    </AppLayout>
</template>
