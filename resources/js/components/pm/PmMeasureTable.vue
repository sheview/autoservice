<script setup lang="ts">
import { t } from '@/lib/i18n';
import { cellRange, outOfRange, rangeText, tableRows, type MeasureTableAnswer, type TemplateField } from '@/lib/pmTemplate';
import { computed } from 'vue';

/**
 * A measure table (rows × columns of readings): inputs while recording, values when viewing.
 * A reading out of its range is red; the recommended range shows per row or per column.
 */
const props = defineProps<{ field: TemplateField; mode: 'edit' | 'view'; idPrefix: string }>();
const model = defineModel<MeasureTableAnswer | null>({ required: true });

const rows = computed(() => tableRows(props.field));
const columns = computed(() => props.field.columns ?? []);
const rowRanges = computed(() => rows.value.some((row) => row.min != null || row.max != null));
const columnRanges = computed(() => columns.value.some((column) => column.min != null || column.max != null));

const value = (row: string, column: string) => model.value?.[row]?.[column] ?? null;
const set = (row: string, column: string, input: string) => {
    const table: MeasureTableAnswer = { ...(model.value ?? {}) };
    table[row] = { ...(table[row] ?? {}), [column]: input === '' ? null : input };
    model.value = table;
};
const red = (rowIndex: number, columnIndex: number) => {
    const row = rows.value[rowIndex];
    const column = columns.value[columnIndex];
    const [min, max] = cellRange(row, column);
    return outOfRange(value(row.key, column.key), min, max);
};
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-max border-collapse text-sm">
            <thead>
                <tr class="text-xs text-muted-foreground">
                    <th class="border px-2 py-1 text-left"></th>
                    <th v-for="column in columns" :key="column.key" class="border px-2 py-1 font-medium">{{ column.label }}</th>
                    <th v-if="rowRanges" class="border px-2 py-1 font-medium">{{ t('pm_checklists.recommend') }}</th>
                </tr>
                <tr v-if="columnRanges" class="text-xs text-muted-foreground">
                    <td class="border px-2 py-1">{{ t('pm_checklists.recommend') }}</td>
                    <td v-for="column in columns" :key="column.key" class="border px-2 py-1 text-center">{{ rangeText(column.min, column.max) }}</td>
                    <td v-if="rowRanges" class="border"></td>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, r) in rows" :key="row.key">
                    <td class="border px-2 py-1">
                        {{ row.label }}<span v-if="row.unit" class="text-muted-foreground"> ({{ row.unit }})</span>
                    </td>
                    <td
                        v-for="(column, c) in columns"
                        :key="column.key"
                        class="border p-0.5 text-center"
                        :class="{ 'bg-red-50 font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-300': red(r, c) }"
                    >
                        <input
                            v-if="mode === 'edit'"
                            :id="`${idPrefix}_${row.key}_${column.key}`"
                            :value="value(row.key, column.key) ?? ''"
                            type="number"
                            step="any"
                            inputmode="decimal"
                            class="h-9 w-20 rounded border border-input bg-transparent px-2 text-center"
                            :class="{ 'border-red-500': red(r, c) }"
                            :aria-label="`${row.label} ${column.label}`"
                            @input="set(row.key, column.key, ($event.target as HTMLInputElement).value)"
                        />
                        <span v-else>{{ value(row.key, column.key) ?? '' }}</span>
                    </td>
                    <td v-if="rowRanges" class="border px-2 py-1 text-center text-xs text-muted-foreground">
                        {{ rangeText(row.min, row.max) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
