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

interface BranchRow {
    id: number;
    code: string;
    name: string;
    address: string | null;
    province: string | null;
}

interface Filters {
    search: string;
    province: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    branches: Paginated<BranchRow>;
    filters: Filters;
    provinces: string[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('branches.title'), href: route('tenancy.branches.index') }];

const filters = useListFilters('tenancy.branches.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'code', sortable: true },
    { key: 'name', sortable: true },
    { key: 'address', sortable: false },
    { key: 'province', sortable: true },
];

const destroy = (branch: BranchRow) => {
    if (confirm(t('common.confirm_delete', { name: branch.name }))) {
        router.delete(route('tenancy.branches.destroy', branch.id), { preserveScroll: true });
    }
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('branches.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('branches.title')" :description="t('branches.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('tenancy.branches.create')">{{ t('branches.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).branch" />

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('branches.search_placeholder')" />
                <select v-model="filters.province" :class="selectClass" :aria-label="t('branches.province')">
                    <option :value="null">{{ t('branches.all_provinces') }}</option>
                    <option v-for="province in provinces" :key="province" :value="province">{{ province }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`branches.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`branches.${column.key}`) }}</span>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="branch in branches.data" :key="branch.id" class="border-t">
                            <td class="px-4 py-2 font-mono text-xs">{{ branch.code }}</td>
                            <td class="px-4 py-2 font-medium">{{ branch.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ branch.address ?? '-' }}</td>
                            <td class="px-4 py-2">{{ branch.province ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <Link
                                    v-if="can.update"
                                    :href="route('tenancy.branches.edit', branch.id)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ t('common.edit') }}
                                </Link>
                                <button
                                    v-if="can.delete"
                                    type="button"
                                    class="ml-3 text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                    @click="destroy(branch)"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="branches.data.length === 0">
                            <td :colspan="columns.length + 1" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="branches" />
        </div>
    </AppLayout>
</template>
