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
import { computed } from 'vue';

interface SpecField {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'select';
    options: string[];
    required: boolean;
}

interface Category {
    id: number;
    name: string;
    code_prefix: string;
    spec_fields: SpecField[];
}

interface AssetForm {
    ulid: string;
    asset_code: string;
    name: string;
    category_id: number;
    branch_id: number | null;
    customer_id: number | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    status: string;
    location: string | null;
    purchased_at: string | null;
    purchase_price: string | null;
    warranty_expires_at: string | null;
    notes: string | null;
    specs: Record<string, string | number> | unknown[];
}

const props = defineProps<{
    asset: AssetForm | null;
    categories: Category[];
    branches: { id: number; name: string }[];
    customers: { id: number; code: string; name: string }[];
    statuses: string[];
}>();

const title = props.asset ? t('assets.edit') : t('assets.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('assets.title'), href: route('asset.assets.index') },
    ...(props.asset ? [{ title: props.asset.asset_code, href: route('asset.assets.show', props.asset.ulid) }] : []),
    { title, href: props.asset ? route('asset.assets.edit', props.asset.ulid) : route('asset.assets.create') },
];

// An empty JSON object arrives as [] from PHP.
const initialSpecs = props.asset && !Array.isArray(props.asset.specs) ? props.asset.specs : {};

const form = useForm({
    category_id: props.asset?.category_id ?? null,
    asset_code: props.asset?.asset_code ?? '',
    name: props.asset?.name ?? '',
    branch_id: props.asset?.branch_id ?? (props.branches.length === 1 ? props.branches[0].id : null),
    customer_id: props.asset?.customer_id ?? null,
    brand: props.asset?.brand ?? '',
    model: props.asset?.model ?? '',
    serial_number: props.asset?.serial_number ?? '',
    status: props.asset?.status ?? 'in_use',
    location: props.asset?.location ?? '',
    purchased_at: props.asset?.purchased_at ?? '',
    purchase_price: props.asset?.purchase_price ?? '',
    warranty_expires_at: props.asset?.warranty_expires_at ?? '',
    notes: props.asset?.notes ?? '',
    specs: { ...initialSpecs } as Record<string, string | number>,
});

const category = computed(() => props.categories.find((c) => c.id === form.category_id) ?? null);

const submit = () => {
    const options = { preserveScroll: true };
    if (props.asset) {
        form.put(route('asset.assets.update', props.asset.ulid), options);
    } else {
        form.post(route('asset.assets.store'), options);
    }
};

const specError = (key: string) => (form.errors as Record<string, string>)[`specs.${key}`];

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="max-w-3xl p-4">
            <Heading :title="title" />

            <form class="space-y-8" @submit.prevent="submit">
                <section class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('assets.general') }}</h3>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="category_id">{{ t('assets.category') }}</Label>
                            <select id="category_id" v-model="form.category_id" :class="selectClass" required>
                                <option :value="null" disabled>{{ t('assets.choose_category') }}</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code_prefix }})</option>
                            </select>
                            <InputError :message="form.errors.category_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="asset_code">{{ t('assets.asset_code') }}</Label>
                            <Input
                                id="asset_code"
                                v-model="form.asset_code"
                                class="font-mono"
                                :placeholder="category ? `${category.code_prefix}-00001` : ''"
                                autocomplete="off"
                            />
                            <p class="text-xs text-muted-foreground">{{ t('assets.asset_code_hint') }}</p>
                            <InputError :message="form.errors.asset_code" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="name">{{ t('assets.name') }}</Label>
                            <Input id="name" v-model="form.name" required autocomplete="off" />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="brand">{{ t('assets.brand') }}</Label>
                            <Input id="brand" v-model="form.brand" />
                            <InputError :message="form.errors.brand" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="model">{{ t('assets.model') }}</Label>
                            <Input id="model" v-model="form.model" />
                            <InputError :message="form.errors.model" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="serial_number">{{ t('assets.serial_number') }}</Label>
                            <Input id="serial_number" v-model="form.serial_number" class="font-mono" autocomplete="off" />
                            <InputError :message="form.errors.serial_number" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="status">{{ t('assets.status') }}</Label>
                            <select id="status" v-model="form.status" :class="selectClass" required>
                                <option v-for="status in statuses" :key="status" :value="status">{{ t(`assets.statuses.${status}`) }}</option>
                            </select>
                            <InputError :message="form.errors.status" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="branch_id">{{ t('assets.branch') }}</Label>
                            <select id="branch_id" v-model="form.branch_id" :class="selectClass">
                                <option :value="null">{{ t('assets.no_branch') }}</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                            <InputError :message="form.errors.branch_id" />
                        </div>

                        <div v-if="customers.length" class="grid gap-2">
                            <Label for="customer_id">{{ t('assets.customer') }}</Label>
                            <select id="customer_id" v-model="form.customer_id" :class="selectClass">
                                <option :value="null">{{ t('assets.no_customer') }}</option>
                                <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                    {{ customer.name }} ({{ customer.code }})
                                </option>
                            </select>
                            <InputError :message="form.errors.customer_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="location">{{ t('assets.location') }}</Label>
                            <Input id="location" v-model="form.location" />
                            <InputError :message="form.errors.location" />
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('assets.specs') }}</h3>
                    <p v-if="!category || category.spec_fields.length === 0" class="text-sm text-muted-foreground">
                        {{ category ? t('assets.no_specs') : t('assets.choose_category') }}
                    </p>
                    <div v-else class="grid gap-6 sm:grid-cols-2">
                        <div v-for="field in category.spec_fields" :key="field.key" class="grid gap-2">
                            <Label :for="`spec_${field.key}`"> {{ field.label }}<span v-if="field.required" class="text-red-600"> *</span> </Label>
                            <select
                                v-if="field.type === 'select'"
                                :id="`spec_${field.key}`"
                                v-model="form.specs[field.key]"
                                :class="selectClass"
                                :required="field.required"
                            >
                                <option :value="undefined">{{ t('common.none') }}</option>
                                <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
                            </select>
                            <Input
                                v-else
                                :id="`spec_${field.key}`"
                                v-model="form.specs[field.key]"
                                :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                                :step="field.type === 'number' ? 'any' : undefined"
                                :required="field.required"
                            />
                            <InputError :message="specError(field.key)" />
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('assets.purchase') }}</h3>
                    <div class="grid gap-6 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="purchased_at">{{ t('assets.purchased_at') }}</Label>
                            <Input id="purchased_at" v-model="form.purchased_at" type="date" />
                            <InputError :message="form.errors.purchased_at" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="purchase_price">{{ t('assets.purchase_price') }}</Label>
                            <Input id="purchase_price" v-model="form.purchase_price" type="number" min="0" step="0.01" />
                            <InputError :message="form.errors.purchase_price" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="warranty_expires_at">{{ t('assets.warranty_expires_at') }}</Label>
                            <Input id="warranty_expires_at" v-model="form.warranty_expires_at" type="date" />
                            <InputError :message="form.errors.warranty_expires_at" />
                        </div>
                    </div>
                </section>

                <div class="grid gap-2">
                    <Label for="notes">{{ t('assets.notes') }}</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.notes" />
                </div>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="asset ? route('asset.assets.show', asset.ulid) : route('asset.assets.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
