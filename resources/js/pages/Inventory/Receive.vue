<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SerialInputs from '@/components/SerialInputs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface PartOption {
    id: number;
    code: string;
    name: string;
    unit: string;
    brand: string | null;
    part_number: string | null;
    track_serial: boolean;
    qty_on_hand: number;
    unit_cost: string | null;
}

/**
 * Goods received straight into stock (no purchase request): a quantity of a part, or one serial
 * per unit for a part followed by serial number.
 */
const props = defineProps<{ part: PartOption | null }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('parts.title'), href: route('inventory.parts.index') },
    { title: t('stock_receipts.title'), href: route('inventory.stock-receipts.create') },
];

const chosen = ref<PartOption | null>(props.part);
const q = ref('');
const options = ref<PartOption[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
const search = async () => {
    const response = await fetch(route('inventory.stock-receipts.parts', { q: q.value.trim() || undefined }), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    options.value = response.ok ? await response.json() : [];
};
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
});

const form = useForm({
    part_id: props.part?.id ?? (null as number | null),
    quantity: 1 as number | string,
    serials: [] as string[],
    unit_cost: props.part?.unit_cost ?? '',
    supplier: '',
    reference: '',
    warranty_until: '',
    received_on: new Date().toLocaleDateString('sv-SE'),
    note: '',
});
const choose = (part: PartOption) => {
    chosen.value = part;
    form.part_id = part.id;
    form.unit_cost = part.unit_cost ?? '';
    form.serials = [];
    q.value = '';
    options.value = [];
};
const submit = () =>
    form
        .transform((data) => ({ ...data, serials: chosen.value?.track_serial ? data.serials : [] }))
        .post(route('inventory.stock-receipts.store'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('stock_receipts.title')" />

        <form class="max-w-4xl space-y-6 p-4" @submit.prevent="submit">
            <Heading :title="t('stock_receipts.title')" :description="t('stock_receipts.description')" />

            <section class="space-y-2">
                <Label for="part_search" required>{{ t('stock_receipts.part') }}</Label>
                <div v-if="chosen" class="flex flex-wrap items-center gap-3 rounded-md border p-3">
                    <div>
                        <div class="font-mono text-xs text-muted-foreground">{{ chosen.code }}</div>
                        <div class="font-medium">
                            {{ chosen.name }}
                            <span v-if="chosen.track_serial" class="ml-1 rounded bg-primary/10 px-1.5 py-0.5 font-mono text-xs text-primary">{{
                                t('parts.sn_badge')
                            }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ [chosen.brand, chosen.part_number].filter(Boolean).join(' · ') }}
                            {{ t('stock_receipts.on_hand', { qty: chosen.qty_on_hand, unit: chosen.unit }) }}
                        </div>
                    </div>
                    <Button type="button" variant="ghost" size="sm" class="ml-auto" @click="chosen = null">{{ t('stock_receipts.change') }}</Button>
                </div>
                <template v-else>
                    <Input id="part_search" v-model="q" :placeholder="t('stock_receipts.search')" autocomplete="off" @focus="search" />
                    <ul v-if="options.length" class="max-h-64 overflow-y-auto rounded-md border">
                        <li v-for="option in options" :key="option.id">
                            <button type="button" class="w-full border-b px-3 py-2 text-left text-sm last:border-b-0 hover:bg-muted/50" @click="choose(option)">
                                <span class="font-mono text-xs">{{ option.code }}</span> · {{ option.name }}
                                <span v-if="option.track_serial" class="ml-1 font-mono text-xs text-primary">{{ t('parts.sn_badge') }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
                <InputError :message="form.errors.part_id" />
            </section>

            <template v-if="chosen">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="quantity" required>{{ t('stock_receipts.quantity', { unit: chosen.unit }) }}</Label>
                        <Input id="quantity" v-model="form.quantity" type="number" min="1" max="5000" step="1" required />
                        <InputError :message="form.errors.quantity" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="unit_cost">{{ t('stock_movements.unit_cost') }}</Label>
                        <Input id="unit_cost" v-model="form.unit_cost" type="number" min="0" step="0.01" />
                        <InputError :message="form.errors.unit_cost" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="received_on">{{ t('part_units.received_on') }}</Label>
                        <Input id="received_on" v-model="form.received_on" type="date" />
                        <InputError :message="form.errors.received_on" />
                    </div>
                </div>

                <div v-if="chosen.track_serial" class="space-y-1">
                    <SerialInputs v-model="form.serials" :count="Number(form.quantity) || 0" />
                    <InputError :message="form.errors.serials" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="supplier">{{ t('part_units.supplier') }}</Label>
                        <Input id="supplier" v-model="form.supplier" maxlength="255" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="reference">{{ t('stock_movements.reference_field') }}</Label>
                        <Input id="reference" v-model="form.reference" maxlength="100" :placeholder="t('stock_movements.reference_placeholder')" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="warranty_until">{{ t('part_units.warranty_until') }}</Label>
                        <Input id="warranty_until" v-model="form.warranty_until" type="date" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="note">{{ t('stock_movements.note') }}</Label>
                    <Input id="note" v-model="form.note" maxlength="1000" />
                </div>
            </template>

            <div class="flex items-center gap-3">
                <Button :disabled="form.processing || !chosen">{{ t('stock_receipts.submit') }}</Button>
                <Button variant="outline" as-child>
                    <Link :href="route('inventory.parts.index')">{{ t('common.cancel') }}</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
