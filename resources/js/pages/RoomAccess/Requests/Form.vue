<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import RoomRulesDialog from '@/components/RoomRulesDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Person = { id: number | null; name: string; company: string; phone: string; id_number: string; id_number_masked?: string | null };
type Item = { name: string; serial_number: string; quantity: number; direction: string };
type Ticket = { id: number; ulid: string; ticket_no: string; title: string };

interface FormData {
    server_room_id: number | null;
    planned_start: string | null;
    planned_end: string | null;
    purpose: string;
    ticket: Ticket | null;
    contract_id: number | null;
    people: Person[];
    items: Item[];
}

const props = defineProps<{
    request: (FormData & { ulid: string; request_no: string }) | null;
    copy: FormData | null;
    rooms: { id: number; ulid: string; name: string; customer: string; location: string | null; requires_id_number: boolean }[];
    contracts: { id: number; label: string }[];
    visitors: { name: string; company: string | null; phone: string | null }[];
    previous: { ulid: string; label: string }[];
    serviceOn: boolean;
}>();

const page = usePage<SharedData>();
const title = props.request ? `${t('room_requests.edit')} ${props.request.request_no}` : t('room_requests.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('room_requests.title'), href: route('room-access.requests.index') },
    { title, href: props.request ? route('room-access.requests.edit', props.request.ulid) : route('room-access.requests.create') },
];

const start = props.request ?? props.copy;
const blankPerson = (): Person => ({ id: null, name: '', company: '', phone: '', id_number: '' });
const form = useForm({
    server_room_id: start?.server_room_id ?? (null as number | null),
    planned_start: start?.planned_start ?? '',
    planned_end: start?.planned_end ?? '',
    purpose: start?.purpose ?? '',
    ticket_id: start?.ticket?.id ?? (null as number | null),
    contract_id: start?.contract_id ?? (null as number | null),
    people: (start?.people.length ? start.people.map((p) => ({ ...p, company: p.company ?? '', phone: p.phone ?? '', id_number: '' })) : [blankPerson()]) as Person[],
    items: (start?.items ?? []).map((i) => ({ ...i, serial_number: i.serial_number ?? '' })) as Item[],
});
const room = computed(() => props.rooms.find((r) => r.id === form.server_room_id) ?? null);

// --- ticket: searched among the open ones the user may see ---
const ticket = ref<Ticket | null>(start?.ticket ?? null);
const ticketQ = ref('');
const ticketOptions = ref<Ticket[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(ticketQ, (q) => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const response = await fetch(route('room-access.requests.tickets', { q }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        ticketOptions.value = response.ok ? await response.json() : [];
    }, 250);
});
const pickTicket = (option: Ticket | null) => {
    ticket.value = option;
    form.ticket_id = option?.id ?? null;
    ticketQ.value = '';
    ticketOptions.value = [];
};

// --- people: picked from those entered before (name, company, phone) ---
const fillFromVisitor = (person: Person) => {
    const known = props.visitors.find((v) => v.name === person.name);
    if (known) {
        person.company = person.company || (known.company ?? '');
        person.phone = person.phone || (known.phone ?? '');
    }
};

const copyFrom = (ulid: string) => ulid && router.get(route('room-access.requests.create'), { copy: ulid });

// --- saving: a draft, or sent after the rules are accepted in the popup ---
const dialog = ref(false);
const send = (submit: boolean, versionId: number | null = null) => {
    form.transform((data) => ({ ...data, submit, accept: submit, version_id: versionId }));
    const options = { onFinish: () => (dialog.value = false) };
    if (props.request) form.put(route('room-access.requests.update', props.request.ulid), options);
    else form.post(route('room-access.requests.store'), options);
};
const openRules = () => {
    if (!form.server_room_id) {
        form.setError('server_room_id', t('room_requests.choose_room'));
        return;
    }
    dialog.value = true;
};

