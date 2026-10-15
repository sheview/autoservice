<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PmField from '@/components/pm/PmField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { emptyAnswer, sectionsOf, type Answer, type RepeatColumn, type TablePart, type TemplateField } from '@/lib/pmTemplate';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Assignment = { asset_category_id: number | null; asset_brand_id: number | null; asset_model_id: number | null };

const props = defineProps<{
    checklist: {
        id: number;
        code: string;
        name: string;
        version: number;
        status: 'draft' | 'published' | 'archived';
        service_line: string | null;
        layout: string;
        blocks: string[];
        items: TemplateField[];
        assignments: Assignment[];
        published_at: string | null;
    } | null;
    versions: { id: number; version: number; status: string; published_at: string | null; updated_at: string }[];
    categories: { id: number; name: string }[];
    catalog: {
        brands: { id: number; name: string }[];
        models: { id: number; name: string; asset_brand_id: number; asset_category_id: number | null }[];
    };
    itemTypes: string[];
    repeatColumnTypes: string[];
    assetSources: string[];
    layouts: string[];
    blocks: string[];
    serviceLines: string[];
    limits: { rows: number; columns: number; items: number };
    can: { manage: boolean };
}>();

const page = usePage<SharedData>();
const template = props.checklist;
// Only a draft is changed; a published or archived version is read, then copied into a new draft.
const editable = computed(() => props.can.manage && (!template || template.status === 'draft'));

const title = !template ? t('pm_checklists.create') : editable.value ? t('pm_checklists.edit') : t('pm_checklists.view');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_checklists.title'), href: route('maintenance.checklists.index') },
    { title, href: template ? route('maintenance.checklists.edit', template.id) : route('maintenance.checklists.create') },
];

const form = useForm({
    code: template?.code ?? '',
    name: template?.name ?? '',
    service_line: template?.service_line ?? null,
    layout: template?.layout ?? 'per_asset',
    blocks: template?.blocks ?? ['acknowledgement'],
    assignments: (template?.assignments ?? []) as Assignment[],
    items: (template?.items ?? [{ key: 'item_1', label: '', type: 'pass_fail' }]).map((item) => ({ ...item })) as TemplateField[],
    publish: false as boolean,
});

// --- fields ---
const freeKey = (prefix: string, taken: string[]) => {
    let n = 1;
    while (taken.includes(`${prefix}_${n}`)) n++;
    return `${prefix}_${n}`;
};
const addItem = () => {
    // A new field stays in the section of the one above it.
    const section = form.items[form.items.length - 1]?.section ?? '';
    form.items.push({
        key: freeKey(
            'item',
            form.items.map((i) => i.key),
        ),
        label: '',
        type: 'pass_fail',
        section,
    });
};
const removeItem = (index: number) => form.items.splice(index, 1);
const moveItem = (index: number, by: number) => {
    const [item] = form.items.splice(index, 1);
    form.items.splice(index + by, 0, item);
};

// Each type keeps the settings it uses; changing the type starts them afresh.
const setType = (item: TemplateField, type: string) => {
    item.type = type;
    if ((type === 'select' || type === 'checkbox_group') && !item.options) item.options = [];
    if (type === 'measure_table') {
        item.rows = [{ key: 'row_1', label: '' }];
        item.columns = [{ key: 'col_1', label: '' }];
    }
    if (type === 'repeat_table') {
        item.rows = { count: 10 };
        item.columns = [{ key: 'col_1', label: '', type: 'number' }];
    }
    if (type === 'readonly_from_asset' && !item.source) item.source = 'serial_number';
    if (type === 'photo' && !item.photos) item.photos = 1;
};
const optionsText = (item: TemplateField) => (item.options ?? []).join('\n');
const setOptions = (item: TemplateField, text: string) => (item.options = text.split('\n'));

const partsOf = (item: TemplateField, which: 'rows' | 'columns') => (which === 'rows' ? (item.rows as TablePart[]) : (item.columns as TablePart[]));
const addPart = (item: TemplateField, which: 'rows' | 'columns') => {
    const parts = partsOf(item, which);
    const prefix = which === 'rows' ? 'row' : 'col';
    const key = freeKey(
        prefix,
        parts.map((p) => p.key),
    );
    parts.push(item.type === 'repeat_table' ? ({ key, label: '', type: 'number' } as RepeatColumn) : { key, label: '' });
};
const removePart = (item: TemplateField, which: 'rows' | 'columns', index: number) => partsOf(item, which).splice(index, 1);

