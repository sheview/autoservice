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

interface ChecklistItem {
    key: string;
    label: string;
    type: string;
}

const props = defineProps<{
    checklist: { id: number; name: string; asset_category_id: number | null; items: ChecklistItem[] } | null;
    categories: { id: number; name: string }[];
    itemTypes: string[];
}>();

const title = props.checklist ? t('pm_checklists.edit') : t('pm_checklists.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_checklists.title'), href: route('maintenance.checklists.index') },
    { title, href: props.checklist ? route('maintenance.checklists.edit', props.checklist.id) : route('maintenance.checklists.create') },
];

const form = useForm({
    name: props.checklist?.name ?? '',
    asset_category_id: props.checklist?.asset_category_id ?? null,
    items: (props.checklist?.items ?? [{ key: 'item_1', label: '', type: 'check' }]).map((item) => ({ ...item })),
});

// A new item gets a free key like item_3; it can be changed.
const addItem = () => {
    let n = form.items.length + 1;
    while (form.items.some((item) => item.key === `item_${n}`)) n++;
    form.items.push({ key: `item_${n}`, label: '', type: 'check' });
};
const removeItem = (index: number) => form.items.splice(index, 1);

const submit = () => {
    if (props.checklist) {
        form.put(route('maintenance.checklists.update', props.checklist.id), { preserveScroll: true });
    } else {
        form.post(route('maintenance.checklists.store'), { preserveScroll: true });
    }
};

const itemError = (index: number, name: string) => (form.errors as Record<string, string>)[`items.${index}.${name}`];

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-8" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="name">{{ t('pm_checklists.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="asset_category_id">{{ t('pm_checklists.category') }}</Label>
                        <select id="asset_category_id" v-model="form.asset_category_id" :class="selectClass">
                            <option :value="null">{{ t('pm_checklists.general') }}</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <InputError :message="form.errors.asset_category_id" />
                    </div>
                </div>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('pm_checklists.items') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ t('pm_checklists.items_hint') }}</p>
                    </div>

                    <div v-for="(item, index) in form.items" :key="index" class="grid gap-4 rounded-md border p-4 sm:grid-cols-12">
                        <div class="grid content-start gap-2 sm:col-span-5">
                            <Label :for="`item_label_${index}`">{{ t('pm_checklists.item_label') }}</Label>
                            <Input :id="`item_label_${index}`" v-model="item.label" required />
                            <InputError :message="itemError(index, 'label')" />
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-3">
                            <Label :for="`item_key_${index}`">{{ t('pm_checklists.item_key') }}</Label>
                            <Input :id="`item_key_${index}`" v-model="item.key" class="font-mono" required pattern="[a-z][a-z0-9_]*" />
                            <p class="text-xs text-muted-foreground">{{ t('pm_checklists.item_key_hint') }}</p>
                            <InputError :message="itemError(index, 'key')" />
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-3">
                            <Label :for="`item_type_${index}`">{{ t('pm_checklists.item_type') }}</Label>
                            <select :id="`item_type_${index}`" v-model="item.type" :class="selectClass">
                                <option v-for="type in itemTypes" :key="type" :value="type">{{ t(`pm_checklists.item_types.${type}`) }}</option>
                            </select>
                        </div>
                        <div class="flex items-start justify-end sm:col-span-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="form.items.length === 1"
                                :aria-label="t('pm_checklists.remove_item')"
                                @click="removeItem(index)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <InputError :message="form.errors.items" />
                    <Button type="button" variant="outline" @click="addItem">{{ t('pm_checklists.add_item') }}</Button>
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('maintenance.checklists.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
