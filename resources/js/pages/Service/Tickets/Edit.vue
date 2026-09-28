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

const props = defineProps<{
    ticket: {
        ulid: string;
        ticket_no: string;
        title: string;
        description: string | null;
        priority: string;
        source: string;
        contact_name: string | null;
        contact_phone: string | null;
    };
    outOfContract: boolean;
    priorities: string[];
    sources: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('tickets.title'), href: route('service.tickets.index') },
    { title: props.ticket.ticket_no, href: route('service.tickets.show', props.ticket.ulid) },
    { title: t('tickets.edit'), href: route('service.tickets.edit', props.ticket.ulid) },
];

const form = useForm({
    title: props.ticket.title,
    description: props.ticket.description ?? '',
    priority: props.ticket.priority,
    source: props.ticket.source,
    contact_name: props.ticket.contact_name ?? '',
    contact_phone: props.ticket.contact_phone ?? '',
});

const submit = () => form.put(route('service.tickets.update', props.ticket.ulid));

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('tickets.edit')" />

        <div class="max-w-3xl p-4">
            <Heading :title="`${t('tickets.edit')} ${ticket.ticket_no}`" />

            <form class="grid gap-6 sm:grid-cols-2" @submit.prevent="submit">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="title">{{ t('tickets.title_field') }}</Label>
                    <Input id="title" v-model="form.title" required />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">{{ t('tickets.description_field') }}</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="4"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-2">
                    <Label for="priority">{{ t('tickets.priority') }}</Label>
                    <select id="priority" v-model="form.priority" :class="selectClass">
                        <option v-for="priority in priorities" :key="priority" :value="priority">{{ t(`tickets.priorities.${priority}`) }}</option>
                    </select>
                    <InputError :message="form.errors.priority" />
                </div>

                <div class="grid gap-2">
                    <Label for="source">{{ t('tickets.source') }}</Label>
                    <select id="source" v-model="form.source" :class="selectClass">
                        <option v-for="source in sources" :key="source" :value="source">{{ t(`tickets.sources.${source}`) }}</option>
                    </select>
                    <InputError :message="form.errors.source" />
                </div>

                <div class="grid gap-2">
                    <Label for="contact_name">{{ t('tickets.contact_name') }}</Label>
                    <Input id="contact_name" v-model="form.contact_name" />
                    <InputError :message="form.errors.contact_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="contact_phone">{{ t('tickets.contact_phone') }}</Label>
                    <Input id="contact_phone" v-model="form.contact_phone" type="tel" />
                    <InputError :message="form.errors.contact_phone" />
                </div>

                <div class="flex items-center gap-3 sm:col-span-2">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('service.tickets.show', ticket.ulid)">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
