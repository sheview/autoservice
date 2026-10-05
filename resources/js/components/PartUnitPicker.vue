<script setup lang="ts">
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import { t } from '@/lib/i18n';
import type { PartUnitRow } from '@/types/inventory';
import { X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Chooses pieces of a part tracked by serial number: those in stock to issue, or (status
 * "issued", narrowed to a ticket or request line) those to take back. Searched by serial on the
 * server; a piece in another status is never offered. need = how many to choose, when fixed.
 */
const props = defineProps<{
    partId: number;
    status?: 'in_stock' | 'issued';
    ticketId?: number | null;
    checkoutItemId?: number | null;
    need?: number | null;
}>();
const model = defineModel<number[]>({ required: true });

const q = ref('');
const options = ref<PartUnitRow[]>([]);
const chosen = ref<Record<number, string>>({});
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const load = async () => {
    loading.value = true;
    const params: Record<string, string | number> = { status: props.status ?? 'in_stock' };
    if (q.value.trim()) params.q = q.value.trim();
    if (props.ticketId) params.ticket_id = props.ticketId;
    if (props.checkoutItemId) params.checkout_item_id = props.checkoutItemId;
    try {
        const response = await fetch(route('inventory.parts.units.options', { part: props.partId, ...params }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        options.value = response.ok ? await response.json() : [];
    } finally {
        loading.value = false;
    }
};

watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
});
watch(() => [props.partId, props.status, props.ticketId, props.checkoutItemId], load, { immediate: true });
// A new part (or a cleared form) starts with nothing chosen.
watch(
    () => props.partId,
    () => {
        model.value = [];
        chosen.value = {};
    },
);

const full = computed(() => props.need != null && model.value.length >= props.need);
const toggle = (unit: PartUnitRow) => {
    if (model.value.includes(unit.id)) {
        model.value = model.value.filter((id) => id !== unit.id);
        delete chosen.value[unit.id];
    } else if (!full.value) {
        model.value = [...model.value, unit.id];
        chosen.value[unit.id] = unit.serial_number;
    }
};

/** Chooses the piece whose serial is exactly the text (a scanned code), when it is offered. */
const notFound = ref('');
const pickSerial = async (serial: string) => {
    q.value = serial;
    clearTimeout(timer);
    await load();
    const unit = options.value.find((u) => u.serial_number.toLowerCase() === serial.trim().toLowerCase());
    notFound.value = unit ? '' : serial;
    if (unit && !model.value.includes(unit.id)) toggle(unit);
    if (unit) q.value = '';
    return unit !== undefined;
};
defineExpose({ pickSerial });

const onEnter = () => {
    if (q.value.trim()) pickSerial(q.value);
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-sm font-medium">{{ t('part_units.pick') }}</span>
            <span class="text-xs" :class="need != null && model.length !== need ? 'text-amber-700' : 'text-muted-foreground'">
                {{ need != null ? t('part_units.picked_of', { count: model.length, need }) : t('part_units.picked', { count: model.length }) }}
            </span>
        </div>

        <div v-if="model.length" class="flex flex-wrap gap-1.5">
            <button
                v-for="id in model"
                :key="id"
                type="button"
                class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 font-mono text-xs"
                @click="toggle({ id, serial_number: chosen[id] ?? '' } as PartUnitRow)"
            >
                {{ chosen[id] ?? `#${id}` }} <X class="size-3" />
            </button>
        </div>

        <div class="flex gap-2">
            <input
                v-model="q"
                type="search"
                :placeholder="t('part_units.search')"
                class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 font-mono text-sm"
                @keydown.enter.prevent="onEnter"
            />
            <BarcodeScanButton @scanned="pickSerial" />
        </div>

        <p v-if="notFound" class="text-xs text-red-700 dark:text-red-400">{{ t('part_units.scan_not_found', { serial: notFound }) }}</p>
        <div class="max-h-56 overflow-y-auto rounded-md border">
            <p v-if="loading && !options.length" class="px-3 py-2 text-sm text-muted-foreground">{{ t('part_units.loading') }}</p>
            <p v-else-if="!options.length" class="px-3 py-2 text-sm text-muted-foreground">{{ t('part_units.no_options') }}</p>
            <label
                v-for="unit in options"
                :key="unit.id"
                class="flex cursor-pointer items-center gap-3 border-b px-3 py-2 text-sm last:border-b-0 hover:bg-muted/50"
                :class="{ 'opacity-50': full && !model.includes(unit.id) }"
            >
                <input
                    type="checkbox"
                    class="size-4 rounded border-input"
                    :checked="model.includes(unit.id)"
                    :disabled="full && !model.includes(unit.id)"
                    @change="toggle(unit)"
                />
                <span class="font-mono">{{ unit.serial_number }}</span>
                <span class="ml-auto text-xs text-muted-foreground">
                    {{ unit.received_on }}<template v-if="unit.warranty_until"> · {{ t('part_units.warranty_until') }} {{ unit.warranty_until }}</template>
                </span>
            </label>
        </div>
        <p class="text-xs text-muted-foreground">{{ status === 'issued' ? t('part_units.pick_return_hint') : t('part_units.pick_hint') }}</p>
    </div>
</template>
