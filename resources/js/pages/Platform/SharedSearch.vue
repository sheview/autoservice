<script setup lang="ts">
import AssetStatusBadge from '@/components/AssetStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

type Row = Record<string, string | number | null>;

const props = defineProps<{
    filters: { kind: string; company: number | null; search: string; [key: string]: unknown };
    kinds: string[];
    companies: { id: number; name: string }[];
    results: { company: string; rows: Row[] }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('shares.search_title'), href: route('platform.shared-search') }];
const filters = useListFilters('platform.shared-search', { ...props.filters });
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('shares.search_title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('shares.search_title')" :description="t('shares.search_description')" />

            <p v-if="!companies.length" class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                {{ t('shares.no_companies') }}
            </p>

            <template v-else>
                <div class="flex flex-wrap items-center gap-3">
                    <select v-if="kinds.length > 1" v-model="filters.kind" :class="selectClass">
                        <option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`shares.kinds.${kind}`) }}</option>
                    </select>
                    <select v-model="filters.company" :class="selectClass">
                        <option :value="null">{{ t('shares.all_companies') }}</option>
                        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <Input v-model="filters.search" type="search" class="max-w-sm flex-1" :placeholder="t('shares.search_placeholder')" />
                </div>

                <p v-if="!filters.search && !filters.company" class="text-sm text-muted-foreground">{{ t('shares.type_to_search') }}</p>
                <p v-else-if="!results.length" class="text-sm text-muted-foreground">{{ t('shares.no_results') }}</p>

                <section v-for="group in results" :key="group.company" class="space-y-2">
                    <h3 class="font-semibold">{{ group.company }}</h3>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr v-if="filters.kind === 'parts'">
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.code') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.name') }}</th>
                                    <th class="hidden px-3 py-2 font-semibold sm:table-cell">{{ t('shares.brand') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('shares.in_stock') }}</th>
                                </tr>
                                <tr v-else>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.code') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.name') }}</th>
                                    <th class="hidden px-3 py-2 font-semibold sm:table-cell">{{ t('shares.serial') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.status') }}</th>
                                    <th class="hidden px-3 py-2 font-semibold md:table-cell">{{ t('shares.location') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-if="filters.kind === 'parts'">
                                    <tr v-for="row in group.rows" :key="String(row.code)" class="border-t">
                                        <td class="px-3 py-2 font-mono text-xs">{{ row.code }}</td>
                                        <td class="px-3 py-2">
                                            {{ row.name
                                            }}<span v-if="row.part_number" class="ml-1 text-xs text-muted-foreground">{{ row.part_number }}</span>
                                        </td>
                                        <td class="hidden px-3 py-2 sm:table-cell">{{ row.brand ?? '' }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ row.qty_on_hand }} {{ row.unit ?? '' }}</td>
                                    </tr>
                                </template>
                                <template v-else>
                                    <tr v-for="row in group.rows" :key="String(row.asset_code)" class="border-t">
                                        <td class="px-3 py-2 font-mono text-xs">{{ row.asset_code }}</td>
                                        <td class="px-3 py-2">
                                            {{ row.name }}
                                            <span class="block text-xs text-muted-foreground">{{
                                                [row.category, row.brand, row.model].filter(Boolean).join(' · ')
                                            }}</span>
                                        </td>
                                        <td class="hidden px-3 py-2 font-mono text-xs sm:table-cell">{{ row.serial_number ?? '' }}</td>
                                        <td class="px-3 py-2"><AssetStatusBadge :status="String(row.status)" /></td>
                                        <td class="hidden px-3 py-2 md:table-cell">{{ row.location ?? '' }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
                <p v-if="results.length" class="text-xs text-muted-foreground">{{ t('shares.limit_note') }}</p>
            </template>
        </div>
    </AppLayout>
</template>
