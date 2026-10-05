<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A problem a customer reported with a QR code, waiting for the helpdesk: the device's MA contract
 * and warranty to decide on, and the three answers. The message goes to the customer.
 */
const props = defineProps<{
    ticketUlid: string;
    review: {
        contracts: { contract_no: string; title: string; service_window: string }[];
        warranty_expires_on: string | null;
        contact_email: string | null;
    };
}>();

const choice = ref<'accept' | 'ask' | 'reject' | null>(null);
const form = useForm({ decision: '', message: '' });
const send = (decision: 'accept' | 'ask' | 'reject') => {
    form.decision = decision;
    form.post(route('service.tickets.review', props.ticketUlid), { preserveScroll: true, onSuccess: () => (choice.value = null) });
};
const warranty = computed(() => {
    const d = props.review.warranty_expires_on;
    if (!d) return { label: t('review.warranty_none'), cls: 'text-muted-foreground' };
    const date = new Date(`${d}T00:00:00`);
    const text = date.toLocaleDateString('th-TH-u-ca-gregory', { dateStyle: 'medium' });
    return date < new Date()
        ? { label: t('review.warranty_expired', { date: text }), cls: 'text-red-700' }
        : { label: t('review.warranty_active', { date: text }), cls: 'text-green-700' };
});
</script>

<template>
    <section class="space-y-3 rounded-lg border-2 border-orange-300 bg-orange-50 p-4 dark:border-orange-800 dark:bg-orange-950/40">
        <h3 class="flex items-center gap-2 font-semibold"><ShieldCheck class="h-5 w-5" />{{ t('review.title') }}</h3>
        <dl class="grid gap-1 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
            <dt class="text-muted-foreground">{{ t('review.contract') }}</dt>
            <dd>
                <template v-if="review.contracts.length">
                    <span v-for="c in review.contracts" :key="c.contract_no" class="block"
                        >{{ c.contract_no }} {{ c.title }} ({{ c.service_window }})</span
                    >
                </template>
                <span v-else class="font-semibold text-amber-700">{{ t('review.no_contract') }}</span>
            </dd>
            <dt class="text-muted-foreground">{{ t('review.warranty') }}</dt>
            <dd :class="warranty.cls">{{ warranty.label }}</dd>
            <template v-if="review.contact_email">
                <dt class="text-muted-foreground">{{ t('review.email') }}</dt>
                <dd>{{ review.contact_email }}</dd>
            </template>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button :disabled="form.processing" @click="send('accept')">{{ t('review.accept') }}</Button>
            <Button variant="outline" @click="choice = 'ask'">{{ t('review.ask') }}</Button>
            <Button variant="outline" class="text-red-600" @click="choice = 'reject'">{{ t('review.reject') }}</Button>
        </div>

        <form v-if="choice" class="space-y-2" @submit.prevent="send(choice)">
            <label class="block text-sm font-semibold">{{ choice === 'ask' ? t('review.ask_message') : t('review.reject_reason') }}</label>
            <textarea v-model="form.message" rows="3" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
            <p class="text-xs text-muted-foreground">{{ t('review.customer_sees') }}</p>
            <InputError :message="form.errors.message || form.errors.decision" />
            <div class="flex gap-2">
                <Button :disabled="form.processing" :variant="choice === 'reject' ? 'destructive' : 'default'">{{
                    choice === 'ask' ? t('review.send_ask') : t('review.send_reject')
                }}</Button>
                <Button type="button" variant="ghost" @click="choice = null">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </section>
</template>
