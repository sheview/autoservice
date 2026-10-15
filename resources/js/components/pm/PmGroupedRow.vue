<script setup lang="ts">
import { t } from '@/lib/i18n';
import { serverDraftStore } from '@/lib/pmDraftStore';
import { RowRejected, saveRow, type RowItem } from '@/lib/pmRows';
import { formAnswer, outOfRange, PASS_FAIL, type Answer, type TemplateField } from '@/lib/pmTemplate';
import { Link } from '@inertiajs/vue3';
import { Check, CornerDownRight, ExternalLink, LoaderCircle } from 'lucide-vue-next';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';

export interface TableRow extends RowItem {
    asset: Record<string, string | number | null> & { asset_code: string; name: string };
}

/**
 * One piece of equipment as a row of the work order's table: its answers in the cells, its result
 * (passed / failed / not inspected with why), saved on its own a moment after each change — as a
 * draft while it has no result yet. Questions that do not fit a cell are filled in on its own page.
 */
const props = defineProps<{
    row: TableRow;
    columns: TemplateField[];
    visitUlid: string;
    working: boolean;
    reasons: string[];
    part?: boolean;
    highlighted?: boolean;
}>();
const emit = defineEmits<{ saved: [item: RowItem] }>();

const RESULTS = ['passed', 'failed', 'not_inspected'] as const;

const state = reactive({
    result: props.row.result,
    answers: Object.fromEntries(props.columns.map((field) => [field.key, formAnswer(field, props.row.answers?.[field.key])])) as Record<
        string,
        Answer
    >,
    note: props.row.note ?? '',
    reason: props.row.not_inspected_reason,
});
const status = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const error = ref('');

// Saved elsewhere ("ผ่านทั้งหมด"): take the server's row without saving it back.
let syncing = false;
watch(
    () => props.row,
    (row) => {
        syncing = true;
        state.result = row.result;
        for (const field of props.columns) state.answers[field.key] = formAnswer(field, row.answers?.[field.key]);
        state.note = row.note ?? '';
        state.reason = row.not_inspected_reason;
        queueMicrotask(() => (syncing = false));
    },
);

let timer: ReturnType<typeof setTimeout> | undefined;
const save = async () => {
    status.value = 'saving';
    error.value = '';
    const payload = {
        result: state.result,
        answers: state.answers,
        note: state.note || null,
        not_inspected_reason: state.result === 'not_inspected' ? state.reason : null,
    };
    try {
        if (state.result === 'pending') {
            await serverDraftStore.save(
                { visitUlid: props.visitUlid, itemId: props.row.id },
                { ...payload, result: null, remarks: {}, note: state.note, instrument_id: null },
            );
        } else {
            emit('saved', await saveRow(props.visitUlid, props.row.id, payload));
        }
        status.value = 'saved';
    } catch (e) {
        status.value = 'error';
        error.value = e instanceof RowRejected ? e.message : t('pm_field.row_failed');
    }
};
watch(
    state,
    () => {
        if (syncing || !props.working) return;
        clearTimeout(timer);
        // Waiting for what only the person knows: why it failed, or why it was not inspected.
        if ((state.result === 'failed' && !state.note.trim()) || (state.result === 'not_inspected' && !state.reason)) {
            status.value = 'idle';
            return;
        }
        timer = setTimeout(save, 700);
    },
    { deep: true },
);
onBeforeUnmount(() => clearTimeout(timer));

const answer = (field: TemplateField, value: Answer) => {
    state.answers[field.key] = value;
    // A Fail makes the equipment failed.
    if (field.type === 'pass_fail' && value === 'fail' && state.result !== 'not_inspected') state.result = 'failed';
};

const cellClass = (field: TemplateField) =>
    (field.type === 'pass_fail' && state.answers[field.key] === 'fail') ||
    ((field.type === 'number' || field.type === 'measure') && outOfRange(state.answers[field.key], field.min, field.max))
        ? 'bg-red-50 dark:bg-red-950/40'
        : '';
const resultClass: Record<string, string> = {
    passed: 'border-green-600 bg-green-600 text-white',
    failed: 'border-red-600 bg-red-600 text-white',
    not_inspected: 'border-slate-500 bg-slate-500 text-white',
};
const passFailClass: Record<string, string> = {
    pass: 'border-green-600 bg-green-600 text-white',
    fail: 'border-red-600 bg-red-600 text-white',
    na: 'border-slate-500 bg-slate-500 text-white',
};
</script>

