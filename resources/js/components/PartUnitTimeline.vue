<script setup lang="ts">
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { PartUnitEventRow } from '@/types/inventory';
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

/**
 * What happened to one piece, oldest first: received when and from where, out on which ticket or
 * request to whom, back, taken off, corrected. Loaded when shown.
 */
const props = defineProps<{ unitId: number }>();

const events = ref<PartUnitEventRow[] | null>(null);

onMounted(async () => {
    const response = await fetch(route('inventory.part-units.history', props.unitId), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    events.value = response.ok ? (await response.json()).events : [];
});
</script>

<template>
    <p v-if="events === null" class="text-sm text-muted-foreground">{{ t('part_units.loading') }}</p>
    <ol v-else class="space-y-2 border-l-2 pl-4 text-sm">
        <li v-for="event in events" :key="event.id" class="relative">
            <span class="absolute -left-[1.4rem] top-1.5 size-2.5 rounded-full bg-primary" />
            <div class="flex flex-wrap gap-x-2">
                <span class="font-semibold">{{ t(`part_units.actions.${event.action}`) }}</span>
                <span class="font-mono">{{ event.serial_number }}</span>
                <span class="text-muted-foreground">{{ dateTime(event.at) }} · {{ event.user_name ?? t('common.system') }}</span>
            </div>
            <div class="flex flex-wrap gap-x-3 text-xs">
                <Link v-if="event.ticket" :href="route('service.tickets.show', event.ticket.ulid)" class="text-primary hover:underline">
                    {{ t('part_units.ticket', { no: event.ticket.ticket_no }) }}
                </Link>
                <Link v-if="event.asset" :href="route('asset.assets.show', event.asset.ulid)" class="text-primary hover:underline">
                    {{ t('part_units.asset', { code: event.asset.asset_code }) }}
                </Link>
                <Link v-if="event.request" :href="route('asset.requests.show', event.request.ulid)" class="text-primary hover:underline">
                    {{ t('part_units.request', { no: event.request.request_no, name: event.request.borrower_name }) }}
                </Link>
                <span v-if="event.reference">{{ event.reference }}</span>
            </div>
            <p v-if="event.reason" class="whitespace-pre-line text-xs text-muted-foreground">{{ event.reason }}</p>
        </li>
    </ol>
</template>
