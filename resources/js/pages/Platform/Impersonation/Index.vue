<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';

interface TenantRow {
    id: number;
    ulid: string;
    name: string;
    subdomain: string;
    status: string;
}

const props = defineProps<{
    tenants: Paginated<TenantRow>;
    filters: { search: string };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('impersonation.title'), href: route('platform.impersonation.index') }];

const filters = useListFilters('platform.impersonation.index', props.filters);

const enter = (tenant: TenantRow) => router.post(route('platform.impersonation.store', tenant.ulid));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('impersonation.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('impersonation.title')" :description="t('impersonation.description')" />

            <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('impersonation.search_placeholder')" />

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('impersonation.name') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('impersonation.subdomain') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                            <th class="px-4 py-2 font-medium text-right">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tenant in tenants.data" :key="tenant.id" class="border-t">
                            <td class="px-4 py-2 font-medium">{{ tenant.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ tenant.subdomain }}</td>
                            <td class="px-4 py-2">{{ t(`impersonation.status.${tenant.status}`) }}</td>
                            <td class="px-4 py-2 text-right">
                                <Button size="sm" variant="outline" :disabled="!!page.props.impersonation" @click="enter(tenant)">
                                    {{ t('impersonation.enter') }}
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="tenants.data.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="tenants" />
        </div>
    </AppLayout>
</template>
