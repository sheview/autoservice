<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

type SlaInput = { response_hours: number | string | undefined; resolve_hours: number | string | undefined };

interface ContractForm {
    id: number;
    customer_id: number;
    contract_no: string;
    title: string;
    status: string;
    starts_on: string;
    ends_on: string;
    value: string | null;
    service_window: string;
    pm_interval_months: number | null;
    notify_days_before: number;
    notes: string | null;
    require_signature?: boolean | null;
    slas: Record<string, SlaInput> | unknown[];
}

const props = defineProps<{
    contract: ContractForm | null;
    // Files already attached (editing).
    documents: Attachment[];
    customers: { id: number; code: string; name: string }[];
    statuses: string[];
    serviceWindows: string[];
    pmIntervals: number[];
    priorities: string[];
    preselectedCustomerId?: number | null;
}>();

const title = props.contract ? t('contracts.edit') : t('contracts.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('contracts.title'), href: route('contract.contracts.index') },
    ...(props.contract ? [{ title: props.contract.contract_no, href: route('contract.contracts.show', props.contract.id) }] : []),
    { title, href: props.contract ? route('contract.contracts.edit', props.contract.id) : route('contract.contracts.create') },
];

// An empty JSON object arrives as [] from PHP.
const existingSlas = props.contract && !Array.isArray(props.contract.slas) ? props.contract.slas : {};

const form = useForm({
    customer_id: props.contract?.customer_id ?? props.preselectedCustomerId ?? null,
    contract_no: props.contract?.contract_no ?? '',
    title: props.contract?.title ?? '',
    status: props.contract?.status ?? 'active',
    starts_on: props.contract?.starts_on ?? '',
    ends_on: props.contract?.ends_on ?? '',
    value: props.contract?.value ?? '',
    service_window: props.contract?.service_window ?? '8x5',
    pm_interval_months: props.contract?.pm_interval_months ?? null,
    notify_days_before: props.contract?.notify_days_before ?? 60,
    notes: props.contract?.notes ?? '',
    require_signature: (props.contract?.require_signature ?? null) as boolean | null,
    slas: Object.fromEntries(
        props.priorities.map((p) => [
            p,
            { response_hours: existingSlas[p]?.response_hours ?? undefined, resolve_hours: existingSlas[p]?.resolve_hours ?? undefined },
        ]),
    ) as Record<string, SlaInput>,
    attachments: [] as File[],
});

const submit = () => {
    // An empty (or emptied: '') number input is sent as null, so the row counts as "no SLA".
    const hoursOrNull = (value: number | string | undefined) => (value === undefined || value === '' ? null : value);
    const transformed = form.transform((data) => ({
        ...data,
        slas: Object.fromEntries(
            Object.entries(data.slas).map(([p, sla]) => [
                p,
                { response_hours: hoursOrNull(sla.response_hours), resolve_hours: hoursOrNull(sla.resolve_hours) },
            ]),
        ),
        // Files go as multipart, which PHP only reads on POST: an edit is a POST that says PUT.
        ...(props.contract ? { _method: 'put' } : {}),
    }));

    if (props.contract) {
        transformed.post(route('contract.contracts.update', props.contract.id), { preserveScroll: true });
    } else {
        transformed.post(route('contract.contracts.store'), { preserveScroll: true });
    }
};

