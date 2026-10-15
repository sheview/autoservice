<script setup lang="ts">
import PmMeasureTable from '@/components/pm/PmMeasureTable.vue';
import PmRepeatTable from '@/components/pm/PmRepeatTable.vue';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import {
    outOfRange,
    PASS_FAIL,
    rangeText,
    repeatRowCount,
    type Answer,
    type MeasureTableAnswer,
    type RepeatRow,
    type TemplateField,
} from '@/lib/pmTemplate';
import { computed } from 'vue';

/**
 * One field of a PM template, to fill in (mode "edit") or to read (mode "view"). The same schema
 * prints the report (resources/views/documents/partials/pm-field.blade.php).
 * answers = every answer of the item (a repeat table counts its rows from another field);
 * asset = the register values a readonly_from_asset field shows while recording.
 */
const props = defineProps<{
    field: TemplateField;
    mode: 'edit' | 'view';
    answers: Record<string, Answer>;
    idPrefix: string;
    asset?: Record<string, string | null> | null;
}>();
const model = defineModel<Answer>({ required: true });

const id = computed(() => `${props.idPrefix}_${props.field.key}`);
const isTable = computed(() => props.field.type === 'measure_table' || props.field.type === 'repeat_table');
const red = computed(
    () =>
        (props.field.type === 'pass_fail' && model.value === 'fail') ||
        ((props.field.type === 'number' || props.field.type === 'measure') && outOfRange(model.value, props.field.min, props.field.max)),
);
const range = computed(() =>
    props.field.type === 'number' || props.field.type === 'measure' ? rangeText(props.field.min, props.field.max, props.field.unit) : '',
);

const passFailClass: Record<string, string> = {
    pass: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    fail: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    na: 'bg-muted text-muted-foreground',
};

const toggleOption = (option: string) => {
    const chosen = Array.isArray(model.value) ? [...(model.value as string[])] : [];
    model.value = chosen.includes(option) ? chosen.filter((o) => o !== option) : [...chosen, option];
};

// What a field shows when read (view mode, and readonly_from_asset while recording).
const shown = computed(() => {
    const value =
        props.field.type === 'readonly_from_asset' && props.mode === 'edit' ? (props.asset?.[props.field.source ?? ''] ?? null) : model.value;
    if (props.field.type === 'check') return value ? t('pm_visits.yes') : t('pm_visits.no');
    if (props.field.type === 'pass_fail') return value ? t(`pm_visits.pass_fail.${value}`) : t('common.none');
    if (Array.isArray(value)) return value.length ? value.join(', ') : t('common.none');
    if (value === null || value === undefined || value === '') return t('common.none');
    return props.field.unit && (props.field.type === 'number' || props.field.type === 'measure') ? `${value} ${props.field.unit}` : String(value);
});

const selectClass = 'h-10 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <div class="grid gap-1.5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <label v-if="field.type === 'check' && mode === 'edit'" class="flex items-center gap-2 text-sm">
                <input v-model="model" type="checkbox" class="size-5 rounded border-input" />
                {{ field.label }}<span v-if="field.required" class="text-red-600">*</span>
            </label>
            <label v-else :for="id" class="text-sm" :class="{ 'font-medium': isTable }">
                {{ field.label }}<span v-if="field.required && mode === 'edit'" class="text-red-600">*</span>
                <span v-if="range" class="ml-1 text-xs text-muted-foreground">({{ t('pm_visits.range') }} {{ range }})</span>
            </label>

            <template v-if="mode === 'edit'">
                <!-- ปกติ / ผิดปกติ / N/A: large buttons for a finger -->
                <div v-if="field.type === 'pass_fail'" class="inline-flex overflow-hidden rounded-md border text-sm" role="radiogroup">
                    <button
                        v-for="option in PASS_FAIL"
                        :key="option"
                        type="button"
                        class="min-w-16 px-3 py-2"
                        :class="model === option ? passFailClass[option] + ' font-semibold' : 'hover:bg-muted'"
                        :aria-pressed="model === option"
                        @click="model = model === option ? null : option"
                    >
                        {{ t(`pm_visits.pass_fail.${option}`) }}
                    </button>
                </div>
                <div v-else-if="field.type === 'number' || field.type === 'measure'" class="flex items-center gap-2">
                    <Input
                        :id="id"
                        v-model="model as string"
                        type="number"
                        step="any"
                        inputmode="decimal"
                        class="w-32"
                        :class="{ 'border-red-500 text-red-700 dark:text-red-300': red }"
                    />
                    <span v-if="field.unit" class="text-sm text-muted-foreground">{{ field.unit }}</span>
                </div>
                <select v-else-if="field.type === 'select'" :id="id" v-model="model" :class="selectClass">
                    <option :value="null"></option>
                    <option v-for="option in field.options ?? []" :key="option" :value="option">{{ option }}</option>
                </select>
                <span v-else-if="field.type === 'readonly_from_asset'" class="text-sm">{{ shown }}</span>
            </template>
            <span v-else-if="!isTable && field.type !== 'photo'" class="text-sm" :class="{ 'font-semibold text-red-700 dark:text-red-300': red }">
                {{ shown }}
            </span>
        </div>

        <template v-if="mode === 'edit'">
            <Input v-if="field.type === 'text'" :id="id" v-model="model as string" />
            <textarea
                v-else-if="field.type === 'textarea'"
                :id="id"
                v-model="model as string"
                rows="3"
                class="shadow-xs rounded-md border border-input bg-transparent px-3 py-2 text-sm"
            />
            <div v-else-if="field.type === 'checkbox_group'" class="flex flex-wrap gap-x-4 gap-y-2">
                <label v-for="option in field.options ?? []" :key="option" class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        class="size-5 rounded border-input"
                        :checked="Array.isArray(model) && (model as string[]).includes(option)"
                        @change="toggleOption(option)"
                    />
                    {{ option }}
                </label>
            </div>
        </template>

        <PmMeasureTable
            v-if="field.type === 'measure_table'"
            v-model="model as MeasureTableAnswer | null"
            :field="field"
            :mode="mode"
            :id-prefix="id"
        />
        <PmRepeatTable
            v-else-if="field.type === 'repeat_table'"
            v-model="model as RepeatRow[] | null"
            :field="field"
            :mode="mode"
            :id-prefix="id"
            :row-count="repeatRowCount(field, answers)"
        />
    </div>
</template>
