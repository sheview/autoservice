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
import { Link2, Plus, Trash2, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    request: PurchaseRequestRow | null;
    attachments: Attachment[];
    // From the issue/loan search: what was looked for, and the request (a saved draft) it was looked for on.
    item: string;
    source: { id: number; ulid: string; request_no: string } | null;
    // Asset categories, for what it goes into the system as; none = assets are off.
    categories: { id: number; name: string }[];
    maxLinks: number;
    // Projects (MA contracts) the purchase can be for; none = the field is hidden.
    contracts: { id: number; label: string }[];
}>();

const title = props.request ? t('purchase_requests.edit') : t('purchase_requests.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('purchase_requests.title'), href: route('inventory.purchase-requests.index') },
    ...(props.request ? [{ title: props.request.pr_no, href: route('inventory.purchase-requests.show', props.request.ulid) }] : []),
    { title, href: props.request ? route('inventory.purchase-requests.edit', props.request.ulid) : route('inventory.purchase-requests.create') },
];

type ExtraItem = {
    item_name: string;
    quantity: number;
    unit: string;
    unit_price: string | number;
    description: string;
    links: string[];
    item_kind: 'asset' | 'part' | null;
    asset_category_id: number | null;
};

const form = useForm({
    item_name: props.request?.item_name ?? props.item,
    contract_id: props.request?.contract_id ?? (null as number | null),
    description: props.request?.description ?? '',
    quantity: props.request?.quantity ?? 1,
    unit: props.request?.unit ?? t('purchase_requests.unit_default'),
    unit_price: props.request?.unit_price ?? '',
    links: (props.request?.links.length ? [...props.request.links] : ['']) as string[],
    reason: props.request?.reason ?? '',
    needed_by: props.request?.needed_by ?? '',
    checkout_request_id: props.source?.id ?? null,
    item_kind: (props.request?.item_kind ?? null) as 'asset' | 'part' | null,
    asset_category_id: props.request?.asset_category_id ?? (null as number | null),
    attachments: [] as File[],
    // More items asked for on the same form (a new request only): each becomes its own request in one batch.
    extra_items: [] as ExtraItem[],
});

// Up to 20 items on one form (the first one plus 19 more).
const maxExtra = 19;
const canAddItems = computed(() => !props.request && !props.source);
const addItem = () =>
    form.extra_items.length < maxExtra &&
    form.extra_items.push({
        item_name: '',
        quantity: 1,
        unit: t('purchase_requests.unit_default'),
        unit_price: '',
        description: '',
        links: [''],
        item_kind: null,
        asset_category_id: null,
    });
const removeItem = (index: number) => form.extra_items.splice(index, 1);
const addExtraLink = (item: ExtraItem) => item.links.length < props.maxLinks && item.links.push('');
const removeExtraLink = (item: ExtraItem, index: number) => {
    item.links.splice(index, 1);
    if (item.links.length === 0) item.links.push('');
};

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
    // The issue/loan request it was asked from is set only when it is opened.
    const transformed = form.transform(({ checkout_request_id, extra_items, ...data }) => ({
        ...data,
        links: data.links.map((link) => link.trim()).filter(Boolean),
        ...(props.request
            ? { _method: 'put' }
            : {
                  checkout_request_id,
                  extra_items: extra_items.map((item) => ({ ...item, links: item.links.map((link) => link.trim()).filter(Boolean) })),
              }),
    }));
    if (props.request) {
        transformed.post(route('inventory.purchase-requests.update', props.request.ulid));
    } else {
        transformed.post(route('inventory.purchase-requests.store'));
    }
};

