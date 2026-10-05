<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, TriangleAlert } from 'lucide-vue-next';

interface RoomRow {
    ulid: string;
    name: string;
    location: string | null;
    customer: string;
    requires_id_number: boolean;
    is_active: boolean;
    missing_rules: string;
    rules: { version: number } | null;
    versions: number;
    updated_at: string | null;
}

interface Filters {
    search: string;
    customer_id: number | null;
    rules: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    rooms: Paginated<RoomRow>;
    filters: Filters;
    customers: { id: number; name: string }[];
    missingRules: number;
    settings: { company_terms: string[]; id_retention_days: number };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('server_rooms.title'), href: route('room-access.rooms.index') }];
const filters = useListFilters('room-access.rooms.index', { ...props.filters });
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const settings = useForm({ company_terms: [...props.settings.company_terms], id_retention_days: props.settings.id_retention_days });
const saveSettings = () =>
    settings
        .transform((data) => ({ ...data, company_terms: data.company_terms.filter((line) => line.trim() !== '') }))
        .put(route('room-access.settings'), { preserveScroll: true });

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('server_rooms.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('server_rooms.title')" :description="t('server_rooms.description')" />
                <Button as-child>
                    <Link :href="route('room-access.rooms.create')">{{ t('server_rooms.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p
                v-if="missingRules > 0"
                class="flex items-start gap-2 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />{{ t('server_rooms.missing_banner', { count: missingRules }) }}
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('server_rooms.search')" />
                <select v-model="filters.customer_id" :class="selectClass" :aria-label="t('server_rooms.customer')">
                    <option :value="null">{{ t('server_rooms.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-model="filters.rules" :class="selectClass" :aria-label="t('server_rooms.rules')">
                    <option :value="null">{{ t('server_rooms.all_rules') }}</option>
                    <option value="missing">{{ t('server_rooms.rules_filter.missing') }}</option>
                    <option value="set">{{ t('server_rooms.rules_filter.set') }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="inline-flex items-center gap-1" @click="sortBy('name')">
                                    {{ t('server_rooms.name') }}
                                    <component :is="filters.direction === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'name'" class="size-3" />
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('server_rooms.customer') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('server_rooms.rules') }}</th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="inline-flex items-center gap-1" @click="sortBy('updated_at')">
                                    {{ t('common.updated_at') }}
                                    <component :is="filters.direction === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'updated_at'" class="size-3" />
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="room in rooms.data" :key="room.ulid" class="border-t">
                            <td class="px-4 py-2">
                                <Link :href="route('room-access.rooms.show', room.ulid)" class="font-medium text-primary hover:underline">{{ room.name }}</Link>
                                <span v-if="!room.is_active" class="ml-2 rounded bg-muted px-1.5 text-xs">{{ t('server_rooms.inactive') }}</span>
                                <div v-if="room.location" class="text-xs text-muted-foreground">{{ room.location }}</div>
                            </td>
                            <td class="px-4 py-2">{{ room.customer }}</td>
                            <td class="px-4 py-2">
                                <span v-if="room.rules" class="rounded bg-green-100 px-1.5 text-xs text-green-800 dark:bg-green-950 dark:text-green-200">{{
                                    t('server_rooms.rules_version', { version: room.rules.version })
                                }}</span>
                                <span v-else class="rounded bg-amber-100 px-1.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">{{
                                    t('server_rooms.no_rules')
                                }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{{ room.updated_at ? dateTime(room.updated_at) : '-' }}</td>
                        </tr>
                        <tr v-if="rooms.data.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ t('server_rooms.none') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="rooms" />

            <form class="space-y-3 rounded-md border p-4" @submit.prevent="saveSettings">
                <h3 class="text-sm font-semibold">{{ t('server_rooms.company_terms') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('server_rooms.company_terms_hint') }}</p>
                <div v-for="(_, i) in settings.company_terms" :key="i" class="flex gap-2">
                    <span class="w-6 pt-2 text-right text-xs text-muted-foreground">{{ i + 1 }}.</span>
                    <Input v-model="settings.company_terms[i]" maxlength="500" />
                    <Button type="button" variant="ghost" size="sm" @click="settings.company_terms.splice(i, 1)">{{ t('common.delete') }}</Button>
                </div>
                <Button v-if="settings.company_terms.length < 10" type="button" variant="outline" size="sm" @click="settings.company_terms.push('')">
                    {{ t('server_rooms.add_line') }}
                </Button>
                <div class="grid max-w-xs gap-2">
                    <Label for="retention" required>{{ t('server_rooms.id_retention_days') }}</Label>
                    <Input id="retention" v-model="settings.id_retention_days" type="number" min="7" max="3650" required />
                    <InputError :message="settings.errors.id_retention_days" />
                </div>
                <Button :disabled="settings.processing">{{ t('server_rooms.save_settings') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
