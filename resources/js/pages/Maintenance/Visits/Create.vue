<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface AssetRow {
    id: number;
    asset_code: string;
    name: string;
    category: string | null;
    serial_number: string | null;
    location: string | null;
}

const props = defineProps<{
    customers: { id: number; code: string; name: string }[];
    customerId: number | null;
    contracts: Record<string, { id: number; contract_no: string; title: string }>;
    assets: AssetRow[];
    assignees: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_visits.title'), href: route('maintenance.visits.index') },
    { title: t('pm_visits.create'), href: route('maintenance.visits.create') },
];

const form = useForm({
    customer_id: props.customerId,
    contract_id: null as number | null,
    title: '',
    due_on: '',
    scheduled_on: '',
    assignee_id: null as number | null,
    asset_ids: [] as number[],
    draft: false as boolean,
});

// Another customer: its contracts and equipment come from the server; what was picked goes.
watch(
    () => form.customer_id,
    (id) => {
        form.contract_id = null;
        form.asset_ids = [];
        router.reload({ only: ['contracts', 'assets', 'customerId'], data: { customer_id: id ?? undefined } });
    },
);

const filter = ref('');
const shown = computed(() => {
    const q = filter.value.trim().toLowerCase();
    return q
        ? props.assets.filter((a) => [a.asset_code, a.name, a.category, a.serial_number, a.location].some((v) => v?.toLowerCase().includes(q)))
        : props.assets;
});
const allShownPicked = computed(() => shown.value.length > 0 && shown.value.every((a) => form.asset_ids.includes(a.id)));
const toggleAll = () => {
    const ids = shown.value.map((a) => a.id);
    form.asset_ids = allShownPicked.value ? form.asset_ids.filter((id) => !ids.includes(id)) : [...new Set([...form.asset_ids, ...ids])];
};

const save = (draft: boolean) => {
    form.draft = draft;
    form.transform((data) => ({ ...data, scheduled_on: data.scheduled_on || null })).post(route('maintenance.visits.store'));
};

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_visits.create')" />

        <form class="max-w-4xl space-y-6 p-4" @submit.prevent="save(false)">
            <Heading :title="t('pm_visits.create')" :description="t('pm_visits.create_description')" />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="customer_id" required>{{ t('pm_visits.customer') }}</Label>
                    <select id="customer_id" v-model="form.customer_id" :class="selectClass" required>
                        <option :value="null" disabled>{{ t('pm_visits.pick_customer') }}</option>
                        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                            {{ customer.name }} ({{ customer.code }})
                        </option>
                    </select>
                    <InputError :message="form.errors.customer_id" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="contract_id">{{ t('pm_visits.contract') }}</Label>
                    <select id="contract_id" v-model="form.contract_id" :class="selectClass" :disabled="!form.customer_id">
                        <option :value="null">{{ t('pm_visits.no_contract') }}</option>
                        <option v-for="contract in contracts" :key="contract.id" :value="contract.id">
                            {{ contract.contract_no }} {{ contract.title }}
                        </option>
                    </select>
                    <InputError :message="form.errors.contract_id" />
                </div>
                <div class="grid content-start gap-2 sm:col-span-2">
                    <Label for="title" required>{{ t('pm_visits.work_title') }}</Label>
                    <Input id="title" v-model="form.title" :placeholder="t('pm_visits.work_title_placeholder')" required />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="due_on" required>{{ t('pm_visits.due_on') }}</Label>
                    <Input id="due_on" v-model="form.due_on" type="date" required />
                    <InputError :message="form.errors.due_on" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="scheduled_on">{{ t('pm_visits.scheduled_on') }}</Label>
                    <Input id="scheduled_on" v-model="form.scheduled_on" type="date" />
                    <InputError :message="form.errors.scheduled_on" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="assignee_id">{{ t('pm_visits.assignee') }}</Label>
                    <select id="assignee_id" v-model="form.assignee_id" :class="selectClass">
                        <option :value="null">{{ t('pm_visits.unassigned') }}</option>
                        <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                    </select>
                    <InputError :message="form.errors.assignee_id" />
                </div>
            </div>

            <!-- Equipment of the customer -->
            <section v-if="form.customer_id" class="space-y-2">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('pm_visits.equipment') }} ({{ form.asset_ids.length }})</h3>
                        <p class="text-xs text-muted-foreground">{{ t('pm_visits.equipment_hint') }}</p>
                    </div>
                    <Input v-model="filter" type="search" class="max-w-xs" :placeholder="t('pm_visits.filter_equipment')" />
                </div>
                <div v-if="assets.length" class="max-h-96 overflow-y-auto rounded-md border">
                    <label class="flex items-center gap-3 border-b bg-muted/40 px-3 py-2 text-sm font-medium">
                        <input type="checkbox" class="size-4 rounded border-input" :checked="allShownPicked" @change="toggleAll" />
                        {{ t('pm_visits.select_all') }}
                    </label>
                    <label v-for="asset in shown" :key="asset.id" class="flex items-center gap-3 border-b px-3 py-1.5 text-sm last:border-b-0">
                        <input v-model="form.asset_ids" type="checkbox" :value="asset.id" class="size-4 rounded border-input" />
                        <span class="font-mono text-xs">{{ asset.asset_code }}</span>
                        <span>{{ asset.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            [asset.category, asset.serial_number, asset.location].filter(Boolean).join(' · ')
                        }}</span>
                    </label>
                </div>
                <p v-else class="text-sm text-muted-foreground">{{ t('pm_visits.no_assets') }}</p>
                <InputError :message="form.errors.asset_ids" />
            </section>

            <div class="flex flex-wrap gap-2">
                <Button :disabled="form.processing">{{ t('pm_visits.save_scheduled') }}</Button>
                <Button type="button" variant="outline" :disabled="form.processing" @click="save(true)">{{ t('pm_visits.save_draft') }}</Button>
                <Button variant="ghost" as-child>
                    <Link :href="route('maintenance.visits.index')">{{ t('common.cancel') }}</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
