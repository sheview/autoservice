<script setup lang="ts">
import RequestStatusBadge from '@/components/RequestStatusBadge.vue';
import RequestSteps from '@/components/RequestSteps.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { ItemRequestsPanelData } from '@/types/checkout';
import { Link } from '@inertiajs/vue3';
import { HandHelping } from 'lucide-vue-next';

/**
 * Issue/loan on an asset or part page: the request lines for this item (open ones first, then the
 * last finished) and a button that starts a request with it. "query" = how the request form is
 * told the item: { asset: ulid } or { part: id }.
 */
defineProps<{ checkouts: ItemRequestsPanelData; query: { asset: string } | { part: number } }>();
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">{{ t('requests.panel_title') }}</h3>
                <p v-if="checkouts.quantity > 1" class="text-xs text-muted-foreground">
                    {{
                        t('requests.quantity_left', {
                            available: checkouts.available_quantity,
                            total: checkouts.quantity,
                            unit: checkouts.unit ?? '',
                        })
                    }}
                </p>
            </div>
            <Button v-if="checkouts.can.create && checkouts.available" size="sm" as-child>
                <Link :href="route('asset.requests.create', query)">
                    <HandHelping class="h-4 w-4" />
                    {{ t('requests.new') }}
                </Link>
            </Button>
            <span v-else-if="checkouts.can.create" class="text-xs text-muted-foreground">{{ t('requests.not_available') }}</span>
        </div>

        <div v-if="checkouts.lines.length" class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ t('requests.request_no') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('requests.borrower') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ t('requests.qty') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('requests.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('requests.due_return_date') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in checkouts.lines" :key="line.id" class="border-t align-top">
                        <td class="whitespace-nowrap px-3 py-2">
                            <Link
                                :href="route('asset.requests.show', line.request.ulid)"
                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                            >
                                {{ line.request.request_no }}
                            </Link>
                            <div class="text-xs text-muted-foreground">{{ t(`requests.types.${line.checkout_type}`) }}</div>
                        </td>
                        <td class="px-3 py-2">{{ line.request.borrower_name }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            {{ line.qty_approved ?? line.qty_requested }} {{ line.unit ?? '' }}
                            <div v-if="line.qty_fulfilled > 0" class="text-xs text-muted-foreground">
                                {{ t('requests.fulfilled_qty', { qty: line.qty_fulfilled }) }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <RequestSteps :request="line.request" compact />
                            <div class="mt-1"><RequestStatusBadge :status="line.status" kind="line" :overdue="line.overdue" /></div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2" :class="{ 'text-red-700 dark:text-red-400': line.overdue }">
                            {{ line.due_return_date ?? '-' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="text-sm text-muted-foreground">{{ t('requests.no_lines') }}</p>
    </section>
</template>
