<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface CategoryRow {
    id: number;
    name: string;
    code_prefix: string;
    service_line: string | null;
    spec_fields_count: number;
    assets_count: number;
}

interface Filters {
    search: string;
    service_line: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    categories: Paginated<CategoryRow>;
    filters: Filters;
    serviceLines: string[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('asset_categories.title'), href: route('asset.categories.index') }];

const filters = useListFilters('asset.categories.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'name', sortable: true },
    { key: 'code_prefix', sortable: true },
    { key: 'service_line', sortable: false },
    { key: 'spec_fields_count', sortable: false },
    { key: 'assets_count', sortable: true },
];

const destroy = (category: CategoryRow) => {
    if (confirm(t('common.confirm_delete', { name: category.name }))) {
        router.delete(route('asset.categories.destroy', category.id), { preserveScroll: true });
    }
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('asset_categories.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('asset_categories.title')" :description="t('asset_categories.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('asset.categories.create')">{{ t('asset_categories.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).category" />

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('asset_categories.search_placeholder')" />
                <select v-model="filters.service_line" :class="selectClass" :aria-label="t('asset_categories.service_line')">
                    <option :value="null">{{ t('asset_categories.all_service_lines') }}</option>
                    <option v-for="line in serviceLines" :key="line" :value="line">{{ t(`service_lines.${line}`) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`asset_categories.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`asset_categories.${column.key}`) }}</span>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="category in categories.data" :key="category.id" class="border-t">
                            <td class="px-4 py-2 font-medium">{{ category.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ category.code_prefix }}</td>
                            <td class="px-4 py-2">
                                {{ category.service_line ? t(`service_lines.${category.service_line}`) : t('asset_categories.no_service_line') }}
                            </td>
                            <td class="px-4 py-2">{{ category.spec_fields_count }}</td>
                            <td class="px-4 py-2">{{ category.assets_count }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <Link
                                    v-if="can.update"
                                    :href="route('asset.categories.edit', category.id)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ t('common.edit') }}
                                </Link>
                                <button
                                    v-if="can.delete && category.assets_count === 0"
                                    type="button"
                                    class="ml-3 text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                    @click="destroy(category)"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="categories.data.length === 0">
                            <td :colspan="columns.length + 1" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="categories" />
        </div>
    </AppLayout>
</template>
