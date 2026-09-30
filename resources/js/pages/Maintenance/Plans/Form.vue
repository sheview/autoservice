<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
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
    plan: { id: number; title: string; interval_months: number; assignee_id: number | null; notes: string | null; contract_no: string | null } | null;
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
});

const contract = computed(() => props.contracts?.find((c) => c.id === form.contract_id) ?? null);

// Picking a contract fills the interval from the contract and suggests a title.
watch(
    contract,
    (picked) => {
        if (!picked || props.plan) return;
        if (picked.pm_interval_months) form.interval_months = picked.pm_interval_months;
        if (!form.title) form.title = `PM ${picked.title}`;
    },
    { immediate: true },
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

        <div class="max-w-3xl p-4">
            <Heading :title="title" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
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

                <div class="grid gap-2">
                    <Label for="title">{{ t('pm_plans.title_field') }}</Label>
                    <Input id="title" v-model="form.title" required autocomplete="off" />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
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
                    <div class="grid gap-2">
                        <Label for="assignee_id">{{ t('pm_plans.assignee') }}</Label>
                        <select id="assignee_id" v-model="form.assignee_id" :class="selectClass">
                            <option :value="null">{{ t('pm_plans.no_assignee') }}</option>
                            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="form.errors.assignee_id" />
                    </div>
                </div>

                <div class="grid gap-2">
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
