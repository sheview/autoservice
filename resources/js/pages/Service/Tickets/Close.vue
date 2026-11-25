<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, Check, MapPin, Minus, Plus, Printer, Search, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

interface PartOption {
    id: number;
    code: string;
    name: string;
    unit: string;
    qty_on_hand: number;
    track_serial: boolean;
}

// Types, not interfaces: form data must be plain records.
type PartLine = {
    part_id: number;
    qty: number;
};

type RemovedPiece = {
    item_name: string;
    serial_number: string;
    problem: string;
    disposition: string;
};

const props = defineProps<{
    ticket: {
        ulid: string;
        ticket_no: string;
        title: string;
        status: string;
        contact_name: string | null;
        asset: string | null;
        warranty_checked: boolean;
        closable: boolean;
    };
    presets: { symptom: string[]; solution: string[] };
    // Parts go on an issue request sent for approval (stock moves when it is handed out).
    canRequestParts: boolean;
    partOptions?: PartOption[];
    warrantyStatuses: string[];
    dispositions: string[];
    limits: { photos: number; photo_kb: number };
}>();

const page = usePage<SharedData>();
const errors = computed(() => page.props.errors as Record<string, string>);

const form = useForm({
    symptoms: [] as string[],
    solutions: [] as string[],
    note: '',
    warranty_status: null as string | null,
    parts: [] as PartLine[],
    removed: [] as RemovedPiece[],
    signer_name: props.ticket.contact_name ?? '',
    tech_signature: null as string | null,
    signature: null as string | null,
    signed_sheet: null as File | null,
    lat: null as number | null,
    lng: null as number | null,
    before: [] as File[],
    after: [] as File[],
});

// --- 1. chips: tap to pick, type to add one of your own ---
const chips = ref({ symptom: [...props.presets.symptom], solution: [...props.presets.solution] });
const typed = ref({ symptom: '', solution: '' });
const field = (kind: 'symptom' | 'solution') => (kind === 'symptom' ? form.symptoms : form.solutions);
const toggle = (kind: 'symptom' | 'solution', label: string) => {
    const list = field(kind);
    const i = list.indexOf(label);
    if (i >= 0) list.splice(i, 1);
    else list.push(label);
};
const addTyped = (kind: 'symptom' | 'solution') => {
    const label = typed.value[kind].trim();
    if (!label) return;
    if (!chips.value[kind].includes(label)) chips.value[kind].push(label);
    if (!field(kind).includes(label)) field(kind).push(label);
    typed.value[kind] = '';
};

// --- 2. photos: from the camera, made small on the phone before upload ---
const previews = ref<{ before: string[]; after: string[] }>({ before: [], after: [] });
const shrink = (file: File, max = 1600): Promise<File> =>
    new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(1, max / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(
                (blob) => resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file),
                'image/jpeg',
                0.8,
            );
            URL.revokeObjectURL(img.src);
        };
        img.onerror = () => resolve(file);
        img.src = URL.createObjectURL(file);
    });
const addPhotos = async (stage: 'before' | 'after', event: Event) => {
    const input = event.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        if (form[stage].length >= props.limits.photos) break;
        const small = await shrink(file);
        form[stage].push(small);
        previews.value[stage].push(URL.createObjectURL(small));
    }
    input.value = '';
};
const removePhoto = (stage: 'before' | 'after', i: number) => {
    form[stage].splice(i, 1);
    URL.revokeObjectURL(previews.value[stage][i]);
    previews.value[stage].splice(i, 1);
};

// --- 3. parts: search (or a scanner typing the code), with the stock shown ---
const partSearch = ref('');
const chosen = ref<Record<number, PartOption>>({});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(partSearch, (q) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.reload({ only: ['partOptions'], data: { part_search: q } }), 300);
});
const addPart = (part: PartOption) => {
    chosen.value[part.id] = part;
    const line = form.parts.find((l) => l.part_id === part.id);
    if (line) line.qty++;
    else form.parts.push({ part_id: part.id, qty: 1 });
    partSearch.value = '';
};
const short = (line: PartLine) => line.qty > (chosen.value[line.part_id]?.qty_on_hand ?? 0);

// --- 3b. pieces taken out of the device (a note only, not stock) ---
const addRemoved = () => form.removed.push({ item_name: '', serial_number: '', problem: '', disposition: props.dispositions[0] });