const error = (key: string) => (form.errors as Record<string, string>)[key];

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-8" @submit.prevent="submit">
                <section class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('contracts.general') }}</h3>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid content-start gap-2 sm:col-span-2">
                            <Label for="customer_id" required>{{ t('contracts.customer') }}</Label>
                            <select id="customer_id" v-model="form.customer_id" :class="selectClass" required>
                                <option :value="null" disabled>{{ t('contracts.choose_customer') }}</option>
                                <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                    {{ customer.name }} ({{ customer.code }})
                                </option>
                            </select>
                            <InputError :message="form.errors.customer_id" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="contract_no" required>{{ t('contracts.contract_no') }}</Label>
                            <Input id="contract_no" v-model="form.contract_no" class="font-mono" required autocomplete="off" />
                            <InputError :message="form.errors.contract_no" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="status" required>{{ t('contracts.status') }}</Label>
                            <select id="status" v-model="form.status" :class="selectClass" required>
                                <option v-for="status in statuses" :key="status" :value="status">{{ t(`contracts.statuses.${status}`) }}</option>
                            </select>
                            <InputError :message="form.errors.status" />
                        </div>

                        <div class="grid content-start gap-2 sm:col-span-2">
                            <Label for="title" required>{{ t('contracts.title_field') }}</Label>
                            <Input id="title" v-model="form.title" required />
                            <InputError :message="form.errors.title" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="starts_on" required>{{ t('contracts.starts_on') }}</Label>
                            <Input id="starts_on" v-model="form.starts_on" type="date" required />
                            <InputError :message="form.errors.starts_on" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="ends_on" required>{{ t('contracts.ends_on') }}</Label>
                            <Input id="ends_on" v-model="form.ends_on" type="date" required />
                            <InputError :message="form.errors.ends_on" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="value">{{ t('contracts.value') }}</Label>
                            <Input id="value" v-model="form.value" type="number" min="0" step="0.01" />
                            <InputError :message="form.errors.value" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="service_window" required>{{ t('contracts.service_window') }}</Label>
                            <select id="service_window" v-model="form.service_window" :class="selectClass" required>
                                <option v-for="window in serviceWindows" :key="window" :value="window">
                                    {{ t(`contracts.service_windows.${window}`) }}
                                </option>
                            </select>
                            <InputError :message="form.errors.service_window" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="pm_interval_months">{{ t('contracts.pm_interval_months') }}</Label>
                            <select id="pm_interval_months" v-model="form.pm_interval_months" :class="selectClass">
                                <option :value="null">{{ t('contracts.no_pm') }}</option>
                                <option v-for="months in pmIntervals" :key="months" :value="months">
                                    {{ t('contracts.every_months', { months }) }}
                                </option>
                            </select>
                            <InputError :message="form.errors.pm_interval_months" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="notify_days_before" required>{{ t('contracts.notify_days_before') }}</Label>
                            <Input id="notify_days_before" v-model="form.notify_days_before" type="number" min="0" max="365" required />
                            <p class="text-xs text-muted-foreground">{{ t('contracts.notify_hint') }}</p>
                            <InputError :message="form.errors.notify_days_before" />
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('contracts.sla') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ t('contracts.sla_hint') }}</p>
                    </div>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ t('contracts.priority') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ t('contracts.response_hours') }}</th>
                                    <th class="px-4 py-2 font-medium">{{ t('contracts.resolve_hours') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="priority in priorities" :key="priority" class="border-t align-top">
                                    <td class="px-4 py-2 font-medium">{{ t(`contracts.priorities.${priority}`) }}</td>
                                    <td class="px-4 py-2">
                                        <Input
                                            v-model="form.slas[priority].response_hours"
                                            type="number"
                                            min="0.25"
                                            step="0.25"
                                            :aria-label="`${t('contracts.response_hours')} ${t(`contracts.priorities.${priority}`)}`"
                                        />
                                        <InputError :message="error(`slas.${priority}.response_hours`)" />
                                    </td>
                                    <td class="px-4 py-2">
                                        <Input
                                            v-model="form.slas[priority].resolve_hours"
                                            type="number"
                                            min="0.25"
                                            step="0.25"
                                            :aria-label="`${t('contracts.resolve_hours')} ${t(`contracts.priorities.${priority}`)}`"
                                        />
                                        <InputError :message="error(`slas.${priority}.resolve_hours`)" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="grid content-start gap-2 sm:max-w-md">
                    <Label for="require_signature">{{ t('contracts.require_signature') }}</Label>
                    <select id="require_signature" v-model="form.require_signature" :class="selectClass">
                        <option :value="null">{{ t('contracts.require_signature_inherit') }}</option>
                        <option :value="true">{{ t('contracts.require_signature_yes') }}</option>
                        <option :value="false">{{ t('contracts.require_signature_no') }}</option>
                    </select>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="notes">{{ t('contracts.notes') }}</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.notes" />
                </div>

                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('contracts.documents') }}</h3>
                    <AttachmentList v-if="contract" :attachments="documents" :title="false" can-delete />
                    <AttachmentPicker v-model="form.attachments" images :errors="form.errors" />
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="contract ? route('contract.contracts.show', contract.id) : route('contract.contracts.index')">
                            {{ t('common.cancel') }}
                        </Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
