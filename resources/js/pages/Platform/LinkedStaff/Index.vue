<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

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

interface Pool {
    id: number;
    from: string;
    to: string;
    roles: string[];
    role_labels: string[];
    is_active: boolean;
}

const props = defineProps<{
    pools: Pool[];
    roleOptions: { name: string; label: string }[];
    staff: Paginated<Person>;
    tenants: { id: number; ulid: string; name: string }[];
    filters: Filters;
}>();

// A new pair of companies sharing their staff (technicians by default).
const poolForm = useForm<{ from_tenant_id: number | null; to_tenant_id: number | null; roles: string[] }>({
    from_tenant_id: null,
    to_tenant_id: null,
    roles: ['technician'],
});
const addPool = () => poolForm.post(route('platform.staff-pools.store'), { preserveScroll: true, onSuccess: () => poolForm.reset() });

const updatePool = (pool: Pool, changes: Partial<Pick<Pool, 'roles' | 'is_active'>>) =>
    router.put(route('platform.staff-pools.update', pool.id), { roles: pool.roles, is_active: pool.is_active, ...changes }, { preserveScroll: true });

const toggleRole = (pool: Pool, role: string, on: boolean) =>
    updatePool(pool, { roles: on ? [...pool.roles, role] : pool.roles.filter((name) => name !== role) });

const removePool = (pool: Pool) => {
    if (confirm(t('linked_staff.pool_delete_confirm', { from: pool.from, to: pool.to }))) {
        router.delete(route('platform.staff-pools.destroy', pool.id), { preserveScroll: true });
    }
};

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('linked_staff.title'), href: route('platform.linked-staff.index') }];

const filters = useListFilters('platform.linked-staff.index', props.filters);

// Boxes changed but not saved yet, by "person-company": saved together with "บันทึก" (one request,
// not one per box). Kept across pages and searches until saved or discarded.
const draft = ref<Record<string, boolean>>({});
const cell = (person: Person, tenantId: number) => `${person.id}-${tenantId}`;
const isOn = (person: Person, tenantId: number) => draft.value[cell(person, tenantId)] ?? person.on.includes(tenantId);
const isChanged = (person: Person, tenantId: number) => cell(person, tenantId) in draft.value;
const setOn = (person: Person, tenantId: number, on: boolean) => {
    const changes = { ...draft.value };
    if (on === person.on.includes(tenantId)) {
        delete changes[cell(person, tenantId)];
    } else {
        changes[cell(person, tenantId)] = on;
    }
    draft.value = changes;
};
const pending = computed(() => Object.keys(draft.value).length);

// A company's column on this page: everyone but those whose own company it is.
const column = (tenantId: number) => props.staff.data.filter((person) => person.home !== tenantId);
const columnState = (tenantId: number) => {
    const on = column(tenantId).filter((person) => isOn(person, tenantId)).length;
    return { all: on > 0 && on === column(tenantId).length, some: on > 0 && on < column(tenantId).length };
};
const setColumn = (tenantId: number, on: boolean) => column(tenantId).forEach((person) => setOn(person, tenantId, on));

const saving = ref(false);
const save = () =>
    router.put(
        route('platform.linked-staff.update-many'),
        {
            changes: Object.entries(draft.value).map(([key, active]) => {
                const [user, tenantId] = key.split('-').map(Number);
                return { user, tenant_id: tenantId, active };
            }),
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onSuccess: () => (draft.value = {}),
            onFinish: () => (saving.value = false),
        },
    );

// After a save (or another page of people): what now matches the saved state is no longer a change;
// what was refused (e.g. a company's last admin) stays ticked as a change.
watch(
    () => props.staff,
    () =>
        props.staff.data.forEach((person) =>
            props.tenants.forEach((tenant) => isChanged(person, tenant.id) && setOn(person, tenant.id, isOn(person, tenant.id))),
        ),
);

