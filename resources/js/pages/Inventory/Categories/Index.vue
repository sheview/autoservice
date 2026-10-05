<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

interface Category {
    id: number;
    name: string;
    track_serial: boolean;
    parts: number;
}

defineProps<{ categories: Category[] }>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('parts.title'), href: route('inventory.parts.index') },
    { title: t('part_categories.title'), href: route('inventory.part-categories.index') },
];

const form = useForm({ name: '', track_serial: false });
const add = () => form.post(route('inventory.part-categories.store'), { preserveScroll: true, onSuccess: () => form.reset() });

const save = (category: Category, changes: Partial<Category>) =>
    router.put(route('inventory.part-categories.update', category.id), { name: category.name, track_serial: category.track_serial, ...changes }, { preserveScroll: true });
const destroy = (category: Category) => {
    if (confirm(t('part_categories.delete_confirm', { name: category.name }))) {
        router.delete(route('inventory.part-categories.destroy', category.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('part_categories.title')" />

        <div class="max-w-3xl space-y-6 p-4">
            <Heading :title="t('part_categories.title')" :description="t('part_categories.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).name" />

            <form class="flex flex-wrap items-end gap-3 rounded-md border p-4" @submit.prevent="add">
                <div class="grid min-w-60 flex-1 gap-2">
                    <label for="name" class="text-sm font-medium">{{ t('part_categories.name') }} <span class="text-red-600">*</span></label>
                    <Input id="name" v-model="form.name" required maxlength="100" :placeholder="t('part_categories.name_placeholder')" />
                </div>
                <label class="flex h-9 items-center gap-2 text-sm">
                    <input v-model="form.track_serial" type="checkbox" class="size-4 rounded border-input" />
                    {{ t('part_categories.track_serial') }}
                </label>
                <Button :disabled="form.processing">{{ t('part_categories.add') }}</Button>
                <InputError class="w-full" :message="form.errors.name" />
            </form>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('part_categories.name') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('part_categories.track_serial') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('part_categories.parts') }}</th>
                            <th class="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="category in categories" :key="category.id" class="border-t">
                            <td class="px-4 py-2">
                                <input
                                    :value="category.name"
                                    maxlength="100"
                                    class="h-8 w-full rounded-md border border-transparent bg-transparent px-2 hover:border-input focus:border-input"
                                    @change="save(category, { name: ($event.target as HTMLInputElement).value })"
                                />
                            </td>
                            <td class="px-4 py-2">
                                <input
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                    :checked="category.track_serial"
                                    @change="save(category, { track_serial: ($event.target as HTMLInputElement).checked })"
                                />
                            </td>
                            <td class="px-4 py-2 text-right">{{ category.parts.toLocaleString('th-TH') }}</td>
                            <td class="px-4 py-2 text-right">
                                <Button variant="ghost" size="sm" class="text-red-600" @click="destroy(category)">{{ t('common.delete') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="categories.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ t('part_categories.none') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
