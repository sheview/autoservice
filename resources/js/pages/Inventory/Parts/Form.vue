<script setup lang="ts">
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import DuplicateMatches, { type DuplicateMatch } from '@/components/DuplicateMatches.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import QuickPicks from '@/components/QuickPicks.vue';
import SerialInputs from '@/components/SerialInputs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDuplicates } from '@/composables/useDuplicates';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { pendingPhotos } from '@/lib/photoUpload';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

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
    asset_category_id: number | null;
    storage_location_id: number | null;
    track_serial: boolean;
}

// contracts: the MA contracts (projects) a part can be kept for; none = the field is hidden.
// categories: the asset categories, shared with parts (a new part starts tracked by serial as its category says).
const props = defineProps<{
    part: PartForm | null;
    contracts: { id: number; label: string }[];
    categories: { id: number; name: string; requires_serial: boolean }[];
    // Storage locations (Settings), shared with assets.
    locations: { id: number; name: string }[];
    canSerials: boolean;
    // The most used units and brands, as buttons under their fields.
    picks: { units: string[]; brands: string[] };
    // Creating only: the first stock is received with the part (stock-movements.create).
    canReceive?: boolean;
    maxInitialSerials?: number;
}>();

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
    asset_category_id: props.part?.asset_category_id ?? (null as number | null),
    storage_location_id: props.part?.storage_location_id ?? (null as number | null),
    track_serial: props.part?.track_serial ?? false,
    // Creating only (CreatePart): a count, or the serial numbers of a part tracked by serial.
    initial_qty: 0 as number | string,
    initial_serials: [] as string[],
});

// The same part already there (same name or part number): saving is refused, so offer it instead.
type PartDuplicate = {
    id: number;
    code: string;
    name: string;
    part_number: string | null;
    brand: string | null;
    unit: string;
    qty_on_hand: number;
    reasons: string[];
    // The same part (saving is refused), or one that may be it (a hint).
    blocking: boolean;
    url: string;
};
const duplicates = useDuplicates<PartDuplicate>('inventory.parts.duplicates', () => ({
    name: form.name,
    part_number: form.part_number,
    except: props.part?.id,
}));
const duplicateMatches = computed<DuplicateMatch[]>(() =>
    duplicates.value.map((part) => ({
        key: String(part.id),
        title: `${part.code} ${part.name}`,
        detail: [part.part_number, part.brand, t('duplicates.stock', { qty: part.qty_on_hand, unit: part.unit })].filter(Boolean).join(' · '),
        reasons: part.reasons,
        blocking: part.blocking,
        actions: [
            ...(props.part ? [] : [{ label: t('duplicates.receive'), href: `${part.url}#stock-form` }]),
            { label: t('duplicates.open'), href: part.url },
        ],
    })),
);

// Photos picked before the part exists (PhotoPicker): uploaded by its page once it is saved
// (PhotoSlots pending-key), so a failed photo never loses the part.
const photos = ref<File[]>([]);

// A new part takes its category's setting until it is changed here.
watch(
    () => form.asset_category_id,
    (id) => {
        if (!props.part) form.track_serial = props.categories.find((c) => c.id === id)?.requires_serial ?? false;
    },
);

