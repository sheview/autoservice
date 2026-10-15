<script setup lang="ts">
import { t } from '@/lib/i18n';
import { outOfRange, PASS_FAIL, rangeText, type RepeatColumn, type RepeatRow, type TemplateField } from '@/lib/pmTemplate';
import { computed } from 'vue';

/**
 * A table of repeated rows (a battery block per row, a camera per row). How many rows it has comes
 * from the template: fixed, or the number typed in another field (rowCount).
 */
const props = defineProps<{ field: TemplateField; mode: 'edit' | 'view'; idPrefix: string; rowCount: number }>();
const model = defineModel<RepeatRow[] | null>({ required: true });

const columns = computed(() => (props.field.columns ?? []) as RepeatColumn[]);
const shown = computed(() => (props.mode === 'edit' ? props.rowCount : (model.value ?? []).length));

const value = (index: number, column: string) => model.value?.[index]?.[column] ?? null;
const set = (index: number, column: string, input: string | null) => {
    const rows = [...(model.value ?? [])];
    while (rows.length <= index) rows.push({});
    rows[index] = { ...rows[index], [column]: input === '' ? null : input };
    model.value = rows.slice(0, Math.max(props.rowCount, 0));
};
const red = (index: number, column: RepeatColumn) => {
    const v = value(index, column.key);
    return column.type === 'pass_fail' ? v === 'fail' : column.type === 'number' && outOfRange(v, column.min, column.max);
};
</script>

<template>
    <p v-if="shown === 0" class="text-xs text-muted-foreground">{{ t('common.none') }}</p>
    <div v-else class="max-h-[28rem] overflow-auto">
        <table class="w-full min-w-max border-collapse text-sm">
            <thead class="sticky top-0 bg-background">
                <tr class="text-xs text-muted-foreground">
                    <th class="border px-2 py-1">#</th>
                    <th v-for="column in columns" :key="column.key" class="border px-2 py-1 font-medium">
                        {{ column.label }}<span v-if="column.unit"> ({{ column.unit }})</span>
                        <div v-if="rangeText(column.min, column.max)" class="font-normal">{{ rangeText(column.min, column.max) }}</div>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="index in shown" :key="index">
                    <td class="border px-2 py-1 text-center text-xs text-muted-foreground">{{ index }}</td>
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="border p-0.5"
                        :class="{ 'bg-red-50 font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-300': red(index - 1, column) }"
                    >
                        <template v-if="mode === 'edit'">
                            <select
                                v-if="column.type === 'pass_fail'"
                                :value="value(index - 1, column.key) ?? ''"
                                class="h-9 w-full rounded border border-input bg-transparent px-1"
                                :aria-label="`${index} ${column.label}`"
                                @change="set(index - 1, column.key, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value=""></option>
                                <option v-for="option in PASS_FAIL" :key="option" :value="option">{{ t(`pm_visits.pass_fail.${option}`) }}</option>
                            </select>
                            <input
                                v-else
                                :id="`${idPrefix}_${index}_${column.key}`"
                                :value="value(index - 1, column.key) ?? ''"
                                :type="column.type === 'number' ? 'number' : 'text'"
                                :step="column.type === 'number' ? 'any' : undefined"
                                :inputmode="column.type === 'number' ? 'decimal' : undefined"
                                class="h-9 w-full min-w-20 rounded border border-input bg-transparent px-2"
                                :class="{ 'border-red-500': red(index - 1, column) }"
                                :aria-label="`${index} ${column.label}`"
                                @input="set(index - 1, column.key, ($event.target as HTMLInputElement).value)"
                            />
                        </template>
                        <span v-else class="px-1">{{
                            column.type === 'pass_fail' && value(index - 1, column.key)
                                ? t(`pm_visits.pass_fail.${value(index - 1, column.key)}`)
                                : (value(index - 1, column.key) ?? '')
                        }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
