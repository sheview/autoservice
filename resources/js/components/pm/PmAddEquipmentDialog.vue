<script setup lang="ts">
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import InputError from '@/components/InputError.vue';
import type { PmTemplateOption } from '@/components/pm/PmTemplatePicker.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { assetKeyFromScan } from '@/lib/assetQr';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, LoaderCircle, Plus, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Found {
    id: number;
    asset_code: string;
    name: string;
    category: string | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    property_no: string | null;
    location: string | null;
    needs_review: boolean;
}

interface CopySource {
    asset_code: string;
    name: string;
    category_id: number | null;
    brand: string | null;
    model: string | null;
    location: string | null;
    floor: string | null;
    room: string | null;
}

interface Options {
    categories: { id: number; name: string; requires_serial: boolean }[];
    catalog: {
        brands: { id: number; name: string }[];
        models: { id: number; name: string; asset_brand_id: number; asset_category_id: number | null }[];
    };
    templates: PmTemplateOption[];
    categoryTemplates: Record<string, string>;
    copyFrom: CopySource[];
    hasPlan: boolean;
}

/**
 * Equipment found on site that is not on the work order (PmSiteEquipmentController): scan its sticker
 * or serial number, or type anything off it, and put it on when the customer's register has it; else
 * register it here with a short form — copied from a piece already on the work order, so often only
 * the serial number is left to type — and "save and add another of the same model" for a row of them.
 */
const props = defineProps<{ open: boolean; visitUlid: string; initialTerm?: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const step = ref<'find' | 'new'>('find');
const term = ref('');
const searching = ref(false);
const searched = ref(false);
const found = ref<Found[]>([]);
const adding = ref<number | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            step.value = 'find';
            term.value = props.initialTerm ?? '';
            found.value = [];
            searched.value = false;
            if (term.value) search();
        }
    },
);

const getJson = async <T,>(url: string): Promise<T> => {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) throw new Error(String(response.status));
    return (await response.json()) as T;
};

const search = async (text = term.value) => {
    const key = assetKeyFromScan(text);
    const q = (key?.code ?? key?.ulid ?? text).trim();
    term.value = q;
    if (!q) return;
    searching.value = true;
    try {
        found.value = (await getJson<{ found: Found[] }>(route('maintenance.visits.site-equipment.search', { visit: props.visitUlid, q }))).found;
    } catch {
        found.value = [];
    } finally {
        searching.value = false;
        searched.value = true;
    }
};

const addExisting = (asset: Found) => {
    adding.value = asset.id;
    router.post(
        route('maintenance.visits.site-equipment.existing', props.visitUlid),
        { asset_ids: [asset.id] },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onFinish: () => (adding.value = null),
        },
    );
};

// --- The short form of new equipment --------------------------------------------------------
const options = ref<Options | null>(null);
const loadingOptions = ref(false);
const optionsFailed = ref(false);
const form = useForm({
    category_id: null as number | null,
    brand: '',
    model: '',
    serial_number: '',
    property_no: '',
    ip_address: '',
    name: '',
    location: '',
    floor: '',
    room: '',
    used_by: '',
    template_code: '',
    add_to_plan: true as boolean,
    photo: null as File | null,
});
const copyIndex = ref<number | ''>('');
const more = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

const startNew = async () => {
    step.value = 'new';
    form.reset();
    form.clearErrors();
    copyIndex.value = '';
    // what was searched for and not found is most likely its serial number
    if (term.value && searched.value && !found.value.length) form.serial_number = term.value;
    if (options.value) return;
    loadingOptions.value = true;
    optionsFailed.value = false;
    try {
        options.value = await getJson<Options>(route('maintenance.visits.site-equipment.options', props.visitUlid));
        // one piece on the work order of one kind: start from it
        if (options.value.copyFrom.length === 1) copyFrom(0);
    } catch {
        optionsFailed.value = true;
    } finally {
        loadingOptions.value = false;
    }
};

const copyFrom = (index: number | '') => {
    copyIndex.value = index;
    const source = index === '' ? null : options.value?.copyFrom[index];
    if (!source) return;
    form.category_id = source.category_id;
    form.brand = source.brand ?? '';
    form.model = source.model ?? '';
    form.location = source.location ?? '';
    form.floor = source.floor ?? '';
    form.room = source.room ?? '';
};
const copyLabel = (source: CopySource) =>
    [source.name, [source.brand, source.model].filter(Boolean).join(' '), source.room || source.location].filter(Boolean).join(' · ');

const category = computed(() => options.value?.categories.find((c) => c.id === form.category_id) ?? null);
const brandId = computed(() => options.value?.catalog.brands.find((b) => b.name.toLowerCase() === form.brand.trim().toLowerCase())?.id ?? null);
const models = computed(() =>
    (options.value?.catalog.models ?? []).filter(
        (m) =>
            (brandId.value === null || m.asset_brand_id === brandId.value) &&
            (!form.category_id || !m.asset_category_id || m.asset_category_id === form.category_id),
    ),
);
const autoTemplate = computed(() => {
    const code = form.category_id ? options.value?.categoryTemplates[String(form.category_id)] : undefined;
    return code ? (options.value?.templates.find((o) => o.code === code)?.name ?? code) : null;
});

