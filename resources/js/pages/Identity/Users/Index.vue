<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface UserRow {
    id: number;
    name: string;
    email: string;
    employee_code: string | null;
    position: string | null;
    branch: string | null;
    customer: string | null;
    role: string | null;
    is_active: boolean;
}

interface Filters {
    search: string;
    branch_id: number | null;
    role: string | null;
    status: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    users: Paginated<UserRow>;
    filters: Filters;
    branches: { id: number; name: string }[];
    roles: { name: string; label: string }[];
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('users.title'), href: route('identity.users.index') }];

const filters = useListFilters('identity.users.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'name', sortable: true },
    { key: 'email', sortable: true },
    { key: 'employee_code', sortable: true },
    { key: 'branch', sortable: false },
    { key: 'role', sortable: false },
];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('users.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('users.title')" :description="t('users.description')" />
                <div v-if="can.create" class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('identity.users.import')">{{ t('users.import') }}</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="route('identity.users.create')">{{ t('users.create') }}</Link>
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('users.search_placeholder')" />
                <select v-model="filters.branch_id" :class="selectClass" :aria-label="t('users.branch')">
                    <option :value="null">{{ t('users.all_branches') }}</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                </select>
                <select v-model="filters.role" :class="selectClass" :aria-label="t('users.role')">
                    <option :value="null">{{ t('users.all_roles') }}</option>
                    <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
                </select>
                <select v-model="filters.status" :class="selectClass" :aria-label="t('common.status')">
                    <option :value="null">{{ t('common.all') }}</option>
                    <option value="active">{{ t('common.active') }}</option>
                    <option value="inactive">{{ t('common.inactive') }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`users.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`users.${column.key}`) }}</span>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.id" class="border-t">
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ user.name }}</div>
                                <div v-if="user.position" class="text-xs text-muted-foreground">{{ user.position }}</div>
                            </td>
                            <td class="px-4 py-2">{{ user.email }}</td>
                            <td class="px-4 py-2">{{ user.employee_code ?? '—' }}</td>
                            <td class="px-4 py-2">{{ user.branch ?? t('users.no_branch') }}</td>
                            <td class="px-4 py-2">
                                <div>{{ user.role ?? '—' }}</div>
                                <div v-if="user.customer" class="text-xs text-muted-foreground">{{ user.customer }}</div>
                            </td>
                            <td class="px-4 py-2">
                                <span :class="user.is_active ? 'text-green-700 dark:text-green-400' : 'text-muted-foreground'">
                                    {{ user.is_active ? t('common.active') : t('common.inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Link :href="route('identity.users.edit', user.id)" class="text-primary underline-offset-4 hover:underline">
                                    {{ t('common.edit') }}
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="users.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="users" />
        </div>
    </AppLayout>
</template>
