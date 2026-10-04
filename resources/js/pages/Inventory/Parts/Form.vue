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

interface PartForm {
    id: number;
    code: string;
    name: string;
    contract_id: number | null;
    part_number: string | null;
    brand: string | null;
    unit: string;
    min_qty: number;
    unit_cost: string | null;
    is_active: boolean;
    notes: string | null;
}

// contracts: the MA contracts (projects) a part can be kept for; none = the field is hidden.
const props = defineProps<{ part: PartForm | null; contracts: { id: number; label: string }[] }>();

const title = props.part ? t('parts.edit') : t('parts.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('parts.title'), href: route('inventory.parts.index') },
    { title, href: props.part ? route('inventory.parts.edit', props.part.id) : route('inventory.parts.create') },
];

const form = useForm({
    code: props.part?.code ?? '',
    name: props.part?.name ?? '',
    contract_id: props.part?.contract_id ?? (null as number | null),
    part_number: props.part?.part_number ?? '',
    brand: props.part?.brand ?? '',
    unit: props.part?.unit ?? '',
    min_qty: props.part?.min_qty ?? 0,
    unit_cost: props.part?.unit_cost ?? '',
    is_active: props.part?.is_active ?? true,
    notes: props.part?.notes ?? '',
});

const submit = () => {
    if (props.part) {
        form.put(route('inventory.parts.update', props.part.id));
    } else {
        form.post(route('inventory.parts.store'));
    }
};

const textareaClass = 'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="code" required>{{ t('parts.code') }}</Label>
                        <Input id="code" v-model="form.code" class="font-mono uppercase" required maxlength="30" autocomplete="off" />
                        <p class="text-xs text-muted-foreground">{{ t('parts.code_hint') }}</p>
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="name" required>{{ t('parts.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div v-if="contracts.length" class="grid content-start gap-2 sm:col-span-3">
                        <Label for="contract_id">{{ t('parts.contract') }}</Label>
                        <select
                            id="contract_id"
                            v-model="form.contract_id"
                            class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null">{{ t('parts.no_contract') }}</option>
                            <option v-for="contract in contracts" :key="contract.id" :value="contract.id">{{ contract.label }}</option>
                        </select>
                        <p class="text-xs text-muted-foreground">{{ t('parts.contract_hint') }}</p>
                        <InputError :message="form.errors.contract_id" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="brand">{{ t('parts.brand') }}</Label>
                        <Input id="brand" v-model="form.brand" maxlength="100" />
                        <InputError :message="form.errors.brand" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="part_number">{{ t('parts.part_number') }}</Label>
                        <Input id="part_number" v-model="form.part_number" class="font-mono" maxlength="100" />
                        <InputError :message="form.errors.part_number" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="unit" required>{{ t('parts.unit') }}</Label>
                        <Input id="unit" v-model="form.unit" required maxlength="30" :placeholder="t('parts.unit_placeholder')" />
                        <InputError :message="form.errors.unit" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="min_qty">{{ t('parts.min_qty') }}</Label>
                        <Input id="min_qty" v-model="form.min_qty" type="number" min="0" step="1" />
                        <InputError :message="form.errors.min_qty" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="unit_cost">{{ t('parts.unit_cost') }}</Label>
                        <Input id="unit_cost" v-model="form.unit_cost" type="number" min="0" step="0.01" />
                        <InputError :message="form.errors.unit_cost" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('parts.min_qty_hint') }} · {{ t('parts.unit_cost_hint') }}</p>

                <div class="grid content-start gap-2">
                    <Label for="notes">{{ t('parts.notes') }}</Label>
                    <textarea id="notes" v-model="form.notes" rows="3" :class="textareaClass" />
                    <InputError :message="form.errors.notes" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                    {{ t('parts.is_active') }}
                </label>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="part ? route('inventory.parts.show', part.id) : route('inventory.parts.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