const pickPhoto = (event: Event) => {
    form.photo = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const save = (another: boolean) =>
    form
        .transform((data) => ({ ...data, template_code: data.template_code || null }))
        .post(route('maintenance.visits.site-equipment.store', props.visitUlid), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                if (!another) {
                    emit('update:open', false);
                    return;
                }
                // the same model again: only what is this unit's own is cleared
                form.serial_number = '';
                form.property_no = '';
                form.ip_address = '';
                form.used_by = '';
                form.name = '';
                form.photo = null;
                if (photoInput.value) photoInput.value.value = '';
            },
        });

// A refused equipment number or IP is under "more": open it so the message shows.
watch(
    () => form.errors,
    (errors) => {
        if (errors.property_no || errors.ip_address) more.value = true;
    },
);

// Refusals not tied to a field of the form (the work order is closed, the same device twice).
const otherError = computed(() => {
    const errors = form.errors as Record<string, string>;
    return errors.visit ?? errors.duplicate ?? errors.asset_ids ?? errors.mac_address;
});

const selectClass = 'h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => emit('update:open', value)">
        <DialogContent class="flex max-h-[92dvh] flex-col gap-3 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ step === 'find' ? t('pm_field.add_equipment') : t('pm_field.new_equipment') }}</DialogTitle>
                <DialogDescription>{{ step === 'find' ? t('pm_field.add_equipment_hint') : t('pm_field.new_equipment_hint') }}</DialogDescription>
            </DialogHeader>

            <!-- 1. Look it up in the customer's register -->
            <template v-if="step === 'find'">
                <form class="flex gap-2" @submit.prevent="search()">
                    <Input v-model="term" type="search" enterkeyhint="search" class="h-11 min-w-0 flex-1" :placeholder="t('pm_field.add_search')" />
                    <BarcodeScanButton class="h-11" @scanned="search" />
                    <Button type="submit" class="h-11" :disabled="searching || !term.trim()" :aria-label="t('common.search')">
                        <LoaderCircle v-if="searching" class="h-4 w-4 animate-spin" />
                        <Search v-else class="h-4 w-4" />
                    </Button>
                </form>

                <div class="-mx-1 min-h-0 flex-1 space-y-2 overflow-y-auto px-1">
                    <div v-for="asset in found" :key="asset.id" class="flex items-center gap-3 rounded-md border p-3 text-sm">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ asset.name }}</span>
                            <span class="block truncate font-mono text-xs text-muted-foreground">
                                {{ [asset.asset_code, asset.serial_number, asset.property_no].filter(Boolean).join(' · ') }}
                            </span>
                            <span v-if="asset.location" class="block truncate text-xs text-muted-foreground">{{ asset.location }}</span>
                        </span>
                        <Button size="sm" class="h-10" :disabled="adding !== null" @click="addExisting(asset)">
                            <LoaderCircle v-if="adding === asset.id" class="mr-1 h-4 w-4 animate-spin" />{{ t('pm_field.add_to_visit') }}
                        </Button>
                    </div>
                    <p v-if="searched && !found.length" class="rounded-md bg-muted px-4 py-3 text-sm text-muted-foreground">
                        {{ t('pm_field.not_in_register', { term }) }}
                    </p>
                </div>

                <Button type="button" variant="outline" class="h-12" @click="startNew">
                    <Plus class="mr-1.5 h-4 w-4" />{{ t('pm_field.register_new') }}
                </Button>
            </template>

            <!-- 2. Register it with a short form -->
            <form v-else class="-mx-1 min-h-0 flex-1 space-y-3 overflow-y-auto px-1" @submit.prevent="save(false)">
                <button type="button" class="inline-flex items-center text-sm text-primary" @click="step = 'find'">
                    <ChevronLeft class="h-4 w-4" />{{ t('pm_field.back_to_search') }}
                </button>
                <p v-if="loadingOptions" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <LoaderCircle class="h-4 w-4 animate-spin" />{{ t('pm_field.loading') }}
                </p>
                <p v-else-if="optionsFailed" class="text-sm text-destructive">{{ t('pm_field.row_failed') }}</p>

                <template v-if="options">
                    <div v-if="options.copyFrom.length" class="space-y-1">
                        <Label for="copy_from">{{ t('pm_field.copy_from') }}</Label>
                        <select
                            id="copy_from"
                            :value="copyIndex"
                            :class="selectClass"
                            @change="
                                copyFrom(($event.target as HTMLSelectElement).value === '' ? '' : Number(($event.target as HTMLSelectElement).value))
                            "
                        >
                            <option value="">{{ t('pm_field.copy_none') }}</option>
                            <option v-for="(source, index) in options.copyFrom" :key="index" :value="index">{{ copyLabel(source) }}</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <Label for="category_id">{{ t('assets.category') }} *</Label>
                        <select id="category_id" v-model="form.category_id" :class="selectClass" required>
                            <option :value="null" disabled>{{ t('pm_field.pick_category') }}</option>
                            <option v-for="c in options.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <InputError :message="form.errors.category_id" />
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="space-y-1">
                            <Label for="brand">{{ t('assets.brand') }}</Label>
                            <Input id="brand" v-model="form.brand" class="h-11" list="pm-add-brands" autocomplete="off" />
                            <datalist id="pm-add-brands">
                                <option v-for="b in options.catalog.brands" :key="b.id" :value="b.name" />
                            </datalist>
                        </div>
                        <div class="space-y-1">
                            <Label for="model">{{ t('assets.model') }}</Label>
                            <Input id="model" v-model="form.model" class="h-11" list="pm-add-models" autocomplete="off" />
                            <datalist id="pm-add-models">
                                <option v-for="m in models" :key="m.id" :value="m.name" />
                            </datalist>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="serial_number">{{ t('assets.serial_number') }}{{ category?.requires_serial ? ' *' : '' }}</Label>
                        <div class="flex gap-2">
                            <Input id="serial_number" v-model="form.serial_number" class="h-11 min-w-0 flex-1 font-mono" autocomplete="off" />
                            <BarcodeScanButton class="h-11" @scanned="(text) => (form.serial_number = text.trim())" />
                        </div>
                        <InputError :message="form.errors.serial_number" />
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-3 space-y-1">
                            <Label for="location">{{ t('assets.location') }}</Label>
                            <Input id="location" v-model="form.location" class="h-11" />
                        </div>
                        <div class="space-y-1">
                            <Label for="floor">{{ t('pm_field.floor_label') }}</Label>
                            <Input id="floor" v-model="form.floor" class="h-11" />
                        </div>
                        <div class="col-span-2 space-y-1">
                            <Label for="room">{{ t('pm_field.room_label') }}</Label>
                            <Input id="room" v-model="form.room" class="h-11" />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="photo">{{ t('pm_field.label_photo') }}</Label>
                        <input
                            id="photo"
                            ref="photoInput"
                            type="file"
                            accept="image/*"
                            capture="environment"
                            class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2"
                            @change="pickPhoto"
                        />
                        <InputError :message="form.errors.photo" />
                    </div>

                    <button type="button" class="text-sm text-primary underline-offset-4 hover:underline" @click="more = !more">
                        {{ more ? t('pm_field.less_fields') : t('pm_field.more_fields') }}
                    </button>
                    <div v-if="more" class="grid gap-2 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="property_no">{{ t('assets.property_no') }}</Label>
                            <Input id="property_no" v-model="form.property_no" class="h-11" />
                            <InputError :message="form.errors.property_no" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ip_address">{{ t('assets.ip_address') }}</Label>
                            <Input id="ip_address" v-model="form.ip_address" class="h-11" inputmode="decimal" />
                            <InputError :message="form.errors.ip_address" />
                        </div>
                        <div class="space-y-1">
                            <Label for="used_by">{{ t('assets.used_by') }}</Label>
                            <Input id="used_by" v-model="form.used_by" class="h-11" />
                        </div>
                        <div class="space-y-1">
                            <Label for="name">{{ t('assets.name') }}</Label>
                            <Input id="name" v-model="form.name" class="h-11" :placeholder="t('pm_field.name_auto')" />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="template_code">{{ t('pm_visits.template') }}</Label>
                        <select id="template_code" v-model="form.template_code" :class="selectClass">
                            <option value="">
                                {{ autoTemplate ? t('pm_field.template_auto', { name: autoTemplate }) : t('pm_field.template_auto_none') }}
                            </option>
                            <option v-for="o in options.templates" :key="o.code" :value="o.code">{{ o.name }}</option>
                        </select>
                        <InputError :message="form.errors.template_code" />
                    </div>

                    <label v-if="options.hasPlan" class="flex items-start gap-2 text-sm">
                        <input v-model="form.add_to_plan" type="checkbox" class="mt-0.5 size-4 rounded border-input" />
                        {{ t('pm_field.add_to_plan') }}
                    </label>

                    <InputError :message="otherError" />
                    <p class="text-xs text-muted-foreground">{{ t('pm_field.review_note') }}</p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <Button type="submit" class="h-12" :disabled="form.processing || !form.category_id">
                            <LoaderCircle v-if="form.processing" class="mr-1 h-4 w-4 animate-spin" />{{ t('pm_field.save_equipment') }}
                        </Button>
                        <Button type="button" variant="outline" class="h-12" :disabled="form.processing || !form.category_id" @click="save(true)">
                            {{ t('pm_field.save_and_another') }}
                        </Button>
                    </div>
                </template>
            </form>
        </DialogContent>
    </Dialog>
</template>
