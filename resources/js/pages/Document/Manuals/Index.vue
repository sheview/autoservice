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
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BookOpen, ExternalLink, Paperclip, Plus } from 'lucide-vue-next';

interface ManualRow {
    id: number;
    title: string;
    category: string | null;
    description: string | null;
    links: { label: string | null; url: string }[];
    files_count: number;
    updated_at: string | null;
}

interface Filters {
    search: string;
    category: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    manuals: Paginated<ManualRow>;
    filters: Filters;
    categories: string[];
    can: { manage: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('manuals.title'), href: route('document.manuals.index') }];
const filters = useListFilters('document.manuals.index', { ...props.filters });

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('manuals.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('manuals.title')" :description="t('manuals.description')" />
                <Button v-if="can.manage" as-child>
                    <Link :href="route('document.manuals.create')">
                        <Plus class="h-4 w-4" />
                        {{ t('manuals.create') }}
                    </Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('manuals.search_placeholder')" />
                <select v-model="filters.category" :class="selectClass" :aria-label="t('manuals.category')">
                    <option :value="null">{{ t('manuals.all_categories') }}</option>
                    <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in ['title', 'category']" :key="column" class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy(column)">
                                    {{ column === 'title' ? t('manuals.name') : t('manuals.category') }}
                                    <template v-if="filters.sort === column">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('manuals.links') }} / {{ t('manuals.files') }}</th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('updated_at')">
                                    {{ t('manuals.updated_at') }}
                                    <template v-if="filters.sort === 'updated_at'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in manuals.data" :key="row.id" class="border-t align-top">
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('document.manuals.show', row.id)"
                                    class="flex items-center gap-2 font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    <BookOpen class="h-4 w-4 shrink-0" />
                                    {{ row.title }}
                                </Link>
                                <p v-if="row.description" class="line-clamp-2 text-xs text-muted-foreground">{{ row.description }}</p>
                            </td>
                            <td class="px-4 py-2">{{ row.category ?? '-' }}</td>
                            <td class="px-4 py-2">
                                <!-- One link: open it right from the list. -->
                                <a
                                    v-if="row.links.length === 1"
                                    :href="row.links[0].url"
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    class="flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                                >
                                    <ExternalLink class="h-3 w-3" />{{ row.links[0].label ?? t('manuals.open') }}
                                </a>
                                <span v-else-if="row.links.length" class="flex items-center gap-1 text-xs text-muted-foreground">
                                    <ExternalLink class="h-3 w-3" />{{ t('manuals.links_count', { count: row.links.length }) }}
                                </span>
                                <span v-if="row.files_count" class="flex items-center gap-1 text-xs text-muted-foreground">
                                    <Paperclip class="h-3 w-3" />{{ t('manuals.files_count', { count: row.files_count }) }}
                                </span>
                                <span v-if="!row.links.length && !row.files_count" class="text-xs text-muted-foreground">-</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ dateTime(row.updated_at) }}</td>
                        </tr>
                        <tr v-if="manuals.data.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ t('manuals.no_manuals') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="manuals" />
        </div>
    </AppLayout>
</template>