const repeatRows = (item: TemplateField) => (item.rows ?? {}) as { count?: number; from?: string; max?: number };
const setRepeatFrom = (item: TemplateField, from: string) =>
    (item.rows = from ? { from, max: repeatRows(item).max ?? 50 } : { count: repeatRows(item).count ?? 10 });
// A repeat table counts its rows from a number field above it.
const numberFieldsBefore = (index: number) => form.items.slice(0, index).filter((i) => i.type === 'number' || i.type === 'measure');

// --- assignments ---
const addAssignment = () => form.assignments.push({ asset_category_id: null, asset_brand_id: null, asset_model_id: null });
const modelsFor = (assignment: Assignment) =>
    props.catalog.models.filter(
        (m) =>
            (!assignment.asset_brand_id || m.asset_brand_id === assignment.asset_brand_id) &&
            (!assignment.asset_category_id || !m.asset_category_id || m.asset_category_id === assignment.asset_category_id),
    );

// --- preview: the form as a technician sees it ---
const preview = ref(false);
const previewAnswers = reactive<Record<string, Answer>>({});
const openPreview = () => {
    for (const item of form.items) previewAnswers[item.key] = emptyAnswer(item);
    preview.value = !preview.value;
};

// --- saving and the version actions ---
const submit = (publish: boolean) => {
    if (publish && !confirm(t('pm_checklists.confirm_publish'))) return;
    form.publish = publish;
    form.transform((data) => {
        const { code, ...rest } = data;
        const items = rest.items.map((item) => ({ ...item, options: item.options?.map((o) => o.trim()).filter(Boolean) }));
        return template ? { ...rest, items } : { code, ...rest, items };
    });
    if (template) form.put(route('maintenance.checklists.update', template.id), { preserveScroll: true });
    else form.post(route('maintenance.checklists.store'), { preserveScroll: true });
};
const act = (name: 'publish' | 'new-version' | 'copy') => {
    if (name === 'publish' && !confirm(t('pm_checklists.confirm_publish'))) return;
    router.post(route(`maintenance.checklists.${name}`, template!.id), {}, { preserveScroll: true });
};
const destroy = () =>
    confirm(t('pm_checklists.confirm_delete', { name: template!.name })) && router.delete(route('maintenance.checklists.destroy', template!.id));

const errors = computed(() => form.errors as Record<string, string>);
const itemError = (index: number, name: string) => errors.value[`items.${index}.${name}`];
const errorsUnder = (prefix: string) =>
    Object.entries(errors.value)
        .filter(([key]) => key.startsWith(prefix))
        .map(([, message]) => message);