const errors = computed(() => form.errors as Record<string, string>);
const linkError = (index: number) => errors.value[`links.${index}`];
const itemError = (index: number, field: string) => errors.value[`extra_items.${index}.${field}`];
const today = new Date().toLocaleDateString('sv-SE');
const textareaClass = 'shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" :description="t('purchase_requests.description')" />

            <p v-if="source" class="mb-4 rounded-md bg-muted/60 px-4 py-2 text-sm">
                {{ t('purchase_requests.from_checkout') }}
                <Link :href="route('asset.requests.show', source.ulid)" class="font-mono text-primary underline-offset-4 hover:underline">
                    {{ source.request_no }}
                </Link>
            </p>

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
                    <div v-if="contracts.length" class="grid content-start gap-2 sm:col-span-2">
                        <Label for="contract_id">{{ t('purchase_requests.contract') }}</Label>
                        <select
                            id="contract_id"
                            v-model="form.contract_id"
                            class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null">{{ t('purchase_requests.no_contract') }}</option>
                            <option v-for="contract in contracts" :key="contract.id" :value="contract.id">{{ contract.label }}</option>
                        </select>
                        <InputError :message="form.errors.contract_id" />
                    </div>
                </div>

                <!-- What it goes into the system as when it arrives -->
                <section class="space-y-2">
                    <Label>{{ t('purchase_requests.kind.label') }}</Label>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"
                            ><input v-model="form.item_kind" type="radio" :value="null" /> {{ t('purchase_requests.kind.none') }}</label
                        >
                        <label v-if="categories.length" class="flex items-center gap-2"
                            ><input v-model="form.item_kind" type="radio" value="asset" /> {{ t('purchase_requests.kind.asset') }}</label
                        >
                        <label class="flex items-center gap-2"
                            ><input v-model="form.item_kind" type="radio" value="part" /> {{ t('purchase_requests.kind.part') }}</label
                        >
                    </div>
                    <div v-if="form.item_kind === 'asset'" class="max-w-sm">
                        <select
                            v-model="form.asset_category_id"
                            required
                            :aria-label="t('purchase_requests.kind.category')"
                            class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option :value="null" disabled>{{ t('purchase_requests.kind.choose_category') }}</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <InputError :message="form.errors.asset_category_id" />
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('purchase_requests.kind.hint') }}</p>
                    <InputError :message="form.errors.item_kind" />
                </section>

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

                <!-- More items on the same form: each goes to the approver as its own request, decided together. -->
                <section v-if="canAddItems" class="space-y-4">
                    <div v-for="(item, index) in form.extra_items" :key="index" class="space-y-4 rounded-md border p-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold">{{ t('purchase_requests.item_n', { n: index + 2 }) }}</h3>
                            <Button type="button" variant="ghost" size="sm" @click="removeItem(index)">
                                <Trash2 class="h-4 w-4" />
                                {{ t('purchase_requests.remove_item') }}
                            </Button>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="grid content-start gap-2 sm:col-span-2">
                                <Label :for="`extra_${index}_name`">{{ t('purchase_requests.item_name') }}<span class="text-red-600"> *</span></Label>
                                <Input :id="`extra_${index}_name`" v-model="item.item_name" required />
                                <InputError :message="itemError(index, 'item_name')" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label :for="`extra_${index}_qty`">{{ t('purchase_requests.quantity') }}<span class="text-red-600"> *</span></Label>
                                <Input :id="`extra_${index}_qty`" v-model.number="item.quantity" type="number" min="1" step="1" required />
                                <InputError :message="itemError(index, 'quantity')" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label :for="`extra_${index}_unit`">{{ t('purchase_requests.unit') }}<span class="text-red-600"> *</span></Label>
                                <Input :id="`extra_${index}_unit`" v-model="item.unit" required maxlength="30" />
                                <InputError :message="itemError(index, 'unit')" />
                            </div>
                            <div class="grid content-start gap-2 sm:col-span-2">
                                <Label :for="`extra_${index}_desc`">{{ t('purchase_requests.description_field') }}</Label>
                                <Input :id="`extra_${index}_desc`" v-model="item.description" />
                                <InputError :message="itemError(index, 'description')" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label :for="`extra_${index}_price`">{{ t('purchase_requests.unit_price') }}</Label>
                                <Input :id="`extra_${index}_price`" v-model="item.unit_price" type="number" min="0" step="0.01" />
                                <InputError :message="itemError(index, 'unit_price')" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label :for="`extra_${index}_kind`">{{ t('purchase_requests.kind.label') }}</Label>
                                <select
                                    :id="`extra_${index}_kind`"
                                    v-model="item.item_kind"
                                    class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                >
                                    <option :value="null">{{ t('purchase_requests.kind.none') }}</option>
                                    <option v-if="categories.length" value="asset">{{ t('purchase_requests.kind.asset') }}</option>
                                    <option value="part">{{ t('purchase_requests.kind.part') }}</option>
                                </select>
                                <select
                                    v-if="item.item_kind === 'asset'"
                                    v-model="item.asset_category_id"
                                    required
                                    :aria-label="t('purchase_requests.kind.category')"
                                    class="shadow-xs h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                >
                                    <option :value="null" disabled>{{ t('purchase_requests.kind.choose_category') }}</option>
                                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                                </select>
                                <InputError :message="itemError(index, 'asset_category_id')" />
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label>{{ t('purchase_requests.links') }}<span class="text-red-600"> *</span></Label>
                            <div v-for="(link, linkIndex) in item.links" :key="linkIndex" class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <Link2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <Input
                                        v-model="item.links[linkIndex]"
                                        type="url"
                                        inputmode="url"
                                        :required="linkIndex === 0"
                                        :placeholder="t('purchase_requests.link_placeholder')"
                                        :aria-label="`${t('purchase_requests.links')} ${linkIndex + 1}`"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="t('purchase_requests.remove_link')"
                                        @click="removeExtraLink(item, linkIndex)"
                                    >
                                        <X class="h-4 w-4" />
                                    </Button>
                                </div>
                                <InputError :message="itemError(index, `links.${linkIndex}`)" />
                            </div>
                            <InputError :message="itemError(index, 'links')" />
                            <Button type="button" variant="outline" size="sm" :disabled="item.links.length >= maxLinks" @click="addExtraLink(item)">
                                <Plus class="h-4 w-4" />
                                {{ t('purchase_requests.add_link') }}
                            </Button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" :disabled="form.extra_items.length >= maxExtra" @click="addItem">
                            <Plus class="h-4 w-4" />
                            {{ t('purchase_requests.add_item') }}
                        </Button>
                        <p class="text-xs text-muted-foreground">{{ t('purchase_requests.add_item_hint') }}</p>
                    </div>
                    <InputError :message="errors.extra_items" />
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
