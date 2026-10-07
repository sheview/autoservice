<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Person {
    id: number;
    name: string;
    email: string;
    employee_code: string | null;
    is_active: boolean;
    home: number;
    on: number[];
    off: number[];
}

interface Filters {
    search: string;
    tenant_id: number | null;
    [key: string]: unknown;
}

const props = defineProps<{
    staff: Paginated<Person>;
    tenants: { id: number; ulid: string; name: string }[];
    filters: Filters;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('linked_staff.title'), href: route('platform.linked-staff.index') }];

const filters = useListFilters('platform.linked-staff.index', props.filters);

// The cell being saved ("person-tenant"), to disable it meanwhile.
const saving = ref<string | null>(null);

const toggle = (person: Person, tenant: { id: number; ulid: string }, active: boolean) => {
    saving.value = `${person.id}-${tenant.id}`;
    router.put(
        route('platform.linked-staff.update', [person.id, tenant.ulid]),
        { active },
        // Not preserving state: a refused change (e.g. the company's last admin) puts the box back.
        { preserveScroll: true, preserveState: false, onFinish: () => (saving.value = null) },
    );
};

const errors = () => Object.values(page.props.errors as Record<string, string>);
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('linked_staff.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('linked_staff.title')" :description="t('linked_staff.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError v-for="(message, index) in errors()" :key="index" :message="message" />

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('linked_staff.search_placeholder')" />
                <select v-model="filters.tenant_id" :class="selectClass" :aria-label="t('linked_staff.home')">
                    <option :value="null">{{ t('linked_staff.all_companies') }}</option>
                    <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('linked_staff.person') }}</th>
                            <th v-for="tenant in tenants" :key="tenant.id" class="px-4 py-2 text-center font-medium">{{ tenant.name }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="person in staff.data" :key="person.id" class="border-t">
                            <td class="px-4 py-2">
                                <div class="font-medium">
                                    {{ person.name }}
                                    <span v-if="!person.is_active" class="ml-1 text-xs text-muted-foreground"
                                        >({{ t('linked_staff.inactive') }})</span
                                    >
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ person.email }}<template v-if="person.employee_code"> · {{ person.employee_code }}</template>
                                </div>
                            </td>
                            <td v-for="tenant in tenants" :key="tenant.id" class="px-4 py-2 text-center">
                                <span v-if="person.home === tenant.id" class="rounded bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                                    {{ t('linked_staff.home') }}
                                </span>
                                <input
                                    v-else
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                    :checked="person.on.includes(tenant.id)"
                                    :disabled="saving === `${person.id}-${tenant.id}`"
                                    :aria-label="`${person.name} — ${tenant.name}`"
                                    @change="toggle(person, tenant, ($event.target as HTMLInputElement).checked)"
                                />
                            </td>
                        </tr>
                        <tr v-if="staff.data.length === 0">
                            <td :colspan="tenants.length + 1" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-muted-foreground">{{ t('linked_staff.new_hint') }}</p>

            <Pagination :paginator="staff" />
        </div>
    </AppLayout>
</template>
