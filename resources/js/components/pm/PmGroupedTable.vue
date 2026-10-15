<script setup lang="ts">
import PmGroupedRow, { type TableRow } from '@/components/pm/PmGroupedRow.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { RowItem } from '@/lib/pmRows';
import { INLINE_TYPES } from '@/lib/pmRows';
import type { TemplateField } from '@/lib/pmTemplate';
import { CheckCheck, MapPin } from 'lucide-vue-next';
import { computed } from 'vue';

export interface TableSet {
    // the main piece, then its parts (a PC, then its monitor)
    rows: TableRow[];
}

export interface TablePlace {
    title: string;
    sets: TableSet[];
}

/**
 * The equipment of one table template (PC, Network) in a work order: by floor / room, a row per
 * piece, parts under the piece they belong to. "ผ่านทั้งหมด" passes what is shown and has no result
 * yet; "ผ่านทั้งชุด" one set.
 */
const props = defineProps<{
    fields: TemplateField[];
    places: TablePlace[];
    visitUlid: string;
    working: boolean;
    reasons: string[];
    highlighted: number | null;
    busy: boolean;
}>();
const emit = defineEmits<{ saved: [item: RowItem]; pass: [ids: number[]] }>();

const columns = computed(() => props.fields.filter((field) => INLINE_TYPES.includes(field.type)));
const elsewhere = computed(() => props.fields.length > columns.value.length);
const pendingOf = (rows: TableRow[]) => rows.filter((row) => row.result === 'pending').map((row) => row.id);
const pending = computed(() => props.places.flatMap((place) => place.sets.flatMap((set) => pendingOf(set.rows))));
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p v-if="elsewhere" class="text-xs text-muted-foreground">{{ t('pm_field.table_elsewhere') }}</p>
            <Button v-if="working" size="sm" class="ml-auto" :disabled="busy || pending.length === 0" @click="emit('pass', pending)">
                <CheckCheck class="mr-1.5 h-4 w-4" />{{ t('pm_field.pass_rows', { count: pending.length }) }}
            </Button>
        </div>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/40 text-left text-xs">
                    <tr>
                        <th class="sticky left-0 z-10 bg-muted px-2 py-2 font-medium">{{ t('pm_field.equipment_column') }}</th>
                        <th v-for="field in columns" :key="field.key" class="whitespace-nowrap px-1.5 py-2 font-medium">{{ field.label }}</th>
                        <th class="px-1.5 py-2 font-medium">{{ t('pm_field.result_column') }}</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="place in places" :key="place.title">
                        <tr class="border-t bg-muted/20">
                            <td :colspan="columns.length + 3" class="px-2 py-1.5 text-xs font-semibold text-muted-foreground">
                                <MapPin class="mr-1 inline h-3.5 w-3.5" />{{ place.title }}
                                <span class="font-normal">
                                    ({{ place.sets.flatMap((s) => s.rows).filter((r) => r.result !== 'pending').length }}/{{
                                        place.sets.flatMap((s) => s.rows).length
                                    }})
                                </span>
                            </td>
                        </tr>
                        <template v-for="set in place.sets" :key="set.rows[0].id">
                            <tr v-if="set.rows.length > 1 && working" class="border-t">
                                <td :colspan="columns.length + 3" class="px-2 pt-1.5 text-xs">
                                    <span class="text-muted-foreground">{{ t('pm_field.set', { count: set.rows.length }) }}</span>
                                    <button
                                        type="button"
                                        class="ml-2 text-primary underline-offset-4 hover:underline disabled:opacity-50"
                                        :disabled="busy || pendingOf(set.rows).length === 0"
                                        @click="emit('pass', pendingOf(set.rows))"
                                    >
                                        {{ t('pm_field.pass_set') }}
                                    </button>
                                </td>
                            </tr>
                            <PmGroupedRow
                                v-for="(row, i) in set.rows"
                                :key="row.id"
                                :row="row"
                                :columns="columns"
                                :visit-ulid="visitUlid"
                                :working="working"
                                :reasons="reasons"
                                :part="i > 0"
                                :highlighted="highlighted === row.id"
                                @saved="emit('saved', $event)"
                            />
                        </template>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
