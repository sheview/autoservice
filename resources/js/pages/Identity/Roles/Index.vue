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

interface RoleRow {
    id: number;
    name: string;
    label: string;
    is_system: boolean;
    users_count: number;
    permissions_count: number;
}

const props = defineProps<{
    roles: Paginated<RoleRow>;
    filters: { search: string };
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('roles.title'), href: route('identity.roles.index') }];

const filters = useListFilters('identity.roles.index', props.filters);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('roles.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('roles.title')" :description="t('roles.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('identity.roles.create')">{{ t('roles.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('roles.search_placeholder')" />

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('roles.label') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('roles.name') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('roles.users_count') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('roles.permissions_count') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="role in roles.data" :key="role.id" class="border-t">
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ role.label }}</span>
                                <span v-if="role.is_system" class="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                    {{ t('roles.system') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ role.name }}</td>
                            <td class="px-4 py-2 text-right">{{ role.users_count }}</td>
                            <td class="px-4 py-2 text-right">{{ role.permissions_count }}</td>
                            <td class="px-4 py-2 text-right">
                                <Link :href="route('identity.roles.edit', role.id)" class="text-primary underline-offset-4 hover:underline">
                                    {{ t('common.edit') }}
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="roles.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="roles" />
        </div>
    </AppLayout>
</template>
