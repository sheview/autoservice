<script setup lang="ts">
import AssetStatusBadge from '@/components/AssetStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SharedRequestList, { type SharedRequestRow } from '@/components/SharedRequestList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Row = Record<string, string | number | null>;
type TicketOption = { id: number; ticket_no: string; title: string };

interface CartLine {
    item_type: 'part' | 'asset';
    id: number;
    code: string;
    name: string;
    unit: string | null;
    max: number;
    qty: number;
    checkout_type: 'issue' | 'loan';
    due_return_date: string;
}

const props = defineProps<{
    filters: { kind: string; company: number | null; search: string; ticket: number | null; [key: string]: unknown };
    kinds: string[];
    companies: { id: number; name: string }[];
    results: { company_id: number; company: string; rows: Row[] }[];
    requestable: number[];
    myRequests: SharedRequestRow[];
    presetTicket: TicketOption | null;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('shares.search_title'), href: route('platform.shared-search') }];
const filters = useListFilters('platform.shared-search', { ...props.filters });
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// The request being put together: lines of one company only, sent as one request there.
const form = useForm({
    company: null as number | null,
    ticket_id: props.presetTicket?.id ?? (null as number | null),
    purpose: '',
    needed_by: '',
    borrower_phone: '',
});
const cart = ref<CartLine[]>([]);
const cartCompany = computed(() => props.companies.find((c) => c.id === form.company)?.name ?? '');
const otherCompany = ref(false);

const inCart = (type: string, id: number) => cart.value.some((l) => l.item_type === type && l.id === id);
const add = (companyId: number, row: Row) => {
    if (form.company !== null && form.company !== companyId) {
        otherCompany.value = true;
        return;
    }
    otherCompany.value = false;
    form.company = companyId;
    const part = props.filters.kind === 'parts';
    cart.value.push({
        item_type: part ? 'part' : 'asset',
        id: Number(row.id),
        code: String(part ? row.code : row.asset_code),
        name: String(row.name),
        unit: (row.unit as string | null) ?? null,
        max: Number(part ? row.qty_on_hand : row.available),
        qty: 1,
        checkout_type: 'issue',
        due_return_date: '',
    });
};
const remove = (index: number) => {
    cart.value.splice(index, 1);
    if (!cart.value.length) form.company = null;
};
const clear = () => {
    form.reset();
    cart.value = [];
    otherCompany.value = false;
};
const send = () =>
    form
        .transform((data) => ({
            ...data,
            items: cart.value.map(({ item_type, id, qty, checkout_type, due_return_date }) => ({
                item_type,
                id,
                qty,
                checkout_type,
                due_return_date: due_return_date || null,
            })),
        }))
        .post(route('platform.shared-search.request'), { preserveScroll: true, onSuccess: () => clear() });

