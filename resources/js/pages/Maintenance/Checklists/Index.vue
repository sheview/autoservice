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
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface ChecklistRow {
    id: number;
    name: string;
    category: string | null;
    items_count: number;
    updated_at: string;
}

interface Filters {
    search: string;
    category: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    checklists: Paginated<ChecklistRow>;
    filters: Filters;
    categories: { id: number; name: string }[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_checklists.title'), href: route('maintenance.checklists.index') }];

const filters = useListFilters('maintenance.checklists.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const destroy = (checklist: ChecklistRow) => {
    if (confirm(t('common.confirm_delete', { name: checklist.name }))) {
        router.delete(route('maintenance.checklists.destroy', checklist.id), { preserveScroll: true });
    }
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_checklists.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('pm_checklists.title')" :description="t('pm_checklists.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('maintenance.checklists.create')">{{ t('pm_checklists.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_checklists.search_placeholder')" />
                <select v-model="filters.category" :class="selectClass" :aria-label="t('pm_checklists.category')">
                    <option :value="null">{{ t('pm_checklists.all_categories') }}</option>
                    <option value="general">{{ t('pm_checklists.general') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('name')">
                                    {{ t('pm_checklists.name') }}
                                    <template v-if="filters.sort === 'name'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_checklists.category') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('pm_checklists.items_count') }}</th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('updated_at')">
                                    {{ t('pm_checklists.updated_at') }}
                                    <template v-if="filters.sort === 'updated_at'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th v-if="can.update || can.delete" class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="checklist in checklists.data" :key="checklist.id" class="border-t">
                            <td class="px-4 py-2 font-medium">{{ checklist.name }}</td>
                            <td class="px-4 py-2">{{ checklist.category ?? t('pm_checklists.general') }}</td>
                            <td class="px-4 py-2 text-right">{{ checklist.items_count }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ dateTime(checklist.updated_at) }}</td>
                            <td v-if="can.update || can.delete" class="whitespace-nowrap px-4 py-2 text-right">
                                <Button v-if="can.update" variant="ghost" size="sm" as-child>
                                    <Link :href="route('maintenance.checklists.edit', checklist.id)">{{ t('common.edit') }}</Link>
                                </Button>
                                <Button v-if="can.delete" variant="ghost" size="sm" class="text-destructive" @click="destroy(checklist)">
                                    {{ t('common.delete') }}
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="checklists.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="checklists" />
        </div>
    </AppLayout>
</template>