const submit = () => {
    if (props.part) {
        form.transform(({ initial_qty, initial_serials, ...data }) => data) // eslint-disable-line @typescript-eslint/no-unused-vars
            .put(route('inventory.parts.update', props.part.id));
        return;
    }
    form.transform(({ initial_qty, initial_serials, ...data }) =>
        // One of the two, as the part will be followed.
        data.track_serial ? { ...data, initial_serials: initial_serials.filter((serial) => serial.trim() !== '') } : { ...data, initial_qty },
    ).post(route('inventory.parts.store'), {
        onSuccess: (page) => {
            const id = (page.props as { part?: { id: number } }).part?.id;
            if (id && photos.value.length) pendingPhotos.value = { key: `part:${id}`, files: photos.value };
        },
    });
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
                        <Label for="code" :required="!!part">{{ t('parts.code') }}</Label>
                        <Input
                            id="code"
                            v-model="form.code"
                            class="font-mono uppercase"
                            :required="!!part"
                            maxlength="30"
                            autocomplete="off"
                            :placeholder="part ? '' : t('parts.code_auto')"
                        />
                        <p class="text-xs text-muted-foreground">{{ part ? t('parts.code_hint') : t('parts.code_auto_hint') }}</p>
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="name" required>{{ t('parts.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <!-- Already there (name or part number): use that part instead of typing it again -->
                    <DuplicateMatches v-if="duplicateMatches.length" class="sm:col-span-3" :matches="duplicateMatches" />

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
                        <Label for="asset_category_id">{{ t('parts.category') }}</Label>
                        <select
                            id="asset_category_id"
                            v-model="form.asset_category_id"
                            class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null">{{ t('parts.no_category') }}</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <InputError :message="form.errors.asset_category_id" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="storage_location_id">{{ t('storage_locations.field') }}</Label>
                        <select
                            id="storage_location_id"
                            v-model="form.storage_location_id"
                            class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null">{{ t('storage_locations.no_location') }}</option>
                            <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                        </select>
                        <InputError :message="form.errors.storage_location_id" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <span class="text-sm font-medium">{{ t('parts.track_serial') }}</span>
                        <label v-if="!part && canSerials" class="flex items-center gap-2 text-sm">
                            <input v-model="form.track_serial" type="checkbox" class="size-4 rounded border-input" />
                            {{ t('parts.track_serial_on') }}
                        </label>
                        <p v-else class="text-sm">
                            {{ (part ? part.track_serial : form.track_serial) ? t('parts.track_serial_on') : t('parts.track_serial_off') }}
                        </p>
                        <p class="text-xs text-muted-foreground">{{ part ? t('parts.track_serial_on_page') : t('parts.track_serial_hint') }}</p>
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="brand">{{ t('parts.brand') }}</Label>
                        <Input id="brand" v-model="form.brand" maxlength="100" />
                        <QuickPicks v-model="form.brand" :options="picks.brands" />
                        <InputError :message="form.errors.brand" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="part_number">{{ t('parts.part_number') }}</Label>
                        <div class="flex gap-2">
                            <Input id="part_number" v-model="form.part_number" class="font-mono" maxlength="100" />
                            <BarcodeScanButton
                                :hint="t('scan_camera.hint_field', { field: t('parts.part_number') })"
                                @scanned="(text) => (form.part_number = text)"
                            />
                        </div>
                        <InputError :message="form.errors.part_number" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="unit" required>{{ t('parts.unit') }}</Label>
                        <Input id="unit" v-model="form.unit" required maxlength="30" :placeholder="t('parts.unit_placeholder')" />
                        <QuickPicks v-model="form.unit" :options="picks.units" />
                        <InputError :message="form.errors.unit" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="min_qty">{{ t('parts.min_qty') }}</Label>
                        <Input id="min_qty" v-model="form.min_qty" type="number" inputmode="numeric" min="0" step="1" />
                        <InputError :message="form.errors.min_qty" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="unit_cost">{{ t('parts.unit_cost') }}</Label>
                        <Input id="unit_cost" v-model="form.unit_cost" type="number" inputmode="decimal" min="0" step="0.01" />
                        <InputError :message="form.errors.unit_cost" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('parts.min_qty_hint') }} · {{ t('parts.unit_cost_hint') }}</p>

                <!-- Creating only: the first stock, received together with the part (one transaction) -->
                <section v-if="!part && canReceive" class="space-y-2 rounded-md border p-4">
                    <h3 class="text-sm font-semibold">{{ t('parts.initial_title') }}</h3>
                    <template v-if="form.track_serial">
                        <p class="text-xs text-muted-foreground">{{ t('parts.initial_serials_hint') }}</p>
                        <SerialInputs id="initial_serials" v-model="form.initial_serials" />
                        <p class="text-xs text-muted-foreground">
                            {{ t('parts.initial_serials_count', { count: form.initial_serials.filter((s) => s.trim() !== '').length }) }}
                        </p>
                        <InputError
                            :message="form.errors.initial_serials ?? Object.entries(form.errors).find(([k]) => k.startsWith('initial_serials.'))?.[1]"
                        />
                    </template>
                    <div v-else class="grid max-w-xs content-start gap-2">
                        <Label for="initial_qty">{{ t('parts.initial_qty') }}</Label>
                        <Input id="initial_qty" v-model="form.initial_qty" type="number" inputmode="numeric" min="0" step="1" />
                        <InputError :message="form.errors.initial_qty" />
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('parts.initial_hint') }}</p>
                </section>

                <div class="grid content-start gap-2">
                    <Label for="notes">{{ t('parts.notes') }}</Label>
                    <textarea id="notes" v-model="form.notes" rows="3" :class="textareaClass" />
                    <InputError :message="form.errors.notes" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                    {{ t('parts.is_active') }}
                </label>

                <!-- Creating only: photos last, taken once the form is filled in; sent once the part is saved -->
                <section v-if="!part" class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('photos.title') }}</h3>
                    <PhotoPicker v-model="photos" />
                </section>

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
