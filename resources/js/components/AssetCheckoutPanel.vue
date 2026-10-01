<script setup lang="ts">
import CheckoutActions from '@/components/CheckoutActions.vue';
import CheckoutRequestForm from '@/components/CheckoutRequestForm.vue';
import CheckoutStatusBadge from '@/components/CheckoutStatusBadge.vue';
import CheckoutSteps from '@/components/CheckoutSteps.vue';
import { Button } from '@/components/ui/button';
import { checkoutRoute } from '@/lib/checkoutRoutes';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { CheckoutRow } from '@/types/checkout';
import { ChevronRight, HandHelping } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

/**
 * Issue/loan on the asset page: the forms in progress with their actions (several at once for
 * an asset bought by the lot), a request form while some of it is free, and the last forms.
 */
defineProps<{
    assetUlid: string;
    // A part's page: where its request form goes (Inventory module).
    storeUrl?: string;
    requestTitle?: string;
    checkouts: {
        open: CheckoutRow[];
        history: CheckoutRow[];
        available: boolean;
        available_quantity: number;
        quantity: number;
        unit: string | null;
        borrowers: { id: number; name: string }[];
        contracts: { id: number; label: string }[];
        default_contract_id?: number | null;
        can: { request: boolean; approve: boolean; userId: number };
    };
}>();

const asking = ref(false);

// Forms whose details are open (all closed at first, so many borrowers still fit on screen).
const expanded = reactive(new Set<string>());
const toggle = (ulid: string) => (expanded.has(ulid) ? expanded.delete(ulid) : expanded.add(ulid));
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">{{ t('checkouts.section') }}</h3>
                <p v-if="checkouts.quantity > 1" class="text-xs text-muted-foreground">
                    {{
                        t('checkouts.quantity_left', {
                            available: checkouts.available_quantity,
                            total: checkouts.quantity,
                            unit: checkouts.unit ?? '',
                        })
                    }}
                </p>
            </div>
            <Button v-if="checkouts.available && checkouts.can.request && !asking" size="sm" @click="asking = true">
                <HandHelping class="h-4 w-4" />
                {{ t('checkouts.request') }}
            </Button>
            <span v-else-if="!checkouts.available && checkouts.open.length === 0" class="text-xs text-muted-foreground">{{
                t('checkouts.not_available')
            }}</span>
            <span v-else-if="!checkouts.available && checkouts.quantity > 1" class="text-xs text-muted-foreground">{{
                t('checkouts.none_left')
            }}</span>
        </div>

        <!-- New request -->
        <CheckoutRequestForm
            v-if="asking"
            :asset-ulid="assetUlid"
            :borrowers="checkouts.borrowers"
            :contracts="checkouts.contracts"
            :default-contract-id="checkouts.default_contract_id"
            :store-url="storeUrl"
            :title="requestTitle ?? t('checkouts.request_title')"
            :max-quantity="checkouts.available_quantity"
            :unit="checkouts.unit"
            @done="asking = false"
            @cancel="asking = false"
        />

        <!-- The forms in progress: one compact row each (an asset bought by the lot may be out with
             many people at once); the details open under a row on demand. -->
        <div v-if="checkouts.open.length" class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ t('checkouts.checkout_no') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('checkouts.borrower') }}</th>
                        <th v-if="checkouts.quantity > 1" class="px-3 py-2 text-right font-medium">{{ t('checkouts.quantity') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('checkouts.due_on') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('checkouts.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="current in checkouts.open" :key="current.ulid">
                        <tr class="border-t align-top">
                            <td class="whitespace-nowrap px-3 py-2">
                                <button
                                    type="button"
                                    class="flex items-center gap-1 font-mono text-xs"
                                    :aria-expanded="expanded.has(current.ulid)"
                                    @click="toggle(current.ulid)"
                                >
                                    <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': expanded.has(current.ulid) }" />
                                    {{ current.checkout_no }}
                                </button>
                                <div class="pl-4 text-xs text-muted-foreground">{{ t(`checkouts.types.${current.type}`) }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ current.borrower_name }}</div>
                                <div v-if="current.borrower_department" class="text-xs text-muted-foreground">{{ current.borrower_department }}</div>
                            </td>
                            <td v-if="checkouts.quantity > 1" class="whitespace-nowrap px-3 py-2 text-right">
                                {{ current.quantity }} {{ checkouts.unit ?? '' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2" :class="{ 'text-red-700 dark:text-red-400': current.overdue }">
                                {{ current.due_on ?? '-' }}
                            </td>
                            <td class="px-3 py-2">
                                <CheckoutSteps :checkout="current" compact />
                                <div class="mt-1"><CheckoutStatusBadge :status="current.status" :overdue="current.overdue" /></div>
                            </td>
                            <td class="min-w-56 px-3 py-2"><CheckoutActions :checkout="current" :can="checkouts.can" /></td>
                        </tr>
                        <tr v-if="expanded.has(current.ulid)" class="bg-muted/20">
                            <td :colspan="checkouts.quantity > 1 ? 6 : 5" class="px-3 py-3">
                                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <dt class="text-xs text-muted-foreground">{{ t('checkouts.requested_by') }}</dt>
                                        <dd>{{ current.requested_by_name }} · {{ dateTime(current.requested_at) }}</dd>
                                    </div>
                                    <div v-if="current.decided_by_name">
                                        <dt class="text-xs text-muted-foreground">{{ t('checkouts.decided_by') }}</dt>
                                        <dd>{{ current.decided_by_name }} · {{ dateTime(current.decided_at) }}</dd>
                                    </div>
                                    <div v-if="current.borrower_phone">
                                        <dt class="text-xs text-muted-foreground">{{ t('checkouts.borrower_phone') }}</dt>
                                        <dd>{{ current.borrower_phone }}</dd>
                                    </div>
                                    <div v-if="current.purpose" class="sm:col-span-2 lg:col-span-3">
                                        <dt class="text-xs text-muted-foreground">{{ t('checkouts.purpose') }}</dt>
                                        <dd class="whitespace-pre-line">{{ current.purpose }}</dd>
                                    </div>
                                </dl>
                                <p v-if="current.status === 'approved'" class="mt-2 text-xs text-muted-foreground">{{ t('checkouts.print_hint') }}</p>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <!-- Earlier forms -->
        <div v-if="checkouts.history.length" class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.checkout_no') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.type') }}</th>
                        <th v-if="checkouts.quantity > 1" class="px-4 py-2 text-right font-medium">{{ t('checkouts.quantity') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.borrower') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.requested_at') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.returned_at') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.status') }}</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">{{ t('common.actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in checkouts.history" :key="row.ulid" class="border-t">
                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ row.checkout_no }}</td>
                        <td class="px-4 py-2">{{ t(`checkouts.types.${row.type}`) }}</td>
                        <td v-if="checkouts.quantity > 1" class="px-4 py-2 text-right">{{ row.quantity }}</td>
                        <td class="px-4 py-2">{{ row.borrower_name }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ dateTime(row.requested_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ row.returned_at ? dateTime(row.returned_at) : '-' }}</td>
                        <td class="px-4 py-2"><CheckoutStatusBadge :status="row.status" /></td>
                        <td class="px-4 py-2">
                            <a
                                v-if="row.status === 'returned'"
                                :href="route(checkoutRoute(row, 'print'), row.ulid)"
                                target="_blank"
                                rel="noopener"
                                class="text-primary underline-offset-4 hover:underline"
                                >{{ t('checkouts.print') }}</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else-if="checkouts.open.length === 0" class="text-sm text-muted-foreground">{{ t('checkouts.no_checkouts') }}</p>
    </section>
</template>
