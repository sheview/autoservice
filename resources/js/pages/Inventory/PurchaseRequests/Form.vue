<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import type { PurchaseRequestRow } from '@/types/purchase';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Link2, Plus, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    request: PurchaseRequestRow | null;
    attachments: Attachment[];
    // From the issue/loan search: what was looked for.
    item: string;
    maxLinks: number;
}>();

const title = props.request ? t('purchase_requests.edit') : t('purchase_requests.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('purchase_requests.title'), href: route('inventory.purchase-requests.index') },
    ...(props.request ? [{ title: props.request.pr_no, href: route('inventory.purchase-requests.show', props.request.ulid) }] : []),
    { title, href: props.request ? route('inventory.purchase-requests.edit', props.request.ulid) : route('inventory.purchase-requests.create') },
];

const form = useForm({
    item_name: props.request?.item_name ?? props.item,
    description: props.request?.description ?? '',
    quantity: props.request?.quantity ?? 1,
    unit: props.request?.unit ?? t('purchase_requests.unit_default'),
    unit_price: props.request?.unit_price ?? '',
    links: (props.request?.links.length ? [...props.request.links] : ['']) as string[],
    reason: props.request?.reason ?? '',
    needed_by: props.request?.needed_by ?? '',
    attachments: [] as File[],
});

const total = computed(() => {
    const price = Number(form.unit_price);
    return form.unit_price === '' || Number.isNaN(price)
        ? null
        : (price * Number(form.quantity || 0)).toLocaleString('th-TH', { minimumFractionDigits: 2 });
});

const addLink = () => form.links.length < props.maxLinks && form.links.push('');
const removeLink = (index: number) => {
    form.links.splice(index, 1);
    if (form.links.length === 0) form.links.push('');
};

const submit = () => {
    // Empty link boxes are not sent; an edit with files is a POST that says PUT.
    const transformed = form.transform((data) => ({
        ...data,
        links: data.links.map((link) => link.trim()).filter(Boolean),
        ...(props.request ? { _method: 'put' } : {}),
    }));
    if (props.request) {
        transformed.post(route('inventory.purchase-requests.update', props.request.ulid));
    } else {
        transformed.post(route('inventory.purchase-requests.store'));
    }
};

const errors = computed(() => form.errors as Record<string, string>);
const linkError = (index: number) => errors.value[`links.${index}`];
const today = new Date().toLocaleDateString('sv-SE');
const textareaClass = 'shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" :description="t('purchase_requests.description')" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="item_name">{{ t('purchase_requests.item_name') }}<span class="text-red-600"> *</span></Label>
                        <Input id="item_name" v-model="form.item_name" required :placeholder="t('purchase_requests.item_placeholder')" />
                        <InputError :message="form.errors.item_name" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="quantity">{{ t('purchase_requests.quantity') }}<span class="text-red-600"> *</span></Label>
                        <Input id="quantity" v-model.number="form.quantity" type="number" min="1" step="1" required />
                        <InputError :message="form.errors.quantity" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="unit">{{ t('purchase_requests.unit') }}<span class="text-red-600"> *</span></Label>
                        <Input id="unit" v-model="form.unit" required maxlength="30" />
                        <InputError :message="form.errors.unit" />
                    </div>

                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="description">{{ t('purchase_requests.description_field') }}</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            :placeholder="t('purchase_requests.description_placeholder')"
                            :class="textareaClass"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="unit_price">{{ t('purchase_requests.unit_price') }}</Label>
                        <Input id="unit_price" v-model="form.unit_price" type="number" min="0" step="0.01" />
                        <p v-if="total" class="text-xs text-muted-foreground">{{ t('purchase_requests.total') }} {{ total }}</p>
                        <InputError :message="form.errors.unit_price" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="needed_by">{{ t('purchase_requests.needed_by') }}<span class="text-red-600"> *</span></Label>
                        <Input id="needed_by" v-model="form.needed_by" type="date" required :min="request ? undefined : today" />
                        <InputError :message="form.errors.needed_by" />
                    </div>
                </div>

                <section class="space-y-2">
                    <Label>{{ t('purchase_requests.links') }}<span class="text-red-600"> *</span></Label>
                    <p class="text-xs text-muted-foreground">{{ t('purchase_requests.links_hint', { max: maxLinks }) }}</p>
                    <div v-for="(link, index) in form.links" :key="index" class="space-y-1">
                        <div class="flex items-center gap-2">
                            <Link2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                            <Input
                                v-model="form.links[index]"
                                type="url"
                                inputmode="url"
                                :required="index === 0"
                                :placeholder="t('purchase_requests.link_placeholder')"
                                :aria-label="`${t('purchase_requests.links')} ${index + 1}`"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="t('purchase_requests.remove_link')"
                                @click="removeLink(index)"
                            >
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                        <InputError :message="linkError(index)" />
                    </div>
                    <InputError :message="form.errors.links" />
                    <Button type="button" variant="outline" size="sm" :disabled="form.links.length >= maxLinks" @click="addLink">
                        <Plus class="h-4 w-4" />
                        {{ t('purchase_requests.add_link') }}
                    </Button>
                </section>

                <div class="grid content-start gap-2">
                    <Label for="reason">{{ t('purchase_requests.reason') }}<span class="text-red-600"> *</span></Label>
                    <textarea id="reason" v-model="form.reason" rows="3" required :class="textareaClass" />
                    <InputError :message="form.errors.reason" />
                </div>

                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('purchase_requests.quotations') }}</h3>
                    <AttachmentList v-if="request" :attachments="attachments" :title="false" can-delete />
                    <AttachmentPicker v-model="form.attachments" :errors="form.errors" />
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="request ? route('inventory.purchase-requests.show', request.ulid) : route('inventory.purchase-requests.index')">
                            {{ t('common.cancel') }}
                        </Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
