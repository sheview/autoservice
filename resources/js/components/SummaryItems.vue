<script setup lang="ts">
import CheckoutStatusBadge from '@/components/CheckoutStatusBadge.vue';
import CheckoutSteps from '@/components/CheckoutSteps.vue';
import Pagination from '@/components/Pagination.vue';
import PurchaseStatusBadge from '@/components/PurchaseStatusBadge.vue';
import StepDots from '@/components/StepDots.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { purchaseSteps } from '@/lib/purchaseSteps';
import type { Paginated } from '@/types';
import { personParams, type ContractLabel, type SummaryCheckout, type SummaryPurchase } from '@/types/summary';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The issue/loan forms and the purchase requests of a person or a project, each list with its own
 * pages. "by" = which page shows them: on a person's page the project is the useful column, on a
 * project's page the person. Null list = that module is off (or, for purchases, a person from outside).
 */
const props = defineProps<{
    checkouts: Paginated<SummaryCheckout> | null;
    // Parts issued or lent (same rows as the assets', kind "part").
    partCheckouts?: Paginated<SummaryCheckout> | null;
    purchases: Paginated<SummaryPurchase> | null;
    by: 'person' | 'project';
    purchasesNote?: string;
}>();

// The asset forms, then the part forms: the same table for both.
const checkoutLists = computed(() =>
    [
        { key: 'assets', title: t('summary.checkouts'), rows: props.checkouts },
        { key: 'parts', title: t('summary.part_checkouts'), rows: props.partCheckouts ?? null },
    ].filter((list): list is { key: string; title: string; rows: Paginated<SummaryCheckout> } => list.rows !== null),
);

const money = (baht: string | null) => (baht === null ? '-' : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
const project = (contract: ContractLabel) => (contract ? `${contract.contract_no} · ${contract.title}` : t('summary.no_project'));
</script>

<template>
    <section v-for="list in checkoutLists" :key="list.key" class="space-y-2">
        <h3 class="text-sm font-semibold">{{ list.title }}</h3>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.checkout_no') }}</th>
                        <th class="px-4 py-2 font-medium">{{ list.key === 'parts' ? t('part_checkouts.part') : t('summary.asset') }}</th>
                        <th class="px-4 py-2 font-medium">{{ by === 'person' ? t('summary.project') : t('summary.borrower') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('summary.date') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.due_on') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('summary.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in list.rows.data" :key="row.ulid" class="border-t align-top">
                        <td class="whitespace-nowrap px-4 py-2">
                            <div class="font-mono text-xs">{{ row.checkout_no }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ t(`${row.kind === 'part' ? 'part_checkouts' : 'checkouts'}.types.${row.type}`)
                                }}<template v-if="row.quantity > 1"> · {{ row.quantity }} {{ row.asset?.unit ?? '' }}</template>
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="row.asset"
                                :href="
                                    row.kind === 'part' ? route('inventory.parts.show', row.asset.ulid) : route('asset.assets.show', row.asset.ulid)
                                "
                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                            >
                                {{ row.asset.asset_code }}
                            </Link>
                            <div>{{ row.asset?.name }}</div>
                        </td>
                        <td class="px-4 py-2">
                            <template v-if="by === 'person'">
                                <Link
                                    v-if="row.contract"
                                    :href="route('reporting.projects.show', row.contract.id)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ project(row.contract) }}
                                </Link>
                                <span v-else class="text-muted-foreground">{{ project(null) }}</span>
                            </template>
                            <template v-else>
                                <Link
                                    :href="
                                        route(
                                            'reporting.people.show',
                                            personParams({ user_id: row.borrower_user_id, outside_name: row.borrower_name }),
                                        )
                                    "
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ row.borrower_name }}
                                </Link>
                                <div v-if="row.borrower_department" class="text-xs text-muted-foreground">{{ row.borrower_department }}</div>
                            </template>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2">{{ dateTime(row.requested_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-2" :class="{ 'text-red-700 dark:text-red-400': row.overdue }">
                            {{ row.due_on ?? '-' }}
                        </td>
                        <td class="px-4 py-2">
                            <CheckoutSteps :checkout="row" compact />
                            <div class="mt-1"><CheckoutStatusBadge :status="row.status" :overdue="row.overdue" /></div>
                        </td>
                    </tr>
                    <tr v-if="list.rows.data.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">{{ t('summary.no_checkouts') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="list.rows" />
    </section>

    <section v-if="purchases" class="space-y-2">
        <h3 class="text-sm font-semibold">{{ t('summary.purchases_list') }}</h3>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('purchase_requests.pr_no') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('summary.item') }}</th>
                        <th class="px-4 py-2 font-medium">{{ by === 'person' ? t('summary.project') : t('summary.requested_by') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('summary.amount') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('summary.date') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('summary.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in purchases.data" :key="row.ulid" class="border-t align-top">
                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                            <Link :href="route('inventory.purchase-requests.show', row.ulid)" class="text-primary underline-offset-4 hover:underline">
                                {{ row.pr_no }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ row.item_name }}</div>
                            <div class="text-xs text-muted-foreground">{{ row.quantity }} {{ row.unit }}</div>
                        </td>
                        <td class="px-4 py-2">
                            <template v-if="by === 'person'">
                                <Link
                                    v-if="row.contract"
                                    :href="route('reporting.projects.show', row.contract.id)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ project(row.contract) }}
                                </Link>
                                <span v-else class="text-muted-foreground">{{ project(null) }}</span>
                            </template>
                            <Link
                                v-else-if="row.requested_by"
                                :href="route('reporting.people.show', { user: row.requested_by })"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                {{ row.requested_by_name }}
                            </Link>
                            <span v-else>{{ row.requested_by_name }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ money(row.total) }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ dateTime(row.requested_at) }}</td>
                        <td class="px-4 py-2">
                            <StepDots v-bind="purchaseSteps(row)" />
                            <div class="mt-1"><PurchaseStatusBadge :status="row.status" /></div>
                        </td>
                    </tr>
                    <tr v-if="purchases.data.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">{{ t('summary.no_purchases') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="purchases" />
    </section>
    <p v-else-if="purchasesNote" class="text-sm text-muted-foreground">{{ purchasesNote }}</p>
</template>
