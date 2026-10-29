<script lang="ts">
// What one requested / purchased item is for (App\Modules\Platform\Support\ItemPurpose).
// Types, not interfaces: a form's data (useForm) can hold them.
export type ItemPurposeTicket = { id: number; ulid: string; ticket_no: string; title: string; customer_id: number | null };
export type ItemPurposeValue = {
    purpose: string;
    purpose_other: string;
    // Used inside the company, or for a customer and (when it has any) one of its projects.
    for_internal: boolean;
    customer_name: string;
    contract_id: number | null;
    ticket: ItemPurposeTicket | null;
    replaced_serial: string;
    // A new photo of the broken unit, or the address of the one already kept.
    replaced_photo: File | null;
    replaced_photo_url: string | null;
};

export const NEEDS_EVIDENCE = ['replace', 'customer_swap'];

export const emptyPurpose = (): ItemPurposeValue => ({
    purpose: '',
    purpose_other: '',
    for_internal: false,
    customer_name: '',
    contract_id: null,
    ticket: null,
    replaced_serial: '',
    replaced_photo: null,
    replaced_photo_url: null,
});

// A new line starts with what the line before is for, for whom and on which job (not its evidence).
export const purposeLike = (before: ItemPurposeValue | undefined): ItemPurposeValue =>
    before
        ? {
              ...emptyPurpose(),
              purpose: before.purpose,
              purpose_other: before.purpose_other,
              for_internal: before.for_internal,
              customer_name: before.customer_name,
              contract_id: before.contract_id,
              ticket: before.ticket,
          }
        : emptyPurpose();

// A project (MA contract) a line can be for (ContractOptions).
export type ItemPurposeContract = { id: number; customer_id: number | null; customer_name: string | null; label: string };

export const hasEvidence = (value: ItemPurposeValue) =>
    !NEEDS_EVIDENCE.includes(value.purpose) || value.replaced_serial.trim() !== '' || value.replaced_photo !== null || value.replaced_photo_url !== null;
</script>

