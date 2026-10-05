<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import CustomerSites from '@/components/CustomerSites.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface CustomerForm {
    id: number;
    code: string;
    name: string;
    short_name: string | null;
    tax_id: string | null;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    notes: string | null;
    require_signature?: boolean;
}

// attachments: files already attached (editing).
// sites: the customer's places (editing only; saved on their own).
const props = defineProps<{
    customer: CustomerForm | null;
    attachments: Attachment[];
    sites: { id: number; name: string; address: string | null }[];
}>();

const title = props.customer ? t('customers.edit') : t('customers.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('customers.title'), href: route('contract.customers.index') },
    { title, href: props.customer ? route('contract.customers.edit', props.customer.id) : route('contract.customers.create') },
];

const form = useForm({
    code: props.customer?.code ?? '',
    name: props.customer?.name ?? '',
    short_name: props.customer?.short_name ?? '',
    tax_id: props.customer?.tax_id ?? '',
    contact_name: props.customer?.contact_name ?? '',
    phone: props.customer?.phone ?? '',
    email: props.customer?.email ?? '',
    address: props.customer?.address ?? '',
    notes: props.customer?.notes ?? '',
    require_signature: props.customer?.require_signature ?? false,
    attachments: [] as File[],
});

const submit = () => {
    if (props.customer) {
        // Files go as multipart, which PHP only reads on POST: send a POST that says PUT.
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('contract.customers.update', props.customer.id));
    } else {
        form.post(route('contract.customers.store'));
    }
};

const textareaClass = 'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="code" required>{{ t('customers.code') }}</Label>
                        <Input id="code" v-model="form.code" class="font-mono uppercase" required maxlength="30" autocomplete="off" />
                        <p class="text-xs text-muted-foreground">{{ t('customers.code_hint') }}</p>
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="name" required>{{ t('customers.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="short_name">{{ t('customers.short_name') }}</Label>
                        <Input
                            id="short_name"
                            v-model="form.short_name"
                            maxlength="50"
                            autocomplete="off"
                            :placeholder="t('customers.short_name_placeholder')"
                        />
                        <p class="text-xs text-muted-foreground">{{ t('customers.short_name_hint') }}</p>
                        <InputError :message="form.errors.short_name" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="tax_id">{{ t('customers.tax_id') }}</Label>
                        <Input id="tax_id" v-model="form.tax_id" class="font-mono" maxlength="20" />
                        <InputError :message="form.errors.tax_id" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="contact_name">{{ t('customers.contact_name') }}</Label>
                        <Input id="contact_name" v-model="form.contact_name" />
                        <InputError :message="form.errors.contact_name" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="phone">{{ t('customers.phone') }}</Label>
                        <Input id="phone" v-model="form.phone" type="tel" />
                        <InputError :message="form.errors.phone" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="email">{{ t('customers.email') }}</Label>
                        <Input id="email" v-model="form.email" type="email" />
                        <InputError :message="form.errors.email" />
                    </div>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="address">{{ t('customers.address') }}</Label>
                    <textarea id="address" v-model="form.address" rows="3" :class="textareaClass" />
                    <InputError :message="form.errors.address" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="notes">{{ t('customers.notes') }}</Label>
                    <textarea id="notes" v-model="form.notes" rows="3" :class="textareaClass" />
                    <InputError :message="form.errors.notes" />
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.require_signature" type="checkbox" class="mt-0.5 size-4" />
                    <span>
                        {{ t('customers.require_signature') }}
                        <span class="block text-xs text-muted-foreground">{{ t('customers.require_signature_hint') }}</span>
                    </span>
                </label>

                <section class="space-y-2">
                    <AttachmentList v-if="customer" :attachments="attachments" can-delete />
                    <h3 v-else class="text-sm font-semibold">{{ t('attachments.title') }}</h3>
                    <AttachmentPicker v-model="form.attachments" :errors="form.errors" />
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('contract.customers.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>

            <CustomerSites v-if="customer" class="mt-10" :customer-id="customer.id" :sites="sites" />
        </div>
    </AppLayout>
</template>