<template>
    <tr :id="`pm-row-${row.id}`" class="border-t align-top transition-colors" :class="highlighted ? 'bg-amber-100 dark:bg-amber-900/40' : ''">
        <td class="sticky left-0 z-10 min-w-40 max-w-56 bg-background px-2 py-1.5" :class="highlighted ? 'bg-amber-100 dark:bg-amber-900/40' : ''">
            <div class="flex items-start gap-1">
                <CornerDownRight v-if="part" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ row.asset.name }}</div>
                    <div class="truncate font-mono text-xs text-muted-foreground">{{ row.asset.asset_code }}</div>
                </div>
            </div>
        </td>
        <td v-for="field in columns" :key="field.key" class="px-1.5 py-1.5" :class="cellClass(field)">
            <template v-if="field.type === 'readonly_from_asset'">
                <span class="text-xs">{{ row.asset[field.source ?? ''] ?? '-' }}</span>
            </template>
            <div v-else-if="field.type === 'pass_fail'" class="flex gap-1">
                <button
                    v-for="option in PASS_FAIL"
                    :key="option"
                    type="button"
                    :disabled="!working"
                    class="h-8 min-w-9 rounded border px-1 text-xs"
                    :class="state.answers[field.key] === option ? passFailClass[option] : 'border-input'"
                    @click="answer(field, state.answers[field.key] === option ? null : option)"
                >
                    {{ t(`pm_field.pass_fail_short.${option}`) }}
                </button>
            </div>
            <input
                v-else-if="field.type === 'check'"
                type="checkbox"
                class="h-6 w-6"
                :disabled="!working"
                :checked="state.answers[field.key] === true"
                @change="answer(field, ($event.target as HTMLInputElement).checked)"
            />
            <select
                v-else-if="field.type === 'select'"
                class="h-8 rounded border border-input bg-transparent px-1 text-xs"
                :disabled="!working"
                :value="state.answers[field.key] ?? ''"
                @change="answer(field, ($event.target as HTMLSelectElement).value || null)"
            >
                <option value="">-</option>
                <option v-for="option in field.options ?? []" :key="option" :value="option">{{ option }}</option>
            </select>
            <input
                v-else-if="field.type === 'number' || field.type === 'measure'"
                type="number"
                inputmode="decimal"
                step="any"
                class="h-8 w-20 rounded border border-input bg-transparent px-1.5 text-xs"
                :disabled="!working"
                :value="state.answers[field.key] ?? ''"
                @change="answer(field, ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value))"
            />
            <input
                v-else
                type="text"
                class="h-8 w-32 rounded border border-input bg-transparent px-1.5 text-xs"
                :disabled="!working"
                :value="state.answers[field.key] ?? ''"
                @change="answer(field, ($event.target as HTMLInputElement).value || null)"
            />
        </td>
        <td class="px-1.5 py-1.5">
            <div class="flex gap-1">
                <button
                    v-for="result in RESULTS"
                    :key="result"
                    type="button"
                    :disabled="!working"
                    class="h-8 whitespace-nowrap rounded border px-2 text-xs"
                    :class="state.result === result ? resultClass[result] : 'border-input'"
                    @click="state.result = state.result === result ? 'pending' : result"
                >
                    {{ t(`pm_field.row_results.${result}`) }}
                </button>
            </div>
        </td>
        <td class="w-16 whitespace-nowrap px-1.5 py-1.5 text-right text-xs">
            <LoaderCircle v-if="status === 'saving'" class="inline h-4 w-4 animate-spin text-muted-foreground" />
            <Check v-else-if="status === 'saved' && state.result !== 'pending'" class="inline h-4 w-4 text-green-600" />
            <Link
                v-if="working"
                :href="route('maintenance.field.item', [visitUlid, row.id])"
                class="ml-1 inline-flex text-muted-foreground hover:text-foreground"
                :title="t('pm_field.row_details')"
            >
                <ExternalLink class="h-4 w-4" />
            </Link>
        </td>
    </tr>
    <!-- Why it failed / was not inspected, and what went wrong saving -->
    <tr v-if="state.result === 'failed' || state.result === 'not_inspected' || error" class="bg-muted/30">
        <td class="sticky left-0 bg-transparent" />
        <td :colspan="columns.length + 2" class="px-1.5 pb-2">
            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-if="state.result === 'not_inspected'"
                    v-model="state.reason"
                    class="h-8 rounded border border-input bg-background px-1.5 text-xs"
                    :class="{ 'border-red-500': !state.reason }"
                    :disabled="!working"
                >
                    <option :value="null" disabled>{{ t('pm_field.choose_reason') }}</option>
                    <option v-for="reason in reasons" :key="reason" :value="reason">{{ t(`pm_visits.not_inspected_reasons.${reason}`) }}</option>
                </select>
                <input
                    v-if="state.result !== 'pending' && state.result !== 'passed'"
                    v-model.lazy="state.note"
                    type="text"
                    class="h-8 min-w-48 flex-1 rounded border border-input bg-background px-1.5 text-xs"
                    :class="{ 'border-red-500': state.result === 'failed' && !state.note.trim() }"
                    :disabled="!working"
                    :placeholder="state.result === 'failed' ? t('pm_field.failed_note') : t('pm_field.note_optional')"
                />
                <span v-if="error" class="text-xs text-red-600">{{ error }}</span>
            </div>
        </td>
    </tr>
</template>