<script setup lang="ts">
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { PhotoError, prepareImage } from '@/lib/image';
import { Camera, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * The "what for" fields of one line: purpose (other = typed); for whom — inside the company, or a
 * customer (from the list or typed; follows a picked ticket) and, when that customer has any, one
 * of its projects; the ticket (searched at ticketsUrl; none = hidden) and, for a replacement, the
 * broken unit's serial (typed or scanned) or a photo — one of them is required.
 */
const props = defineProps<{
    id: string;
    purposes: string[];
    customers: { id: number; name: string }[];
    contracts: ItemPurposeContract[];
    ticketsUrl: string | null;
    ticketRequired?: boolean;
    // The server's messages of this line's fields, by field name.
    errors: Record<string, string | undefined>;
}>();
const value = defineModel<ItemPurposeValue>({ required: true });

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const needsEvidence = computed(() => NEEDS_EVIDENCE.includes(value.value.purpose));

// --- For whom: inside the company, one of the customers, or typed -----------------------------
const OTHER = '__other__';
const INTERNAL = '__internal__';
const names = computed(() => props.customers.map((c) => c.name));
const typing = ref(value.value.customer_name !== '' && !names.value.includes(value.value.customer_name));
const customerChoice = computed({
    get: () => (value.value.for_internal ? INTERNAL : value.value.customer_name),
    set: (choice: string) => {
        value.value.for_internal = choice === INTERNAL;
        value.value.contract_id = null;
        if (choice === OTHER || choice === INTERNAL) {
            value.value.customer_name = '';
            typing.value = choice === OTHER;
        } else {
            value.value.customer_name = choice;
        }
    },
});

// --- Project: one of the customer's ------------------------------------------------------------
const theirContracts = computed(() =>
    value.value.for_internal || value.value.customer_name === '' ? [] : props.contracts.filter((c) => c.customer_name === value.value.customer_name),
);
// Only one: it is that one.
watch(
    theirContracts,
    (list) => {
        if (list.length === 1 && value.value.contract_id === null) value.value.contract_id = list[0].id;
        if (value.value.contract_id !== null && list.length > 0 && !list.some((c) => c.id === value.value.contract_id)) value.value.contract_id = null;
    },
    { immediate: true },
);
const pickFromList = () => {
    typing.value = false;
    if (!names.value.includes(value.value.customer_name)) value.value.customer_name = '';
};
// Copied from another line: show it the way it is held.
watch(
    () => value.value.customer_name,
    (name) => {
        if (name !== '' && !names.value.includes(name)) typing.value = true;
    },
);

// --- Ticket ---------------------------------------------------------------------------------
const ticketSearch = ref('');
const ticketResults = ref<NonNullable<ItemPurposeValue['ticket']>[]>([]);
const ticketOpen = ref(false);
let ticketTimer: ReturnType<typeof setTimeout> | undefined;
const findTickets = () => {
    if (!props.ticketsUrl) return;
    clearTimeout(ticketTimer);
    ticketTimer = setTimeout(async () => {
        try {
            const url = new URL(props.ticketsUrl!, window.location.origin);
            url.searchParams.set('q', ticketSearch.value);
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            ticketResults.value = response.ok ? await response.json() : [];
            ticketOpen.value = true;
        } catch {
            ticketResults.value = [];
        }
    }, 300);
};
const pickTicket = (ticket: NonNullable<ItemPurposeValue['ticket']>) => {
    value.value.ticket = ticket;
    ticketOpen.value = false;
    ticketSearch.value = '';
    // The job's customer, when it is one of the list.
    const customer = props.customers.find((c) => c.id === ticket.customer_id);
    if (customer) {
        value.value.for_internal = false;
        value.value.customer_name = customer.name;
        typing.value = false;
    }
};

// --- Photo of the broken unit ---------------------------------------------------------------
const preview = ref<string | null>(null);
const photoError = ref<string | null>(null);
watch(
    () => value.value.replaced_photo,
    (file, _, onCleanup) => {
        preview.value = file ? URL.createObjectURL(file) : null;
        const made = preview.value;
        onCleanup(() => made && URL.revokeObjectURL(made));
    },
    { immediate: true },
);
onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value));
const shownPhoto = computed(() => preview.value ?? value.value.replaced_photo_url);
const pickPhoto = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    photoError.value = null;
    try {
        value.value.replaced_photo = await prepareImage(file);
    } catch (e) {
        photoError.value = e instanceof PhotoError ? e.message : t('photos.failed');
    }
};
const removePhoto = () => {
    value.value.replaced_photo = null;
    value.value.replaced_photo_url = null;
};
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="grid content-start gap-1">
            <Label :for="`${id}-purpose`" required>{{ t('item_purpose.purpose') }}</Label>
            <select :id="`${id}-purpose`" v-model="value.purpose" required :class="selectClass">
                <option value="" disabled>{{ t('item_purpose.pick') }}</option>
                <option v-for="purpose in purposes" :key="purpose" :value="purpose">{{ t(`item_purpose.options.${purpose}`) }}</option>
            </select>
            <Input
                v-if="value.purpose === 'other'"
                v-model="value.purpose_other"
                required
                maxlength="255"
                :placeholder="t('item_purpose.other_placeholder')"
                :aria-label="t('item_purpose.other_placeholder')"
            />
            <InputError :message="errors.purpose ?? errors.purpose_other" />
        </div>

        <div class="grid content-start gap-1">
            <Label :for="`${id}-customer`" required>{{ t('item_purpose.customer') }}</Label>
            <select v-if="!typing" :id="`${id}-customer`" v-model="customerChoice" required :class="selectClass">
                <option value="" disabled>{{ t('item_purpose.customer_pick') }}</option>
                <option :value="INTERNAL">{{ t('item_purpose.internal') }}</option>
                <option v-for="name in names" :key="name" :value="name">{{ name }}</option>
                <option :value="OTHER">{{ t('item_purpose.customer_other') }}</option>
            </select>
            <div v-else class="flex gap-2">
                <Input :id="`${id}-customer`" v-model="value.customer_name" required maxlength="255" class="min-w-0 flex-1" />
                <Button type="button" variant="outline" size="sm" class="h-9" @click="pickFromList">{{
                    t('item_purpose.customer_list')
                }}</Button>
            </div>
            <InputError :message="errors.customer_name" />
        </div>

        <div v-if="theirContracts.length" class="grid content-start gap-1 sm:col-span-2">
            <Label :for="`${id}-contract`" required>{{ t('item_purpose.contract') }}</Label>
            <select :id="`${id}-contract`" v-model="value.contract_id" required :class="selectClass">
                <option :value="null" disabled>{{ t('item_purpose.contract_pick') }}</option>
                <option v-for="contract in theirContracts" :key="contract.id" :value="contract.id">{{ contract.label }}</option>
            </select>
            <InputError :message="errors.contract_id" />
        </div>
        <InputError v-else-if="errors.contract_id" class="sm:col-span-2" :message="errors.contract_id" />

        <div v-if="ticketsUrl" class="grid content-start gap-1 sm:col-span-2">
            <Label :for="`${id}-ticket`" :required="ticketRequired">{{ t('item_purpose.ticket') }}</Label>
            <div v-if="value.ticket" class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                <span class="font-mono text-xs">{{ value.ticket.ticket_no }}</span>
                <span class="min-w-0 flex-1 truncate">{{ value.ticket.title }}</span>
                <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="t('item_purpose.clear_ticket')" @click="value.ticket = null">
                    <X class="h-4 w-4" />
                </button>
            </div>
            <div v-else class="relative">
                <Input
                    :id="`${id}-ticket`"
                    v-model="ticketSearch"
                    type="search"
                    autocomplete="off"
                    :placeholder="t('item_purpose.ticket_placeholder')"
                    @input="findTickets"
                    @focus="findTickets"
                    @blur="ticketOpen = false"
                />
                <ul v-if="ticketOpen" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-background shadow-md">
                    <li v-for="ticket in ticketResults" :key="ticket.id">
                        <button type="button" class="flex w-full gap-2 px-3 py-2 text-left text-sm hover:bg-muted" @mousedown.prevent="pickTicket(ticket)">
                            <span class="font-mono text-xs">{{ ticket.ticket_no }}</span>
                            <span>{{ ticket.title }}</span>
                        </button>
                    </li>
                    <li v-if="ticketResults.length === 0" class="px-3 py-2 text-sm text-muted-foreground">{{ t('item_purpose.no_tickets') }}</li>
                </ul>
            </div>
            <p v-if="ticketRequired && !value.ticket" class="text-xs text-amber-700 dark:text-amber-400">{{ t('item_purpose.ticket_part_hint') }}</p>
            <InputError :message="errors.ticket_id" />
        </div>

        <!-- Replacing a broken unit: its serial or a photo -->
        <div v-if="needsEvidence" class="grid gap-3 rounded-md border border-dashed p-3 sm:col-span-2 sm:grid-cols-2">
            <p class="text-xs font-medium sm:col-span-2">{{ t('item_purpose.evidence') }}</p>
            <div class="grid content-start gap-1">
                <Label :for="`${id}-serial`">{{ t('item_purpose.serial') }}</Label>
                <div class="flex gap-2">
                    <Input :id="`${id}-serial`" v-model="value.replaced_serial" maxlength="100" class="min-w-0 flex-1 font-mono" />
                    <BarcodeScanButton :hint="t('item_purpose.serial_scan_hint')" @scanned="(text) => (value.replaced_serial = text)" />
                </div>
            </div>
            <div class="grid content-start gap-1">
                <span class="text-sm font-medium">{{ t('item_purpose.photo') }}</span>
                <div class="flex items-center gap-2">
                    <img v-if="shownPhoto" :src="shownPhoto" :alt="t('item_purpose.photo')" class="h-16 w-16 rounded-md border object-cover" />
                    <label class="inline-flex h-9 cursor-pointer items-center gap-1 rounded-md border px-3 text-sm font-medium hover:bg-accent">
                        <Camera class="h-4 w-4" />
                        {{ shownPhoto ? t('item_purpose.photo_change') : t('item_purpose.photo_add') }}
                        <input type="file" accept="image/*" class="sr-only" @change="pickPhoto" />
                    </label>
                    <Button v-if="shownPhoto" type="button" variant="ghost" size="sm" :aria-label="t('item_purpose.photo_remove')" @click="removePhoto">
                        <X class="h-4 w-4" />
                    </Button>
                </div>
                <InputError :message="photoError ?? errors.replaced_photo" />
            </div>
            <p v-if="!hasEvidence(value)" class="text-xs text-amber-700 dark:text-amber-400 sm:col-span-2">{{ t('item_purpose.evidence_missing') }}</p>
            <InputError class="sm:col-span-2" :message="errors.replaced_serial" />
        </div>
    </div>
</template>
