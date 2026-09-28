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

interface CustomerRow {
    id: number;
    code: string;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    contracts_count: number;
}

interface Filters {
    search: string;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    customers: Paginated<CustomerRow>;
    filters: Filters;
    can: { create: boolean; update: boolean; delete: boolean; viewContracts: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('customers.title'), href: route('contract.customers.index') }];

const filters = useListFilters('contract.customers.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'code', sortable: true },
    { key: 'name', sortable: true },
    { key: 'contact_name', sortable: false },
    { key: 'contracts_count', sortable: true },
];

const destroy = (customer: CustomerRow) => {
    if (confirm(t('common.confirm_delete', { name: customer.name }))) {
        router.delete(route('contract.customers.destroy', customer.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('customers.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('customers.title')" :description="t('customers.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('contract.customers.create')">{{ t('customers.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).customer" />

            <Input v-model="filters.search" type="search" class="max-w-sm" :placeholder="t('customers.search_placeholder')" />

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`customers.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`customers.${column.key}`) }}</span>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="customer in customers.data" :key="customer.id" class="border-t">
                            <td class="px-4 py-2 font-mono text-xs">{{ customer.code }}</td>
                            <td class="px-4 py-2 font-medium">{{ customer.name }}</td>
                            <td class="px-4 py-2">
                                <div>{{ customer.contact_name ?? t('common.none') }}</div>
                                <div v-if="customer.phone || customer.email" class="text-xs text-muted-foreground">
                                    {{ [customer.phone, customer.email].filter(Boolean).join(' · ') }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <Link
                                    v-if="can.viewContracts && customer.contracts_count > 0"
                                    :href="route('contract.contracts.index', { customer_id: customer.id })"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ customer.contracts_count }}
                                </Link>
                                <span v-else>{{ customer.contracts_count }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <Link
                                    v-if="can.update"
                                    :href="route('contract.customers.edit', customer.id)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ t('common.edit') }}
                                </Link>
                                <button
                                    v-if="can.delete && customer.contracts_count === 0"
                                    type="button"
                                    class="ml-3 text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                    @click="destroy(customer)"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="customers.data.length === 0">
                            <td :colspan="columns.length + 1" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="customers" />
        </div>
    </AppLayout>
</template>