// --- 4. sign-off, always: on the screen (the technician, then the customer) or on the printed sheet ---
const how = ref<'screen' | 'paper'>('screen');
const sheetPreview = ref<string | null>(null);
const addSheet = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    form.signed_sheet = await shrink(file, 2200);
    sheetPreview.value = URL.createObjectURL(form.signed_sheet);
};
// What is still missing before the job can be closed (the server checks the same).
const missing = computed(() => {
    const items: string[] = [];
    if (how.value === 'paper') {
        if (!form.signed_sheet) items.push('sheet');
    } else {
        if (form.solutions.length === 0) items.push('solution');
        if (!form.tech_signature) items.push('tech_signature');
        if (!form.signature) items.push('signature');
    }
    if (!form.signer_name.trim()) items.push('signer');
    return items;
});
const techName = computed(() => (page.props.auth.user as { name?: string } | null)?.name ?? '');

onMounted(() => {
    // Where the technician is, if the phone allows it (optional).
    navigator.geolocation?.getCurrentPosition(
        (pos) => {
            form.lat = Number(pos.coords.latitude.toFixed(7));
            form.lng = Number(pos.coords.longitude.toFixed(7));
        },
        () => undefined,
        { enableHighAccuracy: true, timeout: 10000 },
    );
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            tech_signature: how.value === 'screen' ? data.tech_signature : null,
            signature: how.value === 'screen' ? data.signature : null,
            signed_sheet: how.value === 'paper' ? data.signed_sheet : null,
        }))
        .post(route('service.tickets.close.store', props.ticket.ulid), { forceFormData: true, preserveScroll: true });

