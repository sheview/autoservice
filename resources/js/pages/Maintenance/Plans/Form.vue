<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PmAssetPicker, { type PickableAsset } from '@/components/pm/PmAssetPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

interface ContractOption {
    id: number;
    contract_no: string;
    title: string;
    customer: string | null;
    starts_on: string;
    ends_on: string;
    pm_interval_months: number | null;
    assets_count: number;
}

const props = defineProps<{
    plan: {
        id: number;
        title: string;
        interval_months: number;
        assignee_id: number | null;
        notes: string | null;
        contract_no: string | null;
        active: boolean;
        starts_on: string;
        ends_on: string;
        asset_ids: number[];
        contract_starts_on: string | null;
        contract_ends_on: string | null;
    } | null;
    contractAssets: PickableAsset[];
    contracts?: ContractOption[];
    preselectedContractId?: number | null;
    intervalLocked?: boolean;
    intervals: number[];
    assignees: { id: number; name: string }[];
}>();

const title = props.plan ? t('pm_plans.edit') : t('pm_plans.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_plans.title'), href: route('maintenance.plans.index') },
    ...(props.plan ? [{ title: props.plan.title, href: route('maintenance.plans.show', props.plan.id) }] : []),
    { title, href: props.plan ? route('maintenance.plans.edit', props.plan.id) : route('maintenance.plans.create') },
];

const form = useForm({
    contract_id: props.preselectedContractId ?? (null as number | null),
    title: props.plan?.title ?? '',
    interval_months: props.plan?.interval_months ?? 3,
    assignee_id: props.plan?.assignee_id ?? null,
    notes: props.plan?.notes ?? '',
    active: props.plan?.active ?? true,
    starts_on: props.plan?.starts_on ?? '',
    ends_on: props.plan?.ends_on ?? '',
    asset_ids: props.plan?.asset_ids ?? ([] as number[]),
});

const contract = computed(() => props.contracts?.find((c) => c.id === form.contract_id) ?? null);

// Picking a contract fills the interval from the contract and suggests a title.
watch(
    contract,
    (picked) => {
        if (!picked || props.plan) return;
        if (picked.pm_interval_months) form.interval_months = picked.pm_interval_months;
        if (!form.title) form.title = `PM ${picked.title}`;
        // The plan runs the contract's period unless changed; its equipment is picked from the contract's.
        form.starts_on = picked.starts_on;
        form.ends_on = picked.ends_on;
        form.asset_ids = [];
        router.reload({ only: ['contractAssets'], data: { contract_id: picked.id } });
    },
    { immediate: true },
);
const bounds = computed(() =>
    props.plan
        ? { from: props.plan.contract_starts_on ?? undefined, to: props.plan.contract_ends_on ?? undefined }
        : { from: contract.value?.starts_on, to: contract.value?.ends_on },
);

const submit = () => {
    if (props.plan) {
        // contract_id is ignored on update: a plan stays with its contract.
        form.put(route('maintenance.plans.update', props.plan.id), { preserveScroll: true });
    } else {
        form.post(route('maintenance.plans.store'), { preserveScroll: true });
    }
};

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs disabled:opacity-50';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid content-start gap-2">
                    <Label for="contract_id">{{ t('pm_plans.contract') }}</Label>
                    <template v-if="plan">
                        <p class="font-mono text-sm">{{ plan.contract_no }}</p>
                    </template>
                    <template v-else>
                        <select id="contract_id" v-model="form.contract_id" :class="selectClass" required>
                            <option :value="null" disabled>{{ t('pm_plans.choose_contract') }}</option>
                            <option v-for="option in contracts" :key="option.id" :value="option.id">
                                {{ option.contract_no }} — {{ option.title }}
                            </option>
                        </select>
                        <p v-if="contracts?.length === 0" class="text-sm text-muted-foreground">{{ t('pm_plans.no_contracts') }}</p>
                        <p v-if="contract" class="text-xs text-muted-foreground">
                            {{
                                t('pm_plans.contract_summary', {
                                    customer: contract.customer ?? t('common.none'),
                                    from: contract.starts_on,
                                    to: contract.ends_on,
                                    count: contract.assets_count,
                                })
                            }}
                        </p>
                        <InputError :message="form.errors.contract_id" />
                    </template>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="title">{{ t('pm_plans.title_field') }}</Label>
                    <Input id="title" v-model="form.title" required autocomplete="off" />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="interval_months">{{ t('pm_plans.interval') }}</Label>
                        <select id="interval_months" v-model="form.interval_months" :class="selectClass" :disabled="intervalLocked">
                            <option v-for="months in intervals" :key="months" :value="months">
                                {{ t('pm_plans.interval_months', { count: months }) }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            {{ intervalLocked ? t('pm_plans.interval_locked') : t('pm_plans.interval_hint') }}
                        </p>
                        <InputError :message="form.errors.interval_months" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="assignee_id">{{ t('pm_plans.assignee') }}</Label>
                        <select id="assignee_id" v-model="form.assignee_id" :class="selectClass">
                            <option :value="null">{{ t('pm_plans.no_assignee') }}</option>
                            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="form.errors.assignee_id" />
                    </div>
                </div>

                <div class="grid gap-6 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="starts_on">{{ t('pm_plans.starts_on') }}</Label>
                        <Input id="starts_on" v-model="form.starts_on" type="date" :min="bounds.from" :max="bounds.to" :disabled="intervalLocked" />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="ends_on">{{ t('pm_plans.ends_on') }}</Label>
                        <Input id="ends_on" v-model="form.ends_on" type="date" :min="bounds.from" :max="bounds.to" :disabled="intervalLocked" />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input v-model="form.active" type="checkbox" class="size-4 rounded border-input" />
                        {{ t('pm_plans.active') }}
                    </label>
                </div>

                <div v-if="contractAssets.length" class="grid content-start gap-2">
                    <Label>{{ t('pm_plans.assets') }}</Label>
                    <PmAssetPicker v-model="form.asset_ids" :assets="contractAssets" />
                    <InputError :message="form.errors.asset_ids" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="notes">{{ t('pm_plans.notes') }}</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        class="shadow-xs rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.notes" />
                </div>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="plan ? route('maintenance.plans.show', plan.id) : route('maintenance.plans.index')">{{
                            t('common.cancel')
                        }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
