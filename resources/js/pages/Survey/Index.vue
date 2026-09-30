<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import StarRating from '@/components/StarRating.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface SurveyRow {
    id: number;
    ticket_ulid: string;
    ticket_no: string;
    ticket_title: string;
    score: number | null;
    comment: string | null;
    answered_name: string | null;
    customer: string | null;
    assignee: string | null;
    answered_at: string | null;
    created_at: string;
}

interface Filters {
    search: string;
    status: string | null;
    score: number | null;
    customer_id: number | null;
    assignee_id: number | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    surveys: Paginated<SurveyRow>;
    summary: { sent: number; answered: number; response_rate: number | null; average: number | null; scores: Record<string, number> };
    filters: Filters;
    statuses: string[];
    customers: { id: number; code: string; name: string }[];
    technicians: { id: number; name: string }[];
    can: { viewTickets: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('surveys.title'), href: route('survey.surveys.index') }];

const filters = useListFilters('survey.surveys.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const columns = [
    { key: 'ticket_no', sortable: true },
    { key: 'customer', sortable: false },
    { key: 'assignee', sortable: false },
    { key: 'score', sortable: true },
    { key: 'comment', sortable: false },
    { key: 'answered_at', sortable: true },
    { key: 'created_at', sortable: true },
];

const scoreOptions = [5, 4, 3, 2, 1];
// Width of a bar of the score distribution, as a share of the answers.
const share = (count: number) => (props.summary.answered ? `${Math.round((count * 100) / props.summary.answered)}%` : '0%');

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('surveys.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('surveys.title')" :description="t('surveys.description')" />

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">{{ t('surveys.summary.average') }}</p>
                    <p class="text-2xl font-semibold">
                        {{ summary.average === null ? t('common.none') : summary.average.toFixed(2) }}
                        <span class="text-sm font-normal text-muted-foreground">{{ t('surveys.summary.out_of', { max: 5 }) }}</span>
                    </p>
                    <StarRating :model-value="summary.average === null ? null : Math.round(summary.average)" />
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">{{ t('surveys.summary.response_rate') }}</p>
                    <p class="text-2xl font-semibold">{{ summary.response_rate === null ? t('common.none') : `${summary.response_rate}%` }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('surveys.summary.answered_of', { answered: summary.answered, sent: summary.sent }) }}
                    </p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="mb-1 text-xs text-muted-foreground">{{ t('surveys.summary.distribution') }}</p>
                    <div v-for="score in scoreOptions" :key="score" class="flex items-center gap-2 text-xs">
                        <span class="w-3 text-right">{{ score }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded bg-muted">
                            <span class="block h-full bg-amber-400" :style="{ width: share(summary.scores[score] ?? 0) }" />
                        </span>
                        <span class="w-6 text-right text-muted-foreground">{{ summary.scores[score] ?? 0 }}</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('surveys.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('common.status')">
                    <option :value="null">{{ t('surveys.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`surveys.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.score" :class="selectClass" :aria-label="t('surveys.score')">
                    <option :value="null">{{ t('surveys.all_scores') }}</option>
                    <option v-for="score in scoreOptions" :key="score" :value="score">{{ t('surveys.score_option', { score }) }}</option>
                </select>
                <select v-if="customers.length" v-model="filters.customer_id" :class="selectClass" :aria-label="t('surveys.customer')">
                    <option :value="null">{{ t('surveys.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-if="technicians.length" v-model="filters.assignee_id" :class="selectClass" :aria-label="t('surveys.assignee')">
                    <option :value="null">{{ t('surveys.all_technicians') }}</option>
                    <option v-for="technician in technicians" :key="technician.id" :value="technician.id">{{ technician.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`surveys.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`surveys.${column.key}`) }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="survey in surveys.data" :key="survey.id" class="border-t align-top">
                            <td class="px-4 py-2">
                                <Link
                                    v-if="can.viewTickets"
                                    :href="route('service.tickets.show', survey.ticket_ulid)"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                >
                                    {{ survey.ticket_no }}
                                </Link>
                                <span v-else class="font-mono text-xs">{{ survey.ticket_no }}</span>
                                <div>{{ survey.ticket_title }}</div>
                            </td>
                            <td class="px-4 py-2">{{ survey.customer ?? t('common.none') }}</td>
                            <td class="px-4 py-2">{{ survey.assignee ?? t('common.none') }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <StarRating v-if="survey.score !== null" :model-value="survey.score" />
                                <span v-else class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ t('surveys.statuses.pending') }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-2">
                                <p v-if="survey.comment" class="whitespace-pre-line">{{ survey.comment }}</p>
                                <p v-if="survey.answered_name" class="text-xs text-muted-foreground">
                                    {{ t('surveys.answered_by', { name: survey.answered_name }) }}
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ survey.answered_at ? dateTime(survey.answered_at) : t('common.none') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ dateTime(survey.created_at) }}</td>
                        </tr>
                        <tr v-if="surveys.data.length === 0">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="surveys" />
        </div>
    </AppLayout>
</template>
