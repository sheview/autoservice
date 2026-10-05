<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PartUnitPicker from '@/components/PartUnitPicker.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, Check, Eraser, MapPin, Minus, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

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
    unit_ids: number[];
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
    signatureRequired: boolean;
    canIssueParts: boolean;
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
    approver_name: props.ticket.contact_name ?? '',
    signature: null as string | null,
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
    if (line && !part.track_serial) line.qty++;
    else if (!line) form.parts.push({ part_id: part.id, qty: part.track_serial ? 0 : 1, unit_ids: [] });
    partSearch.value = '';
};
const short = (line: PartLine) => line.qty > (chosen.value[line.part_id]?.qty_on_hand ?? 0);
const anyShort = computed(() => form.parts.some(short));
// A part followed by serial number counts the pieces chosen (scanned or ticked).
const tracked = (line: PartLine) => chosen.value[line.part_id]?.track_serial ?? false;
const setPieces = (line: PartLine, ids: number[]) => {
    line.unit_ids = ids;
    line.qty = ids.length;
};
const nonePicked = computed(() => form.parts.some((line) => tracked(line) && line.unit_ids.length === 0));

// --- 3b. pieces taken out of the device (a note only, not stock) ---
const addRemoved = () => form.removed.push({ item_name: '', serial_number: '', problem: '', disposition: props.dispositions[0] });

// --- 4. signature on the screen ---
const pad = ref<HTMLCanvasElement | null>(null);
let drawing = false;
const point = (e: PointerEvent) => {
    const r = pad.value!.getBoundingClientRect();
    return [((e.clientX - r.left) * pad.value!.width) / r.width, ((e.clientY - r.top) * pad.value!.height) / r.height];
};
const down = (e: PointerEvent) => {
    drawing = true;
    const ctx = pad.value!.getContext('2d')!;
    ctx.beginPath();
    ctx.moveTo(...(point(e) as [number, number]));
    pad.value!.setPointerCapture(e.pointerId);
};
const moveTo = (e: PointerEvent) => {
    if (!drawing) return;
    const ctx = pad.value!.getContext('2d')!;
    ctx.lineTo(...(point(e) as [number, number]));
    ctx.stroke();
};
const up = () => {
    if (!drawing) return;
    drawing = false;
    form.signature = pad.value!.toDataURL('image/png');
};
const clearPad = () => {
    const ctx = pad.value!.getContext('2d')!;
    ctx.clearRect(0, 0, pad.value!.width, pad.value!.height);
    form.signature = null;
};
onMounted(async () => {
    await nextTick();
    const ctx = pad.value?.getContext('2d');
    if (ctx) {
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111827';
    }
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

const submit = () => form.post(route('service.tickets.close.store', props.ticket.ulid), { forceFormData: true, preserveScroll: true });

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

            <p v-if="!ticket.closable" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                {{ t('close.not_closable') }}
                <Link :href="route('service.tickets.show', ticket.ulid)" class="font-semibold underline">{{ t('close.back') }}</Link>
            </p>
            <InputError :message="errors.ticket" />

            <!-- 1. What was wrong, what fixed it -->
            <section class="space-y-4">
                <h2 class="text-lg font-semibold">{{ t('close.step_what') }}</h2>
                <div v-for="kind in ['symptom', 'solution'] as const" :key="kind" class="space-y-2">
                    <div class="text-sm font-semibold">{{ t(kind === 'symptom' ? 'close.symptoms' : 'close.solutions') }}</div>
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
                <InputError :message="errors.symptoms" />
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
            <section v-if="canIssueParts" class="space-y-3">
                <h2 class="text-lg font-semibold">{{ t('close.step_parts') }}</h2>
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
                    <li v-if="tracked(line)" class="space-y-2 rounded-xl border p-3">
                        <div class="flex items-center gap-2">
                            <span class="min-w-0 flex-1 text-sm font-medium">
                                {{ chosen[line.part_id]?.name }}
                                <span class="ml-1 rounded bg-primary/10 px-1 font-mono text-xs text-primary">{{ t('parts.sn_badge') }}</span>
                            </span>
                            <button type="button" class="size-12 rounded-xl border" :aria-label="t('common.delete')" @click="form.parts.splice(i, 1)">
                                <Trash2 class="mx-auto size-4" />
                            </button>
                        </div>
                        <PartUnitPicker :model-value="line.unit_ids" :part-id="line.part_id" @update:model-value="(ids) => setPieces(line, ids)" />
                    </li>
                    <li
                        v-else
                        class="flex items-center gap-2 rounded-xl border p-2"
                        :class="short(line) ? 'border-red-400 bg-red-50 dark:bg-red-950/40' : ''"
                    >
                        <span class="min-w-0 flex-1 text-sm">
                            {{ chosen[line.part_id]?.name }}
                            <span class="block text-xs" :class="short(line) ? 'font-semibold text-red-700' : 'text-muted-foreground'">
                                {{ t('close.in_stock', { qty: chosen[line.part_id]?.qty_on_hand ?? 0, unit: chosen[line.part_id]?.unit ?? '' }) }}
                                <template v-if="short(line)"> · {{ t('close.not_enough') }}</template>
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
                        <button type="button" class="size-12 shrink-0 rounded-xl border" :aria-label="t('common.delete')" @click="form.removed.splice(i, 1)">
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

            <!-- 4. Signature / who took the job back -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">{{ signatureRequired ? t('close.step_sign') : t('close.step_sign_optional') }}</h2>
                <label class="block space-y-1.5 text-sm">
                    <span class="font-semibold"
                        >{{ signatureRequired ? t('close.signer') : t('close.approver') }} <span class="text-red-600">*</span></span
                    >
                    <input v-if="signatureRequired" v-model="form.signer_name" :class="input" required />
                    <input v-else v-model="form.approver_name" :class="input" required />
                </label>
                <template v-if="signatureRequired">
                    <div class="text-sm text-muted-foreground">{{ t('close.sign_here') }}</div>
                    <canvas
                        ref="pad"
                        width="800"
                        height="300"
                        class="h-40 w-full touch-none rounded-xl border-2 border-dashed bg-white"
                        @pointerdown="down"
                        @pointermove="moveTo"
                        @pointerup="up"
                        @pointerleave="up"
                    />
                    <button type="button" class="flex min-h-12 items-center gap-2 text-sm text-muted-foreground" @click="clearPad">
                        <Eraser class="size-4" />{{ t('close.clear') }}
                    </button>
                </template>
                <InputError :message="errors.signature || errors.approver_name" />
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <MapPin class="size-3.5" />{{ form.lat ? t('close.location') : t('close.location_hint') }}
                </p>
            </section>

            <!-- Close: always within thumb reach -->
            <div class="fixed inset-x-0 bottom-0 border-t bg-background/95 p-3 backdrop-blur">
                <div class="mx-auto max-w-xl">
                    <button
                        type="submit"
                        :disabled="form.processing || !ticket.closable || anyShort || nonePicked || (signatureRequired && !form.signature)"
                        :class="[big, 'bg-primary text-primary-foreground shadow-lg disabled:opacity-50']"
                    >
                        <Check class="size-6" />{{ t('close.submit') }}
                    </button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
