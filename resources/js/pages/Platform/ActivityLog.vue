<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { reactive } from 'vue';

interface Entry {
    id: number;
    at: string | null;
    description: string;
    event: string | null;
    subject: string | null;
    subject_id: number | null;
    actor: string | null;
    actor_email: string | null;
    impersonating: boolean;
    details: Record<string, unknown>;
}

interface Filters {
    search: string;
    subject: string | null;
    from: string | null;
    to: string | null;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    entries: Paginated<Entry>;
    filters: Filters;
    subjects: { value: string; label: string }[];
    keepDays: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('activity_log.title'), href: route('platform.activity-log') }];
// Empty date boxes are '' (not sent), as the date input wants a string.
const filters = useListFilters('platform.activity-log', { ...props.filters, from: props.filters.from ?? '', to: props.filters.to ?? '' });

// Details open under a row on demand.
const open = reactive(new Set<number>());
const toggle = (id: number) => (open.has(id) ? open.delete(id) : open.add(id));
const hasDetails = (entry: Entry) => Object.keys(entry.details).length > 0;

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('activity_log.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('activity_log.title')" :description="t('activity_log.description', { days: keepDays })" />

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('activity_log.search_placeholder')" />
                <select v-model="filters.subject" :class="selectClass" :aria-label="t('activity_log.subject')">
                    <option :value="null">{{ t('activity_log.all_subjects') }}</option>
                    <option v-for="subject in subjects" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
                </select>
                <label class="flex items-center gap-2 text-sm">
                    {{ t('activity_log.from') }}
                    <Input v-model="filters.from" type="date" class="w-40" />
                </label>
                <label class="flex items-center gap-2 text-sm">
                    {{ t('activity_log.to') }}
                    <Input v-model="filters.to" type="date" class="w-40" />
                </label>
                <select v-model="filters.direction" :class="selectClass" :aria-label="t('activity_log.at')">
                    <option value="desc">{{ t('activity_log.newest') }}</option>
                    <option value="asc">{{ t('activity_log.oldest') }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-8 px-2 py-2" />
                            <th class="px-4 py-2 font-medium">{{ t('activity_log.at') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('activity_log.actor') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('activity_log.action') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('activity_log.subject') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="entry in entries.data" :key="entry.id">
                            <tr class="border-t align-top">
                                <td class="px-2 py-2">
                                    <button
                                        v-if="hasDetails(entry)"
                                        type="button"
                                        class="rounded p-0.5 hover:bg-muted"
                                        :aria-label="t('activity_log.details')"
                                        :aria-expanded="open.has(entry.id)"
                                        @click="toggle(entry.id)"
                                    >
                                        <ChevronRight class="h-4 w-4 transition-transform" :class="{ 'rotate-90': open.has(entry.id) }" />
                                    </button>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">{{ dateTime(entry.at) }}</td>
                                <td class="px-4 py-2">
                                    <div>{{ entry.actor ?? t('activity_log.system') }}</div>
                                    <div v-if="entry.actor_email" class="text-xs text-muted-foreground">{{ entry.actor_email }}</div>
                                    <span
                                        v-if="entry.impersonating"
                                        class="rounded bg-amber-100 px-1.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                                        >{{ t('activity_log.impersonating') }}</span
                                    >
                                </td>
                                <td class="px-4 py-2">
                                    <div>{{ entry.description }}</div>
                                    <div v-if="entry.event" class="font-mono text-xs text-muted-foreground">{{ entry.event }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                    <template v-if="entry.subject">{{ entry.subject }} #{{ entry.subject_id }}</template>
                                    <template v-else>-</template>
                                </td>
                            </tr>
                            <tr v-if="open.has(entry.id)" class="bg-muted/30">
                                <td />
                                <td colspan="4" class="px-4 py-2">
                                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all font-mono text-xs">{{
                                        JSON.stringify(entry.details, null, 2)
                                    }}</pre>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="entries.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('activity_log.no_entries') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="entries" />
        </div>
    </AppLayout>
</template>