const sections = computed(() => sectionsOf(form.items));

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs disabled:opacity-70';
const smallInput = 'h-8 text-sm';
const statusClass: Record<string, string> = {
    draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    published: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    archived: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="template ? `${template.code} · ${template.name}` : title" :description="t('pm_checklists.description')" />
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" @click="openPreview">{{ t('pm_checklists.preview') }}</Button>
                    <template v-if="template && can.manage">
                        <Button v-if="template.status === 'draft'" variant="outline" class="text-destructive" @click="destroy">{{
                            t('common.delete')
                        }}</Button>
                        <Button v-if="template.status !== 'draft'" @click="act('new-version')">{{ t('pm_checklists.new_version') }}</Button>
                        <Button variant="outline" @click="act('copy')">{{ t('pm_checklists.copy') }}</Button>
                    </template>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="template && template.status !== 'draft'" class="rounded-md bg-muted px-4 py-2 text-sm text-muted-foreground">
                {{ t('pm_checklists.read_only') }}
            </p>
            <InputError :message="errors.template" />

            <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
                <form class="min-w-0 space-y-8" @submit.prevent="submit(false)">
                    <fieldset :disabled="!editable" class="space-y-8">
                        <!-- Details -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid content-start gap-2">
                                <Label for="code">{{ t('pm_checklists.code') }}</Label>
                                <Input id="code" v-model="form.code" class="font-mono uppercase" :disabled="!!template" required autocomplete="off" />
                                <p class="text-xs text-muted-foreground">{{ t('pm_checklists.code_hint') }}</p>
                                <InputError :message="errors.code" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label for="name">{{ t('pm_checklists.name') }}</Label>
                                <Input id="name" v-model="form.name" required autocomplete="off" />
                                <InputError :message="errors.name" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label for="service_line">{{ t('pm_checklists.service_line') }}</Label>
                                <select id="service_line" v-model="form.service_line" :class="selectClass">
                                    <option :value="null">{{ t('pm_checklists.no_service_line') }}</option>
                                    <option v-for="line in serviceLines" :key="line" :value="line">
                                        {{ t(`pm_checklists.service_lines.${line}`) }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid content-start gap-2">
                                <Label for="layout">{{ t('pm_checklists.layout') }}</Label>
                                <select id="layout" v-model="form.layout" :class="selectClass">
                                    <option v-for="layout in layouts" :key="layout" :value="layout">
                                        {{ t(`pm_checklists.layouts.${layout}`) }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Shared blocks on the sheet -->
                        <section class="space-y-2">
                            <h3 class="text-sm font-semibold">{{ t('pm_checklists.blocks') }}</h3>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <label v-for="block in blocks" :key="block" class="flex items-center gap-2 text-sm">
                                    <input v-model="form.blocks" type="checkbox" :value="block" class="size-4 rounded border-input" />
                                    {{ t(`pm_checklists.block_names.${block}`) }}
                                </label>
                            </div>
                        </section>

                        <!-- What it checks -->
                        <section class="space-y-3">
                            <div>
                                <h3 class="text-sm font-semibold">{{ t('pm_checklists.assignments') }}</h3>
                                <p class="text-xs text-muted-foreground">{{ t('pm_checklists.assignments_hint') }}</p>
                            </div>
                            <div v-for="(assignment, a) in form.assignments" :key="a" class="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                <select v-model="assignment.asset_category_id" :class="selectClass" :aria-label="t('pm_checklists.category')">
                                    <option :value="null">
                                        {{
                                            assignment.asset_brand_id || assignment.asset_model_id
                                                ? t('pm_checklists.any_category')
                                                : t('pm_checklists.default_assignment')
                                        }}
                                    </option>
                                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                                </select>
                                <select v-model="assignment.asset_brand_id" :class="selectClass" :aria-label="t('pm_checklists.brand')">
                                    <option :value="null">{{ t('pm_checklists.any_brand') }}</option>
                                    <option v-for="brand in catalog.brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                                </select>
                                <select v-model="assignment.asset_model_id" :class="selectClass" :aria-label="t('pm_checklists.model')">
                                    <option :value="null">{{ t('pm_checklists.any_model') }}</option>
                                    <option v-for="model in modelsFor(assignment)" :key="model.id" :value="model.id">{{ model.name }}</option>
                                </select>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="t('common.delete')"
                                    @click="form.assignments.splice(a, 1)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                                <InputError class="sm:col-span-4" :message="errorsUnder(`assignments.${a}.`)[0]" />
                            </div>
                            <Button v-if="editable" type="button" variant="outline" size="sm" @click="addAssignment">
                                <Plus class="mr-1 h-4 w-4" />{{ t('pm_checklists.add_assignment') }}
                            </Button>
                        </section>

                        <!-- Fields -->
                        <section class="space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold">{{ t('pm_checklists.items') }}</h3>
                                <p class="text-xs text-muted-foreground">{{ t('pm_checklists.items_hint') }}</p>
                            </div>

                            <div v-for="(item, index) in form.items" :key="index" class="space-y-3 rounded-md border p-3">
                                <div class="grid gap-3 sm:grid-cols-12">
                                    <div class="grid content-start gap-1 sm:col-span-3">
                                        <Label :for="`item_section_${index}`" class="text-xs">{{ t('pm_checklists.item_section') }}</Label>
                                        <Input
                                            :id="`item_section_${index}`"
                                            v-model="item.section"
                                            :class="smallInput"
                                            :placeholder="t('pm_checklists.item_section_placeholder')"
                                        />
                                    </div>
                                    <div class="grid content-start gap-1 sm:col-span-4">
                                        <Label :for="`item_label_${index}`" class="text-xs">{{ t('pm_checklists.item_label') }}</Label>
                                        <Input :id="`item_label_${index}`" v-model="item.label" :class="smallInput" required />
                                        <InputError :message="itemError(index, 'label')" />
                                    </div>
                                    <div class="grid content-start gap-1 sm:col-span-3">
                                        <Label :for="`item_type_${index}`" class="text-xs">{{ t('pm_checklists.item_type') }}</Label>
                                        <select
                                            :id="`item_type_${index}`"
                                            :value="item.type"
                                            :class="selectClass"
                                            @change="setType(item, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option
                                                v-for="type in [...itemTypes, ...(itemTypes.includes(item.type) ? [] : [item.type])]"
                                                :key="type"
                                                :value="type"
                                            >
                                                {{ t(`pm_checklists.item_types.${type}`) }}
                                            </option>
                                        </select>
                                        <InputError :message="itemError(index, 'type')" />
                                    </div>
                                    <div class="grid content-start gap-1 sm:col-span-2">
                                        <Label :for="`item_key_${index}`" class="text-xs">{{ t('pm_checklists.item_key') }}</Label>
                                        <Input
                                            :id="`item_key_${index}`"
                                            v-model="item.key"
                                            :class="smallInput"
                                            class="font-mono"
                                            required
                                            pattern="[a-z][a-z0-9_]*"
                                        />
                                        <InputError :message="itemError(index, 'key')" />
                                    </div>
                                </div>

                                <!-- Settings of the type -->
                                <div v-if="item.type === 'number' || item.type === 'measure'" class="grid gap-3 sm:grid-cols-3">
                                    <Input
                                        v-model="item.unit"
                                        :class="smallInput"
                                        :placeholder="t('pm_checklists.item_unit')"
                                        :aria-label="t('pm_checklists.item_unit')"
                                    />
                                    <Input
                                        v-model="item.min as unknown as string"
                                        type="number"
                                        step="any"
                                        :class="smallInput"
                                        :placeholder="t('pm_checklists.item_min')"
                                        :aria-label="t('pm_checklists.item_min')"
                                    />
                                    <Input
                                        v-model="item.max as unknown as string"
                                        type="number"
                                        step="any"
                                        :class="smallInput"
                                        :placeholder="t('pm_checklists.item_max')"
                                        :aria-label="t('pm_checklists.item_max')"
                                    />
                                    <p class="text-xs text-muted-foreground sm:col-span-3">{{ t('pm_checklists.range_hint') }}</p>
                                </div>

                                <div v-else-if="item.type === 'select' || item.type === 'checkbox_group'" class="grid gap-1">
                                    <Label class="text-xs">{{ t('pm_checklists.item_options') }}</Label>
                                    <textarea
                                        :value="optionsText(item)"
                                        rows="3"
                                        class="shadow-xs rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                        @input="setOptions(item, ($event.target as HTMLTextAreaElement).value)"
                                    />
                                </div>

                                <div v-else-if="item.type === 'readonly_from_asset'" class="grid gap-1 sm:max-w-xs">
                                    <Label class="text-xs">{{ t('pm_checklists.item_source') }}</Label>
                                    <select v-model="item.source" :class="selectClass">
                                        <option v-for="source in assetSources" :key="source" :value="source">
                                            {{ t(`pm_checklists.asset_sources.${source}`) }}
                                        </option>
                                    </select>
                                </div>

                                <div v-else-if="item.type === 'photo'" class="grid gap-1 sm:max-w-xs">
                                    <Label class="text-xs">{{ t('pm_checklists.item_photos') }}</Label>
                                    <Input v-model.number="item.photos" type="number" min="1" max="10" :class="smallInput" />
                                </div>

                                <!-- A table: its rows (measure table) and columns -->
                                <div v-else-if="item.type === 'measure_table' || item.type === 'repeat_table'" class="space-y-3">
                                    <template
                                        v-for="which in item.type === 'measure_table' ? (['rows', 'columns'] as const) : (['columns'] as const)"
                                        :key="which"
                                    >
                                        <div class="space-y-1">
                                            <p class="text-xs font-medium">{{ t(`pm_checklists.table_${which}`) }}</p>
                                            <div
                                                v-for="(part, p) in partsOf(item, which)"
                                                :key="p"
                                                class="grid grid-cols-2 gap-2 sm:grid-cols-[7rem_1fr_6rem_6rem_6rem_6rem_auto]"
                                            >
                                                <Input
                                                    v-model="part.key"
                                                    :class="smallInput"
                                                    class="font-mono"
                                                    :placeholder="t('pm_checklists.part_key')"
                                                    :aria-label="t('pm_checklists.part_key')"
                                                />
                                                <Input
                                                    v-model="part.label"
                                                    :class="smallInput"
                                                    :placeholder="t('pm_checklists.part_label')"
                                                    :aria-label="t('pm_checklists.part_label')"
                                                />
                                                <select
                                                    v-if="item.type === 'repeat_table'"
                                                    v-model="(part as RepeatColumn).type"
                                                    :class="selectClass"
                                                    class="h-8"
                                                    :aria-label="t('pm_checklists.column_type')"
                                                >
                                                    <option v-for="type in repeatColumnTypes" :key="type" :value="type">
                                                        {{ t(`pm_checklists.item_types.${type}`) }}
                                                    </option>
                                                </select>
                                                <span v-else></span>
                                                <Input
                                                    v-model="part.unit"
                                                    :class="smallInput"
                                                    :placeholder="t('pm_checklists.item_unit')"
                                                    :aria-label="t('pm_checklists.item_unit')"
                                                    :disabled="which === 'columns' && item.type === 'measure_table'"
                                                />
                                                <Input
                                                    v-model="part.min as unknown as string"
                                                    type="number"
                                                    step="any"
                                                    :class="smallInput"
                                                    :placeholder="t('pm_checklists.item_min')"
                                                    :aria-label="t('pm_checklists.item_min')"
                                                />
                                                <Input
                                                    v-model="part.max as unknown as string"
                                                    type="number"
                                                    step="any"
                                                    :class="smallInput"
                                                    :placeholder="t('pm_checklists.item_max')"
                                                    :aria-label="t('pm_checklists.item_max')"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    class="h-8 w-8"
                                                    :aria-label="t('common.delete')"
                                                    @click="removePart(item, which, p)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </div>
                                            <Button v-if="editable" type="button" variant="ghost" size="sm" @click="addPart(item, which)">
                                                <Plus class="mr-1 h-3.5 w-3.5" />{{
                                                    which === 'rows' ? t('pm_checklists.add_row') : t('pm_checklists.add_column')
                                                }}
                                            </Button>
                                        </div>
                                    </template>
                                    <p v-if="item.type === 'measure_table'" class="text-xs text-muted-foreground">
                                        {{ t('pm_checklists.cell_range_hint') }}
                                    </p>

                                    <div v-if="item.type === 'repeat_table'" class="flex flex-wrap items-end gap-3">
                                        <div class="grid gap-1">
                                            <Label class="text-xs">{{ t('pm_checklists.repeat_from') }}</Label>
                                            <select
                                                :value="repeatRows(item).from ?? ''"
                                                :class="selectClass"
                                                class="h-8"
                                                @change="setRepeatFrom(item, ($event.target as HTMLSelectElement).value)"
                                            >
                                                <option value="">{{ t('pm_checklists.repeat_from_none') }}</option>
                                                <option v-for="field in numberFieldsBefore(index)" :key="field.key" :value="field.key">
                                                    {{ field.label || field.key }}
                                                </option>
                                            </select>
                                        </div>
                                        <div v-if="repeatRows(item).from" class="grid gap-1">
                                            <Label class="text-xs">{{ t('pm_checklists.repeat_max') }}</Label>
                                            <Input
                                                v-model.number="repeatRows(item).max"
                                                type="number"
                                                min="1"
                                                :max="limits.rows"
                                                :class="smallInput"
                                                class="w-24"
                                            />
                                        </div>
                                        <div v-else class="grid gap-1">
                                            <Label class="text-xs">{{ t('pm_checklists.repeat_count') }}</Label>
                                            <Input
                                                v-model.number="repeatRows(item).count"
                                                type="number"
                                                min="1"
                                                :max="limits.rows"
                                                :class="smallInput"
                                                class="w-24"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-4 text-xs">
                                    <label v-if="item.type !== 'photo' && item.type !== 'readonly_from_asset'" class="flex items-center gap-2">
                                        <input v-model="item.required" type="checkbox" class="size-4 rounded border-input" />
                                        {{ t('pm_checklists.item_required') }}
                                    </label>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground disabled:opacity-40"
                                        :disabled="index === 0"
                                        @click="moveItem(index, -1)"
                                    >
                                        <ArrowUp class="h-3 w-3" />{{ t('pm_checklists.move_up') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground disabled:opacity-40"
                                        :disabled="index === form.items.length - 1"
                                        @click="moveItem(index, 1)"
                                    >
                                        <ArrowDown class="h-3 w-3" />{{ t('pm_checklists.move_down') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="ml-auto inline-flex items-center gap-1 text-muted-foreground hover:text-destructive disabled:opacity-40"
                                        :disabled="form.items.length === 1"
                                        @click="removeItem(index)"
                                    >
                                        <Trash2 class="h-3 w-3" />{{ t('pm_checklists.remove_item') }}
                                    </button>
                                </div>
                                <InputError
                                    :message="
                                        errorsUnder(`items.${index}.`).filter(
                                            (m) => m !== itemError(index, 'label') && m !== itemError(index, 'type') && m !== itemError(index, 'key'),
                                        )[0]
                                    "
                                />
                            </div>

                            <InputError :message="errors.items" />
                            <ul v-if="errorsUnder('items').length > 1 && !errorsUnder('items.').length" class="list-disc pl-5 text-sm text-red-600">
                                <li v-for="message in errorsUnder('items')" :key="message">{{ message }}</li>
                            </ul>
                            <Button v-if="editable" type="button" variant="outline" :disabled="form.items.length >= limits.items" @click="addItem">
                                <Plus class="mr-1 h-4 w-4" />{{ t('pm_checklists.add_item') }}
                            </Button>
                        </section>
                    </fieldset>

                    <div v-if="editable" class="flex flex-wrap items-center gap-3">
                        <Button type="submit" variant="outline" :disabled="form.processing">{{ t('pm_checklists.save_draft') }}</Button>
                        <Button type="button" :disabled="form.processing" @click="submit(true)">{{ t('pm_checklists.save_publish') }}</Button>
                        <Button variant="ghost" as-child>
                            <Link :href="route('maintenance.checklists.index')">{{ t('common.cancel') }}</Link>
                        </Button>
                    </div>
                </form>

                <!-- Versions of the code -->
                <aside v-if="versions.length" class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('pm_checklists.versions') }}</h3>
                    <ul class="divide-y rounded-md border text-sm">
                        <li v-for="version in versions" :key="version.id" class="flex items-center justify-between gap-2 px-3 py-2">
                            <Link
                                :href="route('maintenance.checklists.edit', version.id)"
                                class="underline-offset-4 hover:underline"
                                :class="{ 'font-semibold': version.id === template?.id }"
                            >
                                {{ t('pm_checklists.version_value', { version: version.version }) }}
                            </Link>
                            <span class="rounded-full px-2 py-0.5 text-xs" :class="statusClass[version.status]">{{
                                t(`pm_checklists.statuses.${version.status}`)
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{ dateTime(version.published_at ?? version.updated_at) }}</span>
                        </li>
                    </ul>
                </aside>
            </div>

            <!-- Preview: the fields as they will be filled in -->
            <section v-if="preview" class="space-y-4 rounded-lg border p-4">
                <h3 class="text-sm font-semibold">{{ t('pm_checklists.preview') }}</h3>
                <div v-for="(section, s) in sections" :key="s" class="space-y-3">
                    <h4 v-if="section.title" class="border-b pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ section.title }}
                    </h4>
                    <PmField
                        v-for="field in section.fields"
                        :key="field.key"
                        v-model="previewAnswers[field.key]"
                        :field="field"
                        mode="edit"
                        :answers="previewAnswers"
                        id-prefix="preview"
                        :asset="{}"
                    />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
