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

interface TemplateRow {
    id: number;
    code: string;
    name: string;
    version: number;
    status: 'draft' | 'published' | 'archived';
    published_version: number | null;
    service_line: string | null;
    layout: string;
    items_count: number;
    tied_to: string[];
    updated_at: string;
}

interface Filters {
    search: string;
    status: string | null;
    service_line: string | null;
    category: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    checklists: Paginated<TemplateRow>;
    filters: Filters;
    categories: { id: number; name: string }[];
    statuses: string[];
    serviceLines: string[];
    can: { create: boolean; update: boolean };
    // Standard templates the company does not have yet (names).
    standardMissing: string[];
}>();

const addStandard = () => router.post(route('maintenance.checklists.standard'), {}, { preserveScroll: true });

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_checklists.title'), href: route('maintenance.checklists.index') }];

const filters = useListFilters('maintenance.checklists.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const statusClass: Record<string, string> = {
    draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    published: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    archived: 'bg-muted text-muted-foreground',
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

            <!-- Standard templates not added yet: add them all in one go, then change them as you like -->
            <div v-if="standardMissing.length" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed p-4">
                <div class="min-w-0 space-y-1">
                    <p class="text-sm font-medium">{{ t('pm_checklists.standard_title', { count: standardMissing.length }) }}</p>
                    <p class="text-xs text-muted-foreground">{{ standardMissing.join(' · ') }}</p>
                    <p class="text-xs text-muted-foreground">{{ t('pm_checklists.standard_hint') }}</p>
                </div>
                <Button variant="outline" @click="addStandard">{{ t('pm_checklists.standard_add') }}</Button>
            </div>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_checklists.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('pm_checklists.status')">
                    <option :value="null">{{ t('pm_checklists.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`pm_checklists.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.service_line" :class="selectClass" :aria-label="t('pm_checklists.service_line')">
                    <option :value="null">{{ t('pm_checklists.all_service_lines') }}</option>
                    <option v-for="line in serviceLines" :key="line" :value="line">{{ t(`pm_checklists.service_lines.${line}`) }}</option>
                </select>
                <select v-model="filters.category" :class="selectClass" :aria-label="t('pm_checklists.category')">
                    <option :value="null">{{ t('pm_checklists.all_categories') }}</option>
                    <option value="default">{{ t('pm_checklists.default_assignment') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in ['code', 'name']" :key="column" class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy(column)">
                                    {{ t(`pm_checklists.${column}`) }}
                                    <template v-if="filters.sort === column">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_checklists.status') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_checklists.service_line') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_checklists.tied_to') }}</th>
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
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="template in checklists.data" :key="template.id" class="border-t">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ template.code }}</td>
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('maintenance.checklists.edit', template.id)"
                                    class="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    {{ template.name }}
                                </Link>
                                <div class="text-xs text-muted-foreground">{{ t(`pm_checklists.layouts.${template.layout}`) }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="statusClass[template.status]">
                                    {{ t(`pm_checklists.statuses.${template.status}`) }}
                                    {{ t('pm_checklists.version_value', { version: template.version }) }}
                                </span>
                                <div v-if="template.status === 'draft' && template.published_version" class="mt-0.5 text-xs text-muted-foreground">
                                    {{ t('pm_checklists.published_version', { version: template.published_version }) }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                {{
                                    template.service_line
                                        ? t(`pm_checklists.service_lines.${template.service_line}`)
                                        : t('pm_checklists.no_service_line')
                                }}
                            </td>
                            <td class="px-4 py-2 text-xs">
                                {{ template.tied_to.length ? template.tied_to.join(', ') : t('pm_checklists.not_tied') }}
                            </td>
                            <td class="px-4 py-2 text-right">{{ template.items_count }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ dateTime(template.updated_at) }}</td>
                        </tr>
                        <tr v-if="checklists.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="checklists" />
        </div>
    </AppLayout>
</template>
