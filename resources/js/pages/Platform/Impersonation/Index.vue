<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCan } from '@/composables/useCan';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

interface TenantRow {
    id: number;
    ulid: string;
    name: string;
    subdomain: string;
    status: string;
    subscription: NonNullable<SharedData['subscription']>;
}

const props = defineProps<{
    tenants: Paginated<TenantRow>;
    filters: { search: string };
}>();

const page = usePage<SharedData>();
const can = useCan();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('impersonation.title'), href: route('platform.impersonation.index') }];

const filters = useListFilters('platform.impersonation.index', props.filters);

const enter = (tenant: TenantRow) => router.post(route('platform.impersonation.store', tenant.ulid));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('impersonation.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('impersonation.title')" :description="t('impersonation.description')" />
                <Button v-if="can('platform.tenants') && !page.props.impersonation" as-child>
                    <Link :href="route('platform.tenants.create')">{{ t('tenants.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('impersonation.search_placeholder')" />

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('impersonation.name') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('impersonation.subdomain') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('tenants.subscription') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tenant in tenants.data" :key="tenant.id" class="border-t">
                            <td class="px-4 py-2 font-medium">{{ tenant.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ tenant.subdomain }}</td>
                            <td class="px-4 py-2">{{ t(`impersonation.status.${tenant.status}`) }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="{
                                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200': tenant.subscription.state === 'expiring',
                                        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200': ['grace', 'locked', 'not_started'].includes(
                                            tenant.subscription.state,
                                        ),
                                        'bg-muted': ['active', 'unlimited'].includes(tenant.subscription.state),
                                    }"
                                >
                                    {{ t(`subscription.states.${tenant.subscription.state}`) }}
                                </span>
                                <span v-if="tenant.subscription.ends_on" class="ml-2 text-xs text-muted-foreground">
                                    {{ t('tenants.ends') }} {{ tenant.subscription.ends_on }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex justify-end gap-2">
                                    <Button v-if="can('platform.tenants') && !page.props.impersonation" size="sm" variant="ghost" as-child>
                                        <Link :href="route('platform.tenants.edit', tenant.ulid)">{{ t('tenants.manage') }}</Link>
                                    </Button>
                                    <Button v-if="can('platform.tenants') && !page.props.impersonation" size="sm" variant="ghost" as-child>
                                        <Link :href="route('platform.tenants.modules.edit', tenant.ulid)">{{ t('tenant_modules.link') }}</Link>
                                    </Button>
                                    <Button v-if="can('platform.tenants') && !page.props.impersonation" size="sm" variant="ghost" as-child>
                                        <Link :href="route('platform.tenants.shares.edit', tenant.ulid)">{{ t('shares.link') }}</Link>
                                    </Button>
                                    <Button size="sm" variant="outline" :disabled="!!page.props.impersonation" @click="enter(tenant)">
                                        {{ t('impersonation.enter') }}
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="tenants.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="tenants" />
        </div>
    </AppLayout>
</template>
