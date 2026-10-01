<script setup lang="ts">
import CheckoutActions from '@/components/CheckoutActions.vue';
import CheckoutRequestForm from '@/components/CheckoutRequestForm.vue';
import CheckoutStatusBadge from '@/components/CheckoutStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { CheckoutRow } from '@/types/checkout';
import { HandHelping } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Issue/loan on the asset page: the form in progress with its actions, a request form when the
 * asset is free, and the last forms.
 */
defineProps<{
    assetUlid: string;
    checkouts: {
        current: CheckoutRow | null;
        history: CheckoutRow[];
        available: boolean;
        borrowers: { id: number; name: string }[];
        can: { request: boolean; approve: boolean; userId: number };
    };
}>();

const asking = ref(false);
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">{{ t('checkouts.section') }}</h3>
            <Button v-if="checkouts.available && checkouts.can.request && !asking" size="sm" @click="asking = true">
                <HandHelping class="h-4 w-4" />
                {{ t('checkouts.request') }}
            </Button>
            <span v-else-if="!checkouts.current && !checkouts.available" class="text-xs text-muted-foreground">{{
                t('checkouts.not_available')
            }}</span>
        </div>

        <!-- The form in progress -->
        <div v-if="checkouts.current" class="space-y-3 rounded-md border p-4 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono font-semibold">{{ checkouts.current.checkout_no }}</span>
                <span class="rounded bg-muted px-1.5 text-xs">{{ t(`checkouts.types.${checkouts.current.type}`) }}</span>
                <CheckoutStatusBadge :status="checkouts.current.status" :overdue="checkouts.current.overdue" />
            </div>
            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('checkouts.borrower') }}</dt>
                    <dd>
                        {{ checkouts.current.borrower_name }}
                        <span v-if="checkouts.current.borrower_department" class="text-muted-foreground"
                            >· {{ checkouts.current.borrower_department }}</span
                        >
                    </dd>
                </div>
                <div v-if="checkouts.current.due_on">
                    <dt class="text-xs text-muted-foreground">{{ t('checkouts.due_on') }}</dt>
                    <dd :class="{ 'text-red-700 dark:text-red-400': checkouts.current.overdue }">{{ checkouts.current.due_on }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('checkouts.requested_by') }}</dt>
                    <dd>{{ checkouts.current.requested_by_name }} · {{ dateTime(checkouts.current.requested_at) }}</dd>
                </div>
                <div v-if="checkouts.current.decided_by_name">
                    <dt class="text-xs text-muted-foreground">{{ t('checkouts.decided_by') }}</dt>
                    <dd>{{ checkouts.current.decided_by_name }} · {{ dateTime(checkouts.current.decided_at) }}</dd>
                </div>
                <div v-if="checkouts.current.purpose" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('checkouts.purpose') }}</dt>
                    <dd class="whitespace-pre-line">{{ checkouts.current.purpose }}</dd>
                </div>
            </dl>
            <CheckoutActions :checkout="checkouts.current" :can="checkouts.can" />
            <p v-if="checkouts.current.status === 'approved'" class="text-xs text-muted-foreground">{{ t('checkouts.print_hint') }}</p>
        </div>

        <!-- New request -->
        <CheckoutRequestForm
            v-if="asking"
            :asset-ulid="assetUlid"
            :borrowers="checkouts.borrowers"
            :title="t('checkouts.request_title')"
            @done="asking = false"
            @cancel="asking = false"
        />

        <!-- Earlier forms -->
        <div v-if="checkouts.history.length" class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.checkout_no') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('checkouts.type') }}</th>
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
                        <td class="px-4 py-2">{{ row.borrower_name }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ dateTime(row.requested_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ row.returned_at ? dateTime(row.returned_at) : '-' }}</td>
                        <td class="px-4 py-2"><CheckoutStatusBadge :status="row.status" /></td>
                        <td class="px-4 py-2">
                            <a
                                v-if="row.status === 'returned'"
                                :href="route('asset.checkouts.print', row.ulid)"
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
        <p v-else-if="!checkouts.current" class="text-sm text-muted-foreground">{{ t('checkouts.no_checkouts') }}</p>
    </section>
</template>