const errors = computed(() => form.errors as Record<string, string>);
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <form class="max-w-4xl space-y-8 p-4 pb-24" @submit.prevent="send(false)">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="title" :description="t('room_requests.description')" />
                <select v-if="!request && previous.length" :class="selectClass" class="max-w-xs" @change="copyFrom(($event.target as HTMLSelectElement).value)">
                    <option value="">{{ t('room_requests.copy_from') }}</option>
                    <option v-for="p in previous" :key="p.ulid" :value="p.ulid">{{ p.label }}</option>
                </select>
            </div>

            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>
            <InputError :message="errors.accept || errors.rules || errors.request" />

            <!-- 1. Room and time -->
            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2 sm:col-span-2">
                    <Label for="room" required>{{ t('room_requests.room') }}</Label>
                    <select id="room" v-model="form.server_room_id" :class="selectClass" required>
                        <option :value="null" disabled>{{ t('room_requests.choose_room') }}</option>
                        <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.customer }} · {{ r.name }}{{ r.location ? ` (${r.location})` : '' }}</option>
                    </select>
                    <InputError :message="form.errors.server_room_id" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="start" required>{{ t('room_requests.planned_start') }}</Label>
                    <Input id="start" v-model="form.planned_start" type="datetime-local" required />
                    <InputError :message="form.errors.planned_start" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="end" required>{{ t('room_requests.planned_end') }}</Label>
                    <Input id="end" v-model="form.planned_end" type="datetime-local" required />
                    <InputError :message="form.errors.planned_end" />
                </div>
            </section>

            <!-- 2. People -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('room_requests.people') }} <span class="text-red-600">*</span></h3>
                <p v-if="room?.requires_id_number" class="text-xs text-muted-foreground">{{ t('room_requests.id_hint') }}</p>
                <datalist id="visitors">
                    <option v-for="v in visitors" :key="v.name + v.company" :value="v.name">{{ v.company }}</option>
                </datalist>
                <div v-for="(person, i) in form.people" :key="i" class="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
                    <Input v-model="person.name" list="visitors" :placeholder="t('room_requests.person_name')" required maxlength="255" @change="fillFromVisitor(person)" />
                    <Input v-model="person.company" :placeholder="t('room_requests.person_company')" maxlength="255" />
                    <Input v-model="person.phone" :placeholder="t('room_requests.person_phone')" maxlength="50" />
                    <div v-if="room?.requires_id_number" class="grid gap-1">
                        <Input v-model="person.id_number" :placeholder="t('room_requests.id_number')" maxlength="30" autocomplete="off" inputmode="numeric" />
                        <span v-if="person.id_number_masked" class="text-xs text-muted-foreground">{{
                            t('room_requests.id_number_kept', { masked: person.id_number_masked })
                        }}</span>
                    </div>
                    <div class="sm:col-span-2">
                        <Button v-if="form.people.length > 1" type="button" variant="ghost" size="sm" @click="form.people.splice(i, 1)">{{ t('common.delete') }}</Button>
                    </div>
                </div>
                <InputError :message="errors.people || errors['people.0.name']" />
                <Button type="button" variant="outline" size="sm" @click="form.people.push(blankPerson())">{{ t('room_requests.add_person') }}</Button>
            </section>

            <!-- 3. Purpose and links -->
            <section class="space-y-4">
                <div class="grid gap-2">
                    <Label for="purpose" required>{{ t('room_requests.purpose') }}</Label>
                    <textarea id="purpose" v-model="form.purpose" rows="3" required maxlength="2000" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" />
                    <InputError :message="form.errors.purpose" />
                </div>
                <p class="text-xs text-muted-foreground">{{ t('room_requests.link_hint') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="serviceOn" class="grid content-start gap-2">
                        <Label>{{ t('room_requests.ticket') }}</Label>
                        <div v-if="ticket" class="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                            <span><span class="font-mono">{{ ticket.ticket_no }}</span> {{ ticket.title }}</span>
                            <Button type="button" variant="ghost" size="sm" @click="pickTicket(null)">{{ t('common.delete') }}</Button>
                        </div>
                        <template v-else>
                            <Input v-model="ticketQ" type="search" :placeholder="t('room_requests.ticket_search')" />
                            <ul v-if="ticketOptions.length" class="max-h-48 overflow-y-auto rounded-md border text-sm">
                                <li v-for="option in ticketOptions" :key="option.id">
                                    <button type="button" class="w-full px-3 py-2 text-left hover:bg-muted/50" @click="pickTicket(option)">
                                        <span class="font-mono">{{ option.ticket_no }}</span> {{ option.title }}
                                    </button>
                                </li>
                            </ul>
                        </template>
                        <InputError :message="form.errors.ticket_id" />
                    </div>
                    <div v-if="contracts.length" class="grid content-start gap-2">
                        <Label for="contract">{{ t('room_requests.contract') }}</Label>
                        <select id="contract" v-model="form.contract_id" :class="selectClass">
                            <option :value="null">{{ t('room_requests.no_contract') }}</option>
                            <option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </select>
                        <InputError :message="form.errors.contract_id" />
                    </div>
                </div>
            </section>

            <!-- 4. Equipment -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('room_requests.items') }}</h3>
                <p v-if="!form.items.length" class="text-sm text-muted-foreground">{{ t('room_requests.no_items') }}</p>
                <div v-for="(item, i) in form.items" :key="i" class="grid gap-2 sm:grid-cols-[2fr_1fr_5rem_auto_auto]">
                    <Input v-model="item.name" :placeholder="t('room_requests.item_name')" required maxlength="255" />
                    <Input v-model="item.serial_number" :placeholder="t('room_requests.item_serial')" class="font-mono" maxlength="100" />
                    <Input v-model="item.quantity" type="number" min="1" :aria-label="t('room_requests.item_qty')" />
                    <select v-model="item.direction" :class="selectClass">
                        <option value="in">{{ t('room_requests.directions.in') }}</option>
                        <option value="out">{{ t('room_requests.directions.out') }}</option>
                    </select>
                    <Button type="button" variant="ghost" size="sm" @click="form.items.splice(i, 1)">{{ t('common.delete') }}</Button>
                </div>
                <Button type="button" variant="outline" size="sm" @click="form.items.push({ name: '', serial_number: '', quantity: 1, direction: 'in' })">{{
                    t('room_requests.add_item')
                }}</Button>
            </section>

            <p class="text-xs text-muted-foreground">{{ t('room_requests.attachments_hint') }}</p>

            <div class="flex flex-wrap gap-3">
                <Button type="button" :disabled="form.processing" @click="openRules">{{ t('room_requests.submit') }}</Button>
                <Button variant="outline" :disabled="form.processing">{{ t('room_requests.save_draft') }}</Button>
                <Button variant="ghost" as-child>
                    <Link :href="request ? route('room-access.requests.show', request.ulid) : route('room-access.requests.index')">{{ t('common.cancel') }}</Link>
                </Button>
            </div>
        </form>

        <RoomRulesDialog
            :room-ulid="room?.ulid ?? null"
            :open="dialog"
            :processing="form.processing"
            @accept="(versionId) => send(true, versionId)"
            @close="dialog = false"
        />
    </AppLayout>
</template>
