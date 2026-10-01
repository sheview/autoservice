<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PurchaseStatusBadge from '@/components/PurchaseStatusBadge.vue';
import StepProgress from '@/components/StepProgress.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { purchaseSteps } from '@/lib/purchaseSteps';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { PurchaseRequestRow } from '@/types/purchase';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ExternalLink, PackagePlus, Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    request: PurchaseRequestRow;
    contract: { id: number; contract_no: string; title: string } | null;
    attachments: Attachment[];
    actions: string[];
    needsNote: string[];
    can: { update: boolean; attach: boolean; createAsset: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('purchase_requests.title'), href: route('inventory.purchase-requests.index') },
    { title: props.request.pr_no, href: route('inventory.purchase-requests.show', props.request.ulid) },
];

// --- moves: the note box opens for the chosen move; a rejection needs it --------
const pending = ref<string | null>(null);
const move = useForm({ action: '', note: '' });
const confirm = () => {
    move.action = pending.value ?? '';
    move.post(route('inventory.purchase-requests.move', props.request.ulid), {
        preserveScroll: true,
        onSuccess: () => {
            pending.value = null;
            move.reset();
        },
    });
};
const actionVariant = (action: string) => (['reject', 'cancel'].includes(action) ? 'outline' : 'default');

// --- links: shown with their site, so a reader sees where they lead -----------
const host = (url: string) => {
    try {
        return new URL(url).hostname;
    } catch {
        return url;
    }
};

// --- progress ------------------------------------------------------------------
const stepBar = computed(() => purchaseSteps(props.request));

const money = (baht: string | null) => (baht === null ? '-' : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${request.pr_no} ${request.item_name}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="flex items-center gap-2 font-mono text-sm text-muted-foreground">
                        {{ request.pr_no }}
                        <PurchaseStatusBadge :status="request.status" />
                    </p>
                    <Heading :title="request.item_name" :description="`${request.quantity} ${request.unit}`" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <a :href="route('inventory.purchase-requests.print', request.ulid)" target="_blank" rel="noopener">
                            <Printer class="h-4 w-4" />
                            {{ t('purchase_requests.print') }}
                        </a>
                    </Button>
                    <Button variant="ghost" as-child>
                        <a :href="route('inventory.purchase-requests.pdf', request.ulid)" target="_blank" rel="noopener">{{
                            t('purchase_requests.pdf')
                        }}</a>
                    </Button>
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('inventory.purchase-requests.edit', request.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                </div>
            </div>

            <StepProgress :steps="stepBar.steps" :current="stepBar.current" :state="stepBar.state" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>

            <!-- What the user may do now -->
            <div v-if="actions.length" class="space-y-3 rounded-md border p-4">
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="action in actions"
                        :key="action"
                        size="sm"
                        :variant="actionVariant(action)"
                        @click="pending = pending === action ? null : action"
                    >
                        {{ t(`purchase_requests.actions.${action}`) }}
                    </Button>
                </div>
                <form v-if="pending" class="space-y-2" @submit.prevent="confirm">
                    <label for="note" class="text-sm font-medium"
                        >{{ t(`purchase_requests.actions.${pending}`) }} — {{ t(`purchase_requests.notes.${pending}`) }}</label
                    >
                    <textarea
                        id="note"
                        v-model="move.note"
                        rows="2"
                        :required="needsNote.includes(pending)"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="move.errors.note ?? move.errors.action" />
                    <div class="flex gap-2">
                        <Button size="sm" :variant="pending === 'reject' ? 'destructive' : 'default'" :disabled="move.processing">
                            {{ t('purchase_requests.confirm') }}
                        </Button>
                        <Button size="sm" type="button" variant="ghost" @click="pending = null">{{ t('common.cancel') }}</Button>
                    </div>
                </form>
            </div>

            <!-- Received: register it -->
            <div
                v-if="can.createAsset"
                class="flex flex-wrap items-center gap-3 rounded-md border border-green-300 bg-green-50 p-4 text-sm dark:border-green-900 dark:bg-green-950"
            >
                <span class="flex-1">{{ t('purchase_requests.create_asset_hint') }}</span>
                <Button as-child>
                    <Link :href="route('asset.assets.create', { purchase_request: request.ulid })">
                        <PackagePlus class="h-4 w-4" />
                        {{ t('purchase_requests.create_asset') }}
                    </Link>
                </Button>
            </div>

            <dl class="grid gap-x-6 gap-y-4 rounded-md border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.requested_by') }}</dt>
                    <dd>{{ request.requested_by_name }} · {{ dateTime(request.requested_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.needed_by') }}</dt>
                    <dd>{{ request.needed_by ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.unit_price') }}</dt>
                    <dd class="tabular-nums">{{ money(request.unit_price) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.total') }}</dt>
                    <dd class="font-semibold tabular-nums">{{ money(request.total) }}</dd>
                </div>
                <div v-if="contract" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.contract') }}</dt>
                    <dd>{{ contract.contract_no }} · {{ contract.title }}</dd>
                </div>
                <div v-if="request.description" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.description_field') }}</dt>
                    <dd class="whitespace-pre-line">{{ request.description }}</dd>
                </div>
                <div v-if="request.reason" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.reason') }}</dt>
                    <dd class="whitespace-pre-line">{{ request.reason }}</dd>
                </div>
            </dl>

            <section v-if="request.links.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.links') }}</h3>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="link in request.links" :key="link" class="flex items-center gap-2 px-4 py-2">
                        <ExternalLink class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <a
                            :href="link"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="min-w-0 flex-1 truncate text-primary underline-offset-4 hover:underline"
                        >
                            {{ link }}
                        </a>
                        <span class="shrink-0 rounded bg-muted px-1.5 text-xs text-muted-foreground">{{ host(link) }}</span>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">{{ t('purchase_requests.link_warning') }}</p>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.quotations') }}</h3>
                <AttachmentList
                    :attachments="attachments"
                    :title="false"
                    :can-delete="can.attach"
                    :upload-url="can.attach ? route('inventory.purchase-requests.attachments.store', request.ulid) : null"
                />
            </section>

            <!-- Who decided, ordered and received, with their notes -->
            <section v-if="request.decided_by_name || request.ordered_by_name || request.received_by_name" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.timeline') }}</h3>
                <ul class="space-y-2 border-l pl-4 text-sm">
                    <li v-if="request.decided_by_name">
                        <span class="font-medium">{{ t('purchase_requests.step_decided') }}</span>
                        <span class="text-xs text-muted-foreground"> · {{ request.decided_by_name }} · {{ dateTime(request.decided_at) }}</span>
                        <p v-if="request.decision_note" class="whitespace-pre-line text-muted-foreground">{{ request.decision_note }}</p>
                    </li>
                    <li v-if="request.ordered_by_name">
                        <span class="font-medium">{{ t('purchase_requests.step_ordered') }}</span>
                        <span class="text-xs text-muted-foreground"> · {{ request.ordered_by_name }} · {{ dateTime(request.ordered_at) }}</span>
                        <p v-if="request.order_note" class="whitespace-pre-line text-muted-foreground">{{ request.order_note }}</p>
                    </li>
                    <li v-if="request.received_by_name">
                        <span class="font-medium">{{ t('purchase_requests.step_received') }}</span>
                        <span class="text-xs text-muted-foreground"> · {{ request.received_by_name }} · {{ dateTime(request.received_at) }}</span>
                        <p v-if="request.receive_note" class="whitespace-pre-line text-muted-foreground">{{ request.receive_note }}</p>
                    </li>
                </ul>
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('inventory.purchase-requests.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