// Our ticket the request is for.
const ticket = ref<TicketOption | null>(props.presetTicket);
const ticketSearch = ref('');
const ticketOptions = ref<TicketOption[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(ticketSearch, (q) => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        ticketOptions.value = q
            ? await (await fetch(route('asset.requests.tickets', { q }), { headers: { Accept: 'application/json' } })).json()
            : [];
    }, 300);
});
const pickTicket = (option: TicketOption | null) => {
    ticket.value = option;
    form.ticket_id = option?.id ?? null;
    ticketSearch.value = '';
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('shares.search_title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('shares.search_title')" :description="t('shares.search_description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <p v-if="!companies.length" class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                {{ t('shares.no_companies') }}
            </p>

            <template v-else>
                <!-- The request being put together -->
                <form v-if="cart.length" class="space-y-4 rounded-lg border border-primary/40 p-4" @submit.prevent="send">
                    <div>
                        <h3 class="font-semibold">{{ t('shares.cart_title', { company: cartCompany }) }}</h3>
                        <p class="text-sm text-muted-foreground">{{ t('shares.cart_hint', { company: cartCompany }) }}</p>
                    </div>
                    <ul class="divide-y rounded-md border text-sm">
                        <li v-for="(line, i) in cart" :key="`${line.item_type}-${line.id}`" class="flex flex-wrap items-center gap-3 p-2">
                            <span class="min-w-40 flex-1">
                                <span class="font-mono text-xs">{{ line.code }}</span> {{ line.name }}
                                <span class="block text-xs text-muted-foreground"
                                    >{{ t('shares.available', { qty: line.max }) }} {{ line.unit ?? '' }}</span
                                >
                            </span>
                            <select v-if="line.item_type === 'asset'" v-model="line.checkout_type" :class="selectClass">
                                <option value="issue">{{ t('shares.issue') }}</option>
                                <option value="loan">{{ t('shares.loan') }}</option>
                            </select>
                            <Input
                                v-if="line.checkout_type === 'loan'"
                                v-model="line.due_return_date"
                                type="date"
                                class="w-40"
                                :aria-label="t('shares.due')"
                                required
                            />
                            <Input v-model.number="line.qty" type="number" min="1" class="w-24" :aria-label="t('shares.qty')" />
                            <button type="button" class="text-xs text-red-600 hover:underline" @click="remove(i)">{{ t('shares.remove') }}</button>
                            <InputError class="w-full" :message="(form.errors as Record<string, string>)[`items.${i}`]" />
                        </li>
                    </ul>
                    <InputError :message="(form.errors as Record<string, string>).items" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="ticket_search">{{ t('shares.ticket') }}</Label>
                            <div v-if="ticket" class="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                                <span
                                    ><span class="font-mono">{{ ticket.ticket_no }}</span> {{ ticket.title }}</span
                                >
                                <button type="button" class="text-xs text-primary hover:underline" @click="pickTicket(null)">
                                    {{ t('shares.remove') }}
                                </button>
                            </div>
                            <template v-else>
                                <Input
                                    id="ticket_search"
                                    v-model="ticketSearch"
                                    type="search"
                                    autocomplete="off"
                                    :placeholder="t('shares.ticket_search')"
                                />
                                <ul v-if="ticketOptions.length" class="max-h-48 divide-y overflow-y-auto rounded-md border text-sm">
                                    <li v-for="option in ticketOptions" :key="option.id">
                                        <button type="button" class="w-full px-3 py-1.5 text-left hover:bg-muted" @click="pickTicket(option)">
                                            <span class="font-mono text-xs">{{ option.ticket_no }}</span> {{ option.title }}
                                        </button>
                                    </li>
                                </ul>
                                <p class="text-xs text-muted-foreground">{{ t('shares.no_ticket') }}</p>
                            </template>
                            <InputError :message="form.errors.ticket_id" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="purpose">{{ t('shares.purpose') }}</Label>
                            <Input id="purpose" v-model="form.purpose" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="needed_by">{{ t('shares.needed_by') }}</Label>
                            <Input id="needed_by" v-model="form.needed_by" type="date" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="phone">{{ t('shares.phone') }}</Label>
                            <Input id="phone" v-model="form.borrower_phone" type="tel" />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button :disabled="form.processing">{{ t('shares.send') }}</Button>
                        <Button type="button" variant="ghost" @click="clear">{{ t('shares.clear') }}</Button>
                    </div>
                </form>
                <p v-if="otherCompany" class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    {{ t('shares.cart_other_company') }}
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <select v-if="kinds.length > 1" v-model="filters.kind" :class="selectClass">
                        <option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`shares.kinds.${kind}`) }}</option>
                    </select>
                    <select v-model="filters.company" :class="selectClass">
                        <option :value="null">{{ t('shares.all_companies') }}</option>
                        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <Input v-model="filters.search" type="search" class="max-w-sm flex-1" :placeholder="t('shares.search_placeholder')" />
                </div>

                <p v-if="!filters.search && !filters.company" class="text-sm text-muted-foreground">{{ t('shares.type_to_search') }}</p>
                <p v-else-if="!results.length" class="text-sm text-muted-foreground">{{ t('shares.no_results') }}</p>

                <section v-for="group in results" :key="group.company" class="space-y-2">
                    <h3 class="font-semibold">{{ group.company }}</h3>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr v-if="filters.kind === 'parts'">
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.code') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.name') }}</th>
                                    <th class="hidden px-3 py-2 font-semibold sm:table-cell">{{ t('shares.brand') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('shares.in_stock') }}</th>
                                    <th v-if="requestable.includes(group.company_id)" class="px-3 py-2" />
                                </tr>
                                <tr v-else>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.code') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.name') }}</th>
                                    <th class="hidden px-3 py-2 font-semibold sm:table-cell">{{ t('shares.serial') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('shares.status') }}</th>
                                    <th class="hidden px-3 py-2 font-semibold md:table-cell">{{ t('shares.location') }}</th>
                                    <th v-if="requestable.includes(group.company_id)" class="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <template v-if="filters.kind === 'parts'">
                                    <tr v-for="row in group.rows" :key="String(row.code)" class="border-t">
                                        <td class="px-3 py-2 font-mono text-xs">{{ row.code }}</td>
                                        <td class="px-3 py-2">
                                            {{ row.name }}
                                            <span v-if="row.part_number" class="ml-1 text-xs text-muted-foreground">{{ row.part_number }}</span>
                                        </td>
                                        <td class="hidden px-3 py-2 sm:table-cell">{{ row.brand ?? '' }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ row.qty_on_hand }} {{ row.unit ?? '' }}</td>
                                        <td v-if="requestable.includes(group.company_id)" class="px-3 py-2 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                :disabled="inCart('part', Number(row.id)) || !Number(row.qty_on_hand)"
                                                @click="add(group.company_id, row)"
                                            >
                                                {{ t('shares.ask') }}
                                            </Button>
                                        </td>
                                    </tr>
                                </template>
                                <template v-else>
                                    <tr v-for="row in group.rows" :key="String(row.asset_code)" class="border-t">
                                        <td class="px-3 py-2 font-mono text-xs">{{ row.asset_code }}</td>
                                        <td class="px-3 py-2">
                                            {{ row.name }}
                                            <span class="block text-xs text-muted-foreground">{{
                                                [row.category, row.brand, row.model].filter(Boolean).join(' · ')
                                            }}</span>
                                        </td>
                                        <td class="hidden px-3 py-2 font-mono text-xs sm:table-cell">{{ row.serial_number ?? '' }}</td>
                                        <td class="px-3 py-2"><AssetStatusBadge :status="String(row.status)" /></td>
                                        <td class="hidden px-3 py-2 md:table-cell">{{ row.location ?? '' }}</td>
                                        <td v-if="requestable.includes(group.company_id)" class="px-3 py-2 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                :disabled="inCart('asset', Number(row.id)) || !Number(row.available)"
                                                @click="add(group.company_id, row)"
                                            >
                                                {{ t('shares.ask') }}
                                            </Button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
                <p v-if="results.length" class="text-xs text-muted-foreground">{{ t('shares.limit_note') }}</p>
            </template>

            <!-- What I asked other companies for -->
            <section v-if="myRequests.length" class="space-y-2">
                <h3 class="font-semibold">{{ t('shares.my_requests') }}</h3>
                <SharedRequestList :requests="myRequests" show-ticket />
            </section>
        </div>
    </AppLayout>
</template>