// Leaving the page with changes not saved: ask first (paging and searching here keep them).
const leaving = (url: URL) => pending.value > 0 && !url.pathname.startsWith(new URL(route('platform.linked-staff.index')).pathname);
const beforeUnload = (event: BeforeUnloadEvent) => {
    if (pending.value > 0) event.preventDefault();
};
let stopListening: () => void = () => {};
onMounted(() => {
    stopListening = router.on('before', (event) => (leaving(event.detail.visit.url) ? confirm(t('linked_staff.leave_confirm')) : true));
    window.addEventListener('beforeunload', beforeUnload);
});
onUnmounted(() => {
    stopListening();
    window.removeEventListener('beforeunload', beforeUnload);
});

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

            <section class="space-y-3 rounded-md border p-4">
                <div>
                    <h3 class="font-semibold">{{ t('linked_staff.pools_title') }}</h3>
                    <p class="text-sm text-muted-foreground">{{ t('linked_staff.pools_description') }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left">
                            <tr>
                                <th class="py-2 pr-4 font-medium">{{ t('linked_staff.pool_from') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ t('linked_staff.pool_to') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ t('linked_staff.pool_roles') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ t('linked_staff.pool_active') }}</th>
                                <th class="py-2 text-right font-medium">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="pool in pools" :key="pool.id" class="border-t align-top">
                                <td class="py-2 pr-4 font-medium">{{ pool.from }}</td>
                                <td class="py-2 pr-4 font-medium">→ {{ pool.to }}</td>
                                <td class="py-2 pr-4">
                                    <div class="flex flex-wrap gap-3">
                                        <label v-for="role in roleOptions" :key="role.name" class="flex items-center gap-1.5">
                                            <input
                                                type="checkbox"
                                                class="size-4 rounded border-input"
                                                :checked="pool.roles.includes(role.name)"
                                                :disabled="pool.roles.length === 1 && pool.roles.includes(role.name)"
                                                @change="toggleRole(pool, role.name, ($event.target as HTMLInputElement).checked)"
                                            />
                                            {{ role.label }}
                                        </label>
                                    </div>
                                </td>
                                <td class="py-2 pr-4">
                                    <input
                                        type="checkbox"
                                        class="size-4 rounded border-input"
                                        :checked="pool.is_active"
                                        :aria-label="t('linked_staff.pool_active')"
                                        @change="updatePool(pool, { is_active: ($event.target as HTMLInputElement).checked })"
                                    />
                                </td>
                                <td class="py-2 text-right">
                                    <button
                                        type="button"
                                        class="text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                        @click="removePool(pool)"
                                    >
                                        {{ t('common.delete') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="pools.length === 0">
                                <td colspan="5" class="py-4 text-center text-muted-foreground">{{ t('linked_staff.pool_none') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <form class="flex flex-wrap items-end gap-3 border-t pt-3" @submit.prevent="addPool">
                    <label class="grid gap-1 text-sm">
                        {{ t('linked_staff.pool_from') }}
                        <select v-model="poolForm.from_tenant_id" :class="selectClass" required>
                            <option :value="null" disabled>{{ t('linked_staff.choose_company') }}</option>
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ t('linked_staff.pool_to') }}
                        <select v-model="poolForm.to_tenant_id" :class="selectClass" required>
                            <option :value="null" disabled>{{ t('linked_staff.choose_company') }}</option>
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id" :disabled="tenant.id === poolForm.from_tenant_id">
                                {{ tenant.name }}
                            </option>
                        </select>
                    </label>
                    <fieldset class="grid gap-1 text-sm">
                        <legend class="mb-1">{{ t('linked_staff.pool_roles') }}</legend>
                        <div class="flex h-9 flex-wrap items-center gap-3">
                            <label v-for="role in roleOptions" :key="role.name" class="flex items-center gap-1.5">
                                <input v-model="poolForm.roles" type="checkbox" :value="role.name" class="size-4 rounded border-input" />
                                {{ role.label }}
                            </label>
                        </div>
                    </fieldset>
                    <Button :disabled="poolForm.processing || !poolForm.from_tenant_id || !poolForm.to_tenant_id || poolForm.roles.length === 0">
                        {{ t('linked_staff.pool_add') }}
                    </Button>
                </form>
            </section>

            <div>
                <h3 class="font-semibold">{{ t('linked_staff.people_title') }}</h3>
                <p class="text-sm text-muted-foreground">{{ t('linked_staff.people_description') }}</p>
            </div>

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
                            <th v-for="tenant in tenants" :key="tenant.id" class="px-4 py-2 text-center font-medium">
                                {{ tenant.name }}
                                <label
                                    v-if="column(tenant.id).length"
                                    class="mt-1 flex items-center justify-center gap-1.5 text-xs font-normal text-muted-foreground"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 rounded border-input"
                                        :checked="columnState(tenant.id).all"
                                        :indeterminate="columnState(tenant.id).some"
                                        @change="setColumn(tenant.id, ($event.target as HTMLInputElement).checked)"
                                    />
                                    {{ t('linked_staff.select_all') }}
                                </label>
                            </th>
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
                            <td
                                v-for="tenant in tenants"
                                :key="tenant.id"
                                class="px-4 py-2 text-center"
                                :class="{ 'bg-amber-50 dark:bg-amber-950/40': isChanged(person, tenant.id) }"
                            >
                                <span v-if="person.home === tenant.id" class="rounded bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                                    {{ t('linked_staff.home') }}
                                </span>
                                <input
                                    v-else
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                    :checked="isOn(person, tenant.id)"
                                    :aria-label="`${person.name} — ${tenant.name}`"
                                    @change="setOn(person, tenant.id, ($event.target as HTMLInputElement).checked)"
                                />
                            </td>
                        </tr>
                        <tr v-if="staff.data.length === 0">
                            <td :colspan="tenants.length + 1" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- The changes not saved yet: saved in one go -->
            <div
                v-if="pending"
                class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 shadow-lg dark:border-amber-800 dark:bg-amber-950"
            >
                <span class="text-sm font-medium">{{ t('linked_staff.pending', { count: pending }) }}</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="saving" @click="draft = {}">{{ t('linked_staff.discard') }}</Button>
                    <Button size="sm" :disabled="saving" @click="save">{{ t('linked_staff.save') }}</Button>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">{{ t('linked_staff.new_hint') }}</p>

            <Pagination :paginator="staff" />
        </div>
    </AppLayout>
</template>
