<script setup lang="ts">
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Link2, Plus, X } from 'lucide-vue-next';
import { computed } from 'vue';

interface ManualForm {
    id: number;
    title: string;
    category: string | null;
    description: string | null;
    links: { label: string | null; url: string }[];
}

const props = defineProps<{
    manual: ManualForm | null;
    categories: string[];
    maxLinks: number;
    maxMb: number;
}>();

const title = props.manual ? t('manuals.edit') : t('manuals.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('manuals.title'), href: route('document.manuals.index') },
    ...(props.manual ? [{ title: props.manual.title, href: route('document.manuals.show', props.manual.id) }] : []),
    { title, href: props.manual ? route('document.manuals.edit', props.manual.id) : route('document.manuals.create') },
];

const form = useForm({
    title: props.manual?.title ?? '',
    category: props.manual?.category ?? '',
    description: props.manual?.description ?? '',
    links: (props.manual?.links.length ? props.manual.links.map((l) => ({ label: l.label ?? '', url: l.url })) : [{ label: '', url: '' }]) as {
        label: string;
        url: string;
    }[],
    attachments: [] as File[],
});

const addLink = () => form.links.length < props.maxLinks && form.links.push({ label: '', url: '' });
const removeLink = (index: number) => {
    form.links.splice(index, 1);
    if (form.links.length === 0) form.links.push({ label: '', url: '' });
};

// Files go as multipart, which PHP reads only on POST: an edit is a POST that says PUT.
const submit = () => {
    const sent = form.transform((data) => ({
        ...data,
        links: data.links.filter((link) => link.url.trim() !== ''),
        ...(props.manual ? { _method: 'put' } : {}),
    }));
    sent.post(props.manual ? route('document.manuals.update', props.manual.id) : route('document.manuals.store'), { forceFormData: true });
};

const errors = computed(() => form.errors as Record<string, string>);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="max-w-3xl p-4">
            <Heading :title="title" :description="t('manuals.description')" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-3">
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="title">{{ t('manuals.name') }}<span class="text-red-600"> *</span></Label>
                        <Input id="title" v-model="form.title" required maxlength="255" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="category">{{ t('manuals.category') }}</Label>
                        <Input
                            id="category"
                            v-model="form.category"
                            list="manual-categories"
                            maxlength="100"
                            :placeholder="t('manuals.category_placeholder')"
                        />
                        <datalist id="manual-categories">
                            <option v-for="category in categories" :key="category" :value="category" />
                        </datalist>
                        <InputError :message="form.errors.category" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-3">
                        <Label for="description">{{ t('manuals.description_field') }}</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </div>

                <section class="space-y-2">
                    <Label>{{ t('manuals.links') }}</Label>
                    <p class="text-xs text-muted-foreground">{{ t('manuals.links_hint', { max: maxLinks }) }}</p>
                    <div v-for="(link, index) in form.links" :key="index" class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                            <Input
                                v-model="link.label"
                                class="w-48"
                                :placeholder="t('manuals.link_label')"
                                :aria-label="`${t('manuals.link_label')} ${index + 1}`"
                            />
                            <Input
                                v-model="link.url"
                                type="url"
                                inputmode="url"
                                class="min-w-0 flex-1"
                                :placeholder="t('manuals.link_url')"
                                :aria-label="`${t('manuals.links')} ${index + 1}`"
                            />
                            <Button type="button" variant="ghost" size="icon" :aria-label="t('common.delete')" @click="removeLink(index)">
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                        <InputError :message="errors[`links.${index}.url`] ?? errors[`links.${index}.label`]" />
                    </div>
                    <Button type="button" variant="outline" size="sm" :disabled="form.links.length >= maxLinks" @click="addLink">
                        <Plus class="h-4 w-4" />{{ t('manuals.add_link') }}
                    </Button>
                </section>

                <section class="space-y-2">
                    <Label>{{ t('manuals.files') }}</Label>
                    <AttachmentPicker v-model="form.attachments" images :max-mb="maxMb" :errors="errors" />
                </section>

                <div class="flex gap-2">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="manual ? route('document.manuals.show', manual.id) : route('document.manuals.index')">{{
                            t('common.cancel')
                        }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
