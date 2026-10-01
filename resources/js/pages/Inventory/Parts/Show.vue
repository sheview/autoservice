<script setup lang="ts">
import AssetCheckoutPanel from '@/components/AssetCheckoutPanel.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import PhotoSlots from '@/components/PhotoSlots.vue';
import StockMovementTypeBadge from '@/components/StockMovementTypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

interface PartDetail {
    id: number;
    code: string;
    name: string;
    part_number: string | null;
    brand: string | null;
    unit: string;
    min_qty: number;
    qty_on_hand: number;
    unit_cost: string | null;
    is_active: boolean;
    notes: string | null;
    low: boolean;
    contract: { id: number; contract_no: string; title: string } | null;
}

interface MovementRow {
    id: number;
    type: string;
    quantity: number;
    balance_after: number;
    unit_cost: string | null;
    reference: string | null;
    note: string | null;
    user_name: string | null;
    ticket: { ulid: string; ticket_no: string; status: string } | null;
    at: string;
}

const props = defineProps<{
    part: PartDetail;
    movements: Paginated<MovementRow> | null;
    // Issue/loan of this part (same panel as an asset's); null when the user does not handle them.
    checkouts: InstanceType<typeof AssetCheckoutPanel>['$props']['checkouts'] | null;
    photos: { slot: number; action: string; url: string | null }[];
    movementTypes: string[];
    can: { update: boolean; delete: boolean; viewTickets: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('parts.title'), href: route('inventory.parts.index') },
    { title: props.part.code, href: route('inventory.parts.show', props.part.id) },
];

const form = useForm({
    type: props.movementTypes[0] ?? '',
    quantity: '' as number | string,
    unit_cost: '',
    reference: '',
    note: '',
});

const submit = () =>
    form.post(route('inventory.parts.movements.store', props.part.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('quantity', 'unit_cost', 'reference', 'note'),
    });

const destroy = () => {
    if (confirm(t('common.confirm_delete', { name: props.part.name }))) {
        router.delete(route('inventory.parts.destroy', props.part.id));
    }
};

