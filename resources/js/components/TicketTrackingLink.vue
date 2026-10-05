<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { router } from '@inertiajs/vue3';
import { Copy, Link2, RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * The customer's tracking link of a ticket (no sign-in needed), to copy and send; staff who may
 * update the ticket can make a new one when the old one got out (the old one stops working).
 */
const props = defineProps<{ ticketUlid: string; url: string; canRenew: boolean }>();

const copied = ref(false);
const copy = async () => {
    await navigator.clipboard.writeText(props.url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};
const renew = () =>
    confirm(t('tickets.tracking_renew_confirm')) &&
    router.post(route('service.tickets.tracking-token', props.ticketUlid), {}, { preserveScroll: true });
</script>

<template>
    <div class="space-y-2 rounded-md border px-3 py-2 text-sm">
        <div class="flex items-center gap-2">
            <Link2 class="h-4 w-4 text-muted-foreground" />
            <span class="text-muted-foreground">{{ t('tickets.tracking_link') }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 text-xs">{{ url }}</code>
            <Button type="button" size="sm" variant="outline" @click="copy"
                ><Copy class="h-3.5 w-3.5" />{{ copied ? t('tickets.tracking_copied') : t('tickets.tracking_copy') }}</Button
            >
            <Button v-if="canRenew" type="button" size="sm" variant="ghost" @click="renew"
                ><RefreshCw class="h-3.5 w-3.5" />{{ t('tickets.tracking_renew') }}</Button
            >
        </div>
        <p class="text-xs text-muted-foreground">{{ t('tickets.tracking_hint') }}</p>
    </div>
</template>
