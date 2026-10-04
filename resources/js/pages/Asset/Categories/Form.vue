<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface SpecField {
    key: string;
    label: string;
    type: string;
    options: string[];
    required: boolean;
}

const props = defineProps<{
    category: {
        id: number;
        name: string;
        code_prefix: string;
        service_line: string | null;
        asset_type: string;
        requires_serial: boolean;
        spec_fields: SpecField[];
    } | null;
    serviceLines: string[];
    assetTypes: string[];
    fieldTypes: string[];
}>();

const title = props.category ? t('asset_categories.edit') : t('asset_categories.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('asset_categories.title'), href: route('asset.categories.index') },
    { title, href: props.category ? route('asset.categories.edit', props.category.id) : route('asset.categories.create') },
];

// Options are edited as one comma separated text box per field.
const form = useForm({
    name: props.category?.name ?? '',
    code_prefix: props.category?.code_prefix ?? '',
    service_line: props.category?.service_line ?? null,
    asset_type: props.category?.asset_type ?? 'hardware',
    requires_serial: props.category?.requires_serial ?? false,
    spec_fields: (props.category?.spec_fields ?? []).map((field) => ({ ...field, optionsText: field.options.join(', ') })),
});

const addField = () => form.spec_fields.push({ key: '', label: '', type: 'text', options: [], required: false, optionsText: '' });
const removeField = (index: number) => form.spec_fields.splice(index, 1);

const submit = () => {
    const transformed = form.transform((data) => ({
        ...data,
        spec_fields: data.spec_fields.map(({ optionsText, ...field }) => ({
            ...field,
            options:
                field.type === 'select'
                    ? optionsText
                          .split(',')
                          .map((o) => o.trim())
                          .filter(Boolean)
                    : [],
        })),
    }));

    if (props.category) {
        transformed.put(route('asset.categories.update', props.category.id), { preserveScroll: true });
    } else {
        transformed.post(route('asset.categories.store'), { preserveScroll: true });
    }
};

// The prefix follows the name (its English letters, up to 4) until it is typed in by hand;
// a Thai name leaves it empty and the server makes one (CAT, CAT2, ...).
const prefixTyped = ref(!!props.category);
watch(
    () => form.name,
    (name) => {
        if (!prefixTyped.value)
            form.code_prefix = name
                .replace(/[^A-Za-z0-9]/g, '')
                .slice(0, 4)
                .toUpperCase();
    },
);

const fieldError = (index: number, name: string) => (form.errors as Record<string, string>)[`spec_fields.${index}.${name}`];

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-8" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="grid content-start gap-2">
                        <Label for="name" required>{{ t('asset_categories.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="code_prefix">{{ t('asset_categories.code_prefix') }}</Label>
                        <Input
                            id="code_prefix"
                            v-model="form.code_prefix"
                            class="font-mono uppercase"
                            maxlength="10"
                            autocomplete="off"
                            :placeholder="t('asset_categories.code_prefix_auto')"
                            @input="prefixTyped = true"
                        />
                        <p class="text-xs text-muted-foreground">{{ t('asset_categories.code_prefix_hint') }}</p>
                        <InputError :message="form.errors.code_prefix" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="service_line">{{ t('asset_categories.service_line') }}</Label>
                        <select id="service_line" v-model="form.service_line" :class="selectClass">
                            <option :value="null">{{ t('asset_categories.no_service_line') }}</option>
                            <option v-for="line in serviceLines" :key="line" :value="line">{{ t(`service_lines.${line}`) }}</option>
                        </select>
                        <InputError :message="form.errors.service_line" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="asset_type" required>{{ t('asset_categories.asset_type') }}</Label>
                        <select id="asset_type" v-model="form.asset_type" required :class="selectClass">
                            <option v-for="type in assetTypes" :key="type" :value="type">{{ t(`asset_categories.asset_types.${type}`) }}</option>
                        </select>
                        <InputError :message="form.errors.asset_type" />
                    </div>
                    <div class="grid content-start gap-1 sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.requires_serial" type="checkbox" class="size-4 rounded border-input" />
                            {{ t('asset_categories.requires_serial') }}
                        </label>
                        <p class="text-xs text-muted-foreground">{{ t('asset_categories.requires_serial_hint') }}</p>
                        <InputError :message="form.errors.requires_serial" />
                    </div>
                </div>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('asset_categories.spec_fields') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ t('asset_categories.spec_fields_hint') }}</p>
                    </div>

                    <div v-for="(field, index) in form.spec_fields" :key="index" class="grid gap-4 rounded-md border p-4 sm:grid-cols-12">
                        <div class="grid content-start gap-2 sm:col-span-3">
                            <Label :for="`field_label_${index}`" required>{{ t('asset_categories.field_label') }}</Label>
                            <Input :id="`field_label_${index}`" v-model="field.label" required />
                            <InputError :message="fieldError(index, 'label')" />
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-3">
                            <Label :for="`field_key_${index}`" required>{{ t('asset_categories.field_key') }}</Label>
                            <Input :id="`field_key_${index}`" v-model="field.key" class="font-mono" required pattern="[a-z][a-z0-9_]*" />
                            <p class="text-xs text-muted-foreground">{{ t('asset_categories.field_key_hint') }}</p>
                            <InputError :message="fieldError(index, 'key')" />
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-2">
                            <Label :for="`field_type_${index}`" required>{{ t('asset_categories.field_type') }}</Label>
                            <select :id="`field_type_${index}`" v-model="field.type" :class="selectClass">
                                <option v-for="type in fieldTypes" :key="type" :value="type">{{ t(`asset_categories.field_types.${type}`) }}</option>
                            </select>
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-3">
                            <template v-if="field.type === 'select'">
                                <Label :for="`field_options_${index}`" :required="field.type === 'select'">{{
                                    t('asset_categories.field_options')
                                }}</Label>
                                <Input :id="`field_options_${index}`" v-model="field.optionsText" required />
                                <InputError :message="fieldError(index, 'options')" />
                            </template>
                            <label class="flex items-center gap-2 text-sm" :class="{ 'mt-7': field.type !== 'select' }">
                                <input v-model="field.required" type="checkbox" class="size-4 rounded border-input" />
                                {{ t('asset_categories.field_required') }}
                            </label>
                        </div>
                        <div class="flex items-start justify-end sm:col-span-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="t('asset_categories.remove_field')"
                                @click="removeField(index)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <InputError :message="form.errors.spec_fields" />
                    <Button type="button" variant="outline" @click="addField">{{ t('asset_categories.add_field') }}</Button>
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('asset.categories.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