const chipClass = (on: boolean) => (on ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background');
const big = 'flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl px-4 text-base font-semibold transition active:scale-[0.98]';
const input = 'h-12 w-full rounded-xl border border-input bg-transparent px-3 text-base';
</script>

<template>
    <AppLayout>
        <Head :title="`${t('close.title')} ${ticket.ticket_no}`" />

        <form class="mx-auto max-w-xl space-y-6 p-4 pb-28" @submit.prevent="submit">
            <div>
                <div class="font-mono text-sm text-muted-foreground">{{ ticket.ticket_no }}</div>
                <h1 class="text-xl font-semibold">{{ ticket.title }}</h1>
                <div v-if="ticket.asset" class="text-sm text-muted-foreground">{{ ticket.asset }}</div>
            </div>

            <!-- The job sheet to print, for a sign-off on paper: up here, where it is found at once -->
            <a
                :href="route('service.tickets.print', ticket.ulid)"
                target="_blank"
                :class="[big, 'bg-gradient-to-r from-violet-600 to-indigo-500 text-white shadow-lg']"
            >
                <Printer class="size-6" />{{ t('field_link.print_top') }}
            </a>
            <p class="-mt-4 text-center text-xs text-muted-foreground">{{ t('field_link.print_top_hint') }}</p>

            <p v-if="!ticket.closable" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                {{ t('close.not_closable') }}
                <Link :href="route('service.tickets.show', ticket.ulid)" class="font-semibold underline">{{ t('close.back') }}</Link>
            </p>
            <InputError :message="errors.ticket" />

            <!-- 1. What was wrong, what fixed it -->
            <section class="space-y-4">
                <h2 class="text-lg font-semibold">{{ t('close.step_what') }}</h2>
                <div v-for="kind in ['symptom', 'solution'] as const" :key="kind" class="space-y-2">
                    <div class="text-sm font-semibold">
                        {{ t(kind === 'symptom' ? 'close.symptoms' : 'close.solutions') }}
                        <span v-if="kind === 'solution' && how === 'screen'" class="text-red-600">*</span>
                    </div>
                    <p v-if="kind === 'solution'" class="text-xs text-muted-foreground">{{ t('field_link.solution_required') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="label in chips[kind]"
                            :key="label"
                            type="button"
                            class="min-h-12 rounded-full border px-4 text-sm transition"
                            :class="chipClass(field(kind).includes(label))"
                            @click="toggle(kind, label)"
                        >
                            <Check v-if="field(kind).includes(label)" class="mr-1 inline size-4" />{{ label }}
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <input v-model="typed[kind]" :class="input" :placeholder="t('close.other')" @keydown.enter.prevent="addTyped(kind)" />
                        <button type="button" class="h-12 shrink-0 rounded-xl border px-4" :aria-label="t('close.add')" @click="addTyped(kind)">
                            <Plus class="size-5" />
                        </button>
                    </div>
                </div>
                <InputError :message="errors.symptoms || errors.solutions" />
                <textarea
                    v-model="form.note"
                    rows="2"
                    class="w-full rounded-xl border border-input bg-transparent px-3 py-2 text-base"
                    :placeholder="t('close.note')"
                />

                <div v-if="!ticket.warranty_checked" class="space-y-2">
                    <div class="text-sm font-semibold">{{ t('close.warranty') }} <span class="text-red-600">*</span></div>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="status in warrantyStatuses"
                            :key="status"
                            type="button"
                            class="min-h-12 rounded-xl border px-3 text-sm"
                            :class="chipClass(form.warranty_status === status)"
                            @click="form.warranty_status = status"
                        >
                            {{ t(`tickets.warranty_statuses.${status}`) }}
                        </button>
                    </div>
                    <InputError :message="errors.warranty_status" />
                </div>
            </section>

            <!-- 2. Photos -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">{{ t('close.step_photos') }}</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div v-for="stage in ['before', 'after'] as const" :key="stage" class="space-y-2">
                        <label :class="[big, 'cursor-pointer border bg-background text-sm']">
                            <Camera class="size-5" />{{ t(`close.${stage}`) }}
                            <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="addPhotos(stage, $event)" />
                        </label>
                        <div class="grid grid-cols-3 gap-1">
                            <div v-for="(src, i) in previews[stage]" :key="src" class="relative">
                                <img :src="src" class="aspect-square w-full rounded-lg object-cover" alt="" />
                                <button
                                    type="button"
                                    class="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white"
                                    :aria-label="t('close.remove')"
                                    @click="removePhoto(stage, i)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>
                        </div>
                        <InputError :message="errors[stage] || errors[`${stage}.0`]" />
                    </div>
                </div>
            </section>

            <!-- 3. Parts -->
            <section v-if="canRequestParts" class="space-y-3">
                <h2 class="text-lg font-semibold">{{ t('close.step_parts') }}</h2>
                <p class="text-sm text-muted-foreground">{{ t('close.parts_hint') }}</p>
                <div class="relative">
                    <Search class="absolute left-3 top-3.5 size-5 text-muted-foreground" />
                    <input v-model="partSearch" :class="input" class="pl-10" :placeholder="t('close.part_search')" autocomplete="off" />
                </div>
                <ul v-if="partSearch && partOptions?.length" class="divide-y rounded-xl border">
                    <li v-for="part in partOptions" :key="part.id">
                        <button type="button" class="flex min-h-12 w-full items-center justify-between gap-2 px-3 text-left" @click="addPart(part)">
                            <span
                                ><span class="font-mono text-xs">{{ part.code }}</span> {{ part.name }}</span
                            >
                            <span class="text-xs text-muted-foreground">{{ t('close.in_stock', { qty: part.qty_on_hand, unit: part.unit }) }}</span>
                        </button>
                    </li>
                </ul>
                <p v-if="!form.parts.length" class="text-sm text-muted-foreground">{{ t('close.no_parts') }}</p>
                <ul class="space-y-2">
                    <template v-for="(line, i) in form.parts" :key="line.part_id">
                        <li
                            class="flex items-center gap-2 rounded-xl border p-2"
                            :class="short(line) ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/40' : ''"
                        >
                            <span class="min-w-0 flex-1 text-sm">
                                {{ chosen[line.part_id]?.name }}
                                <span class="block text-xs" :class="short(line) ? 'font-semibold text-amber-700' : 'text-muted-foreground'">
                                    {{ t('close.in_stock', { qty: chosen[line.part_id]?.qty_on_hand ?? 0, unit: chosen[line.part_id]?.unit ?? '' }) }}
                                    <template v-if="short(line)"> · {{ t('close.backorder') }}</template>
                                </span>
                            </span>
                            <button
                                type="button"
                                class="size-12 rounded-xl border"
                                :aria-label="'-'"
                                @click="line.qty > 1 ? line.qty-- : form.parts.splice(i, 1)"
                            >
                                <Minus class="mx-auto size-4" />
                            </button>
                            <span class="w-8 text-center font-semibold tabular-nums">{{ line.qty }}</span>
                            <button type="button" class="size-12 rounded-xl border" :aria-label="'+'" @click="line.qty++">
                                <Plus class="mx-auto size-4" />
                            </button>
                        </li>
                    </template>
                </ul>
                <InputError :message="errors.parts" />
            </section>

            <!-- 3b. Pieces taken out of the device -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">{{ t('close.step_removed') }}</h2>
                <p class="text-sm text-muted-foreground">{{ t('close.removed_hint') }}</p>
                <div v-for="(piece, i) in form.removed" :key="i" class="space-y-2 rounded-xl border p-3">
                    <div class="flex items-center gap-2">
                        <input v-model="piece.item_name" :class="input" :placeholder="t('close.removed_item')" required maxlength="255" />
                        <button
                            type="button"
                            class="size-12 shrink-0 rounded-xl border"
                            :aria-label="t('common.delete')"
                            @click="form.removed.splice(i, 1)"
                        >
                            <Trash2 class="mx-auto size-4" />
                        </button>
                    </div>
                    <input v-model="piece.serial_number" :class="input" class="font-mono" :placeholder="t('close.removed_serial')" maxlength="100" />
                    <input v-model="piece.problem" :class="input" :placeholder="t('close.removed_problem')" maxlength="1000" />
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="d in dispositions"
                            :key="d"
                            type="button"
                            class="min-h-11 rounded-full border px-4 text-sm"
                            :class="chipClass(piece.disposition === d)"
                            @click="piece.disposition = d"
                        >
                            {{ t(`close.dispositions.${d}`) }}
                        </button>
                    </div>
                </div>
                <button type="button" class="flex min-h-12 items-center gap-2 text-sm font-medium text-primary" @click="addRemoved">
                    <Plus class="size-4" />{{ t('close.removed_add') }}
                </button>
                <InputError :message="errors['removed.0.item_name'] || errors['removed.0.disposition']" />
            </section>

            <!-- 4. Sign-off: on the screen (the technician first, then the customer) or on paper -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">{{ t('field_link.sign_off') }} <span class="text-red-600">*</span></h2>
                <p class="text-xs text-muted-foreground">{{ t('field_link.sign_off_hint') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="min-h-12 rounded-full border px-4 text-sm" :class="chipClass(how === 'screen')" @click="how = 'screen'">
                        {{ t('field_link.sign_screen') }}
                    </button>
                    <button type="button" class="min-h-12 rounded-full border px-4 text-sm" :class="chipClass(how === 'paper')" @click="how = 'paper'">
                        {{ t('field_link.sign_paper') }}
                    </button>
                </div>
                <label class="block space-y-1.5 text-sm">
                    <span class="font-semibold">{{ t('field_link.signer') }} <span class="text-red-600">*</span></span>
                    <input v-model="form.signer_name" :class="input" maxlength="255" />
                </label>
                <InputError :message="errors.signer_name" />
                <template v-if="how === 'screen'">
                    <div class="space-y-1.5">
                        <p class="text-sm font-semibold">{{ t('field_link.tech_sign', { name: techName }) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('field_link.tech_sign_here') }}</p>
                        <SignaturePad v-model="form.tech_signature" />
                        <InputError :message="errors.tech_signature" />
                    </div>
                    <div class="space-y-1.5">
                        <p class="text-sm font-semibold">{{ t('field_link.customer_sign') }}</p>
                        <template v-if="form.tech_signature">
                            <p class="text-xs text-muted-foreground">{{ t('field_link.sign_here') }}</p>
                            <SignaturePad v-model="form.signature" />
                        </template>
                        <p v-else class="rounded-xl bg-muted/60 p-3 text-sm text-muted-foreground">{{ t('field_link.customer_after_tech') }}</p>
                        <InputError :message="errors.signature" />
                    </div>
                </template>
                <template v-else>
                    <p class="text-xs text-muted-foreground">{{ t('field_link.paper_hint') }}</p>
                    <label :class="[big, 'cursor-pointer border-2 border-dashed text-sm']">
                        <Camera class="size-5" />{{ t('field_link.sheet_photo') }}
                        <input type="file" accept="image/*" capture="environment" class="hidden" @change="addSheet" />
                    </label>
                    <img v-if="sheetPreview" :src="sheetPreview" class="max-h-64 rounded-lg border" alt="" />
                    <InputError :message="errors.signed_sheet" />
                </template>
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <MapPin class="size-3.5" />{{ form.lat ? t('close.location') : t('close.location_hint') }}
                </p>
            </section>

            <!-- Close: always within thumb reach -->
            <div class="fixed inset-x-0 bottom-0 border-t bg-background/95 p-3 backdrop-blur">
                <div class="mx-auto max-w-xl space-y-1">
                    <p v-if="missing.length" class="text-center text-xs text-amber-700 dark:text-amber-400">
                        {{ t('field_link.missing', { items: missing.map((m) => t(`field_link.missing_items.${m}`)).join(', ') }) }}
                    </p>
                    <button
                        type="submit"
                        :disabled="form.processing || !ticket.closable || missing.length > 0"
                        :class="[big, 'bg-primary text-primary-foreground shadow-lg disabled:opacity-50']"
                    >
                        <Check class="size-6" />{{ t('close.submit') }}
                    </button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
