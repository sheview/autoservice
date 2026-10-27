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
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
import { ref } from 'vue';

interface LocationRow {
    id: number;
    name: string;
    notes: string | null;
    is_active: boolean;
    assets_count: number;
    parts_count: number;
}

interface Filters {
    search: string;
    status: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    locations: Paginated<LocationRow>;
    filters: Filters;
    statuses: string[];
    // Parts counted too (the inventory module is on).
    showParts: boolean;
    can: { manage: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('storage_locations.title'), href: route('asset.locations.index') }];

const filters = useListFilters('asset.locations.index', props.filters);
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const form = useForm({ name: '', notes: '' });
const add = () => form.post(route('asset.locations.store'), { preserveScroll: true, onSuccess: () => form.reset() });

// One row edited in place at a time.
const editing = ref<number | null>(null);
const edit = useForm({ name: '', notes: '', is_active: true as boolean });
const startEdit = (location: LocationRow) => {
    edit.clearErrors();
    edit.name = location.name;
    edit.notes = location.notes ?? '';
    edit.is_active = location.is_active;
    editing.value = location.id;
};
const save = (location: LocationRow) =>
    edit.put(route('asset.locations.update', location.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });

const destroy = (location: LocationRow) => {
    if (confirm(t('storage_locations.delete_confirm', { name: location.name }))) {
        router.delete(route('asset.locations.destroy', location.id), { preserveScroll: true });
    }
};

const columns = [
    { key: 'name', label: 'storage_locations.name', sortable: true },
    { key: 'notes', label: 'storage_locations.notes', sortable: false },
    { key: 'is_active', label: 'storage_locations.is_active', sortable: false },
    { key: 'assets_count', label: 'storage_locations.assets', sortable: true },
];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('storage_locations.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('storage_locations.title')" :description="t('storage_locations.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <form v-if="can.manage" class="flex flex-wrap items-end gap-3 rounded-lg border p-4" @submit.prevent="add">
                <div class="grid min-w-56 flex-1 gap-1">
                    <label for="name" class="text-sm font-medium">{{ t('storage_locations.name') }} <span class="text-red-600">*</span></label>
                    <Input id="name" v-model="form.name" required maxlength="100" :placeholder="t('storage_locations.name_placeholder')" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid min-w-56 flex-1 gap-1">
                    <label for="notes" class="text-sm font-medium">{{ t('storage_locations.notes') }}</label>
                    <Input id="notes" v-model="form.notes" maxlength="255" />
                    <InputError :message="form.errors.notes" />
                </div>
                <Button :disabled="form.processing">{{ t('storage_locations.add') }}</Button>
            </form>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('storage_locations.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('storage_locations.is_active')">
                    <option :value="null">{{ t('storage_locations.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`storage_locations.statuses.${status}`) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(column.label) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(column.label) }}</span>
                            </th>
                            <th v-if="showParts" class="px-4 py-2 font-medium">{{ t('storage_locations.parts') }}</th>
                            <th v-if="can.manage" class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="location in locations.data" :key="location.id" class="border-t align-top">
                            <template v-if="editing === location.id">
                                <td class="px-4 py-2">
                                    <Input v-model="edit.name" required maxlength="100" :aria-label="t('storage_locations.name')" />
                                    <InputError :message="edit.errors.name" />
                                </td>
                                <td class="px-4 py-2">
                                    <Input v-model="edit.notes" maxlength="255" :aria-label="t('storage_locations.notes')" />
                                </td>
                                <td class="px-4 py-2">
                                    <input v-model="edit.is_active" type="checkbox" class="mt-2 size-4 rounded border-input" :aria-label="t('storage_locations.is_active')" />
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-2 font-medium">{{ location.name }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ location.notes || '-' }}</td>
                                <td class="px-4 py-2">{{ t(`storage_locations.statuses.${location.is_active ? 'active' : 'inactive'}`) }}</td>
                            </template>
                            <td class="px-4 py-2">{{ location.assets_count }}</td>
                            <td v-if="showParts" class="px-4 py-2">{{ location.parts_count }}</td>
                            <td v-if="can.manage" class="whitespace-nowrap px-4 py-2 text-right">
                                <template v-if="editing === location.id">
                                    <button type="button" class="text-primary underline-offset-4 hover:underline" :disabled="edit.processing" @click="save(location)">
                                        {{ t('storage_locations.save') }}
                                    </button>
                                    <button type="button" class="ml-3 text-muted-foreground underline-offset-4 hover:underline" @click="editing = null">
                                        {{ t('storage_locations.cancel') }}
                                    </button>
                                </template>
                                <template v-else>
                                    <button type="button" class="text-primary underline-offset-4 hover:underline" @click="startEdit(location)">
                                        {{ t('storage_locations.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="ml-3 text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                        @click="destroy(location)"
                                    >
                                        {{ t('storage_locations.delete') }}
                                    </button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="locations.data.length === 0">
                            <td :colspan="columns.length + 2" class="px-4 py-8 text-center text-muted-foreground">
                                {{ filters.search || filters.status ? t('common.no_results') : t('storage_locations.none') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="locations" />
        </div>
    </AppLayout>
</template>