const money = (baht: string | null) => (baht === null ? t('common.none') : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
const signed = (quantity: number) => (quantity > 0 ? '+' : '') + quantity.toLocaleString('th-TH');

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${part.code} ${part.name}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-muted-foreground">{{ part.code }}</p>
                    <Heading :title="part.name" :description="[part.brand, part.part_number].filter(Boolean).join(' · ')" />
                </div>
                <div class="flex gap-2">
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('inventory.parts.edit', part.id)">{{ t('common.edit') }}</Link>
                    </Button>
                    <Button v-if="can.delete && part.qty_on_hand === 0" variant="outline" @click="destroy">{{ t('common.delete') }}</Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).part" />

            <PhotoSlots :photos="photos" :editable="can.update" />

            <AssetCheckoutPanel
                v-if="checkouts"
                :asset-ulid="String(part.id)"
                :checkouts="checkouts"
                :store-url="route('inventory.part-checkouts.store', part.id)"
                :request-title="t('part_checkouts.request_title')"
            />

            <div class="grid gap-6 lg:grid-cols-3">
                <dl class="space-y-3 rounded-md border p-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('parts.qty_on_hand') }}</dt>
                        <dd class="text-2xl font-semibold">
                            {{ part.qty_on_hand.toLocaleString('th-TH') }}
                            <span class="text-sm font-normal text-muted-foreground">{{ part.unit }}</span>
                            <span
                                v-if="part.qty_on_hand === 0"
                                class="ml-2 rounded bg-red-100 px-1 align-middle text-xs font-normal text-red-800 dark:bg-red-950 dark:text-red-200"
                            >
                                {{ t('parts.out') }}
                            </span>
                            <span
                                v-else-if="part.low"
                                class="ml-2 rounded bg-amber-100 px-1 align-middle text-xs font-normal text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                            >
                                {{ t('parts.low') }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('parts.min_qty') }}</dt>
                        <dd>{{ part.min_qty > 0 ? `${part.min_qty.toLocaleString('th-TH')} ${part.unit}` : t('common.none') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('parts.unit_cost') }}</dt>
                        <dd>{{ money(part.unit_cost) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('common.status') }}</dt>
                        <dd>{{ t(`parts.statuses.${part.is_active ? 'active' : 'inactive'}`) }}</dd>
                    </div>
                    <div v-if="part.contract">
                        <dt class="text-xs text-muted-foreground">{{ t('parts.contract') }}</dt>
                        <dd>{{ part.contract.contract_no }} · {{ part.contract.title }}</dd>
                    </div>
                    <div v-if="part.notes">
                        <dt class="text-xs text-muted-foreground">{{ t('parts.notes') }}</dt>
                        <dd class="whitespace-pre-line">{{ part.notes }}</dd>
                    </div>
                </dl>

                <form v-if="movementTypes.length" class="space-y-4 rounded-md border p-4 lg:col-span-2" @submit.prevent="submit">
                    <h3 class="text-sm font-semibold">{{ t('stock_movements.new') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid content-start gap-2">
                            <Label for="type">{{ t('stock_movements.type') }}</Label>
                            <select id="type" v-model="form.type" :class="selectClass">
                                <option v-for="type in movementTypes" :key="type" :value="type">{{ t(`stock_movements.types.${type}`) }}</option>
                            </select>
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="quantity">
                                {{ form.type === 'adjust' ? t('stock_movements.counted_field') : t('stock_movements.quantity_field') }}
                            </Label>
                            <Input id="quantity" v-model="form.quantity" type="number" :min="form.type === 'adjust' ? 0 : 1" step="1" required />
                        </div>
                        <div v-if="form.type === 'receive'" class="grid content-start gap-2">
                            <Label for="movement_unit_cost">{{ t('stock_movements.unit_cost') }}</Label>
                            <Input id="movement_unit_cost" v-model="form.unit_cost" type="number" min="0" step="0.01" />
                        </div>
                    </div>
                    <InputError :message="form.errors.quantity ?? form.errors.unit_cost ?? form.errors.type" />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid content-start gap-2">
                            <Label for="reference">{{ t('stock_movements.reference_field') }}</Label>
                            <Input
                                id="reference"
                                v-model="form.reference"
                                maxlength="100"
                                :placeholder="t('stock_movements.reference_placeholder')"
                            />
                            <InputError :message="form.errors.reference" />
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-2">
                            <Label for="note">{{ t('stock_movements.note') }}</Label>
                            <Input
                                id="note"
                                v-model="form.note"
                                maxlength="1000"
                                :required="form.type === 'adjust'"
                                :placeholder="form.type === 'adjust' ? t('stock_movements.adjust_note_hint') : ''"
                            />
                            <InputError :message="form.errors.note" />
                        </div>
                    </div>

                    <Button :disabled="form.processing">{{ t('stock_movements.submit') }}</Button>
                </form>
            </div>

            <section v-if="movements" class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold">{{ t('stock_movements.history') }}</h3>
                    <Link
                        :href="route('inventory.movements.index', { part_id: part.id })"
                        class="text-sm text-primary underline-offset-4 hover:underline"
                    >
                        {{ t('parts.view_all_movements') }}
                    </Link>
                </div>

                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('stock_movements.created_at') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('stock_movements.type') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('stock_movements.quantity') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('stock_movements.balance_after') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('stock_movements.reference') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('stock_movements.user') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="movement in movements.data" :key="movement.id" class="border-t align-top">
                                <td class="whitespace-nowrap px-4 py-2">{{ dateTime(movement.at) }}</td>
                                <td class="px-4 py-2"><StockMovementTypeBadge :type="movement.type" /></td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-medium">{{ signed(movement.quantity) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ movement.balance_after.toLocaleString('th-TH') }}</td>
                                <td class="px-4 py-2">
                                    <template v-if="movement.ticket">
                                        <Link
                                            v-if="can.viewTickets"
                                            :href="route('service.tickets.show', movement.ticket.ulid)"
                                            class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                        >
                                            {{ movement.ticket.ticket_no }}
                                        </Link>
                                        <span v-else class="font-mono text-xs">{{ movement.ticket.ticket_no }}</span>
                                    </template>
                                    <span v-if="movement.reference">{{ movement.reference }}</span>
                                    <div v-if="movement.note" class="text-xs text-muted-foreground">{{ movement.note }}</div>
                                </td>
                                <td class="px-4 py-2">{{ movement.user_name ?? t('common.system') }}</td>
                            </tr>
                            <tr v-if="movements.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ t('stock_movements.none') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :paginator="movements" />
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('inventory.parts.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
