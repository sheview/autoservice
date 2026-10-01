<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { checkoutRoute } from '@/lib/checkoutRoutes';
import { t } from '@/lib/i18n';
import type { CheckoutRow } from '@/types/checkout';
import { useForm } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * What can be done with an issue/loan form now: approve or reject (with a reason), cancel the
 * request, print the hand-over paper, take the asset back (with a note on its condition).
 */
const props = defineProps<{
    checkout: CheckoutRow;
    // return: may take back (asset-checkouts.return); absent = "request" decides (part forms).
    can: { request: boolean; approve: boolean; userId: number; return?: boolean };
    size?: 'sm' | 'default';
}>();

// The open box: reject (needs a reason) or return (optional note).
const open = ref<'reject' | 'return' | null>(null);
const form = useForm({ note: '' });

const pending = computed(() => props.checkout.status === 'pending');
const out = computed(() => props.checkout.status === 'approved');
// Issued parts are used up: only lent ones come back.
const returnable = computed(() => out.value && !(props.checkout.kind === 'part' && props.checkout.type === 'issue'));
// What the server says about this form (its scope), when it says it; otherwise the page's abilities.
const canApprove = computed(() => pending.value && (props.checkout.actions?.approve ?? props.can.approve));
const canCancel = computed(
    () => pending.value && (props.checkout.actions?.cancel ?? (props.can.approve || props.checkout.requested_by === props.can.userId)),
);
const canReturn = computed(() => returnable.value && (props.checkout.actions?.return ?? props.can.return ?? props.can.request));
const printable = computed(() => out.value || props.checkout.status === 'returned');

const send = (action: 'approve' | 'reject' | 'return' | 'cancel') =>
    form.post(route(checkoutRoute(props.checkout, action), props.checkout.ulid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = null;
            form.reset();
        },
    });

const cancel = () => {
    if (confirm(t('checkouts.confirm_cancel', { no: props.checkout.checkout_no }))) {
        send('cancel');
    }
};

const toggle = (box: 'reject' | 'return') => {
    open.value = open.value === box ? null : box;
    form.reset();
    form.clearErrors();
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="canApprove">
                <Button :size="size ?? 'sm'" :disabled="form.processing" @click="send('approve')">{{ t('checkouts.approve') }}</Button>
                <Button :size="size ?? 'sm'" variant="outline" @click="toggle('reject')">{{ t('checkouts.reject') }}</Button>
            </template>
            <Button v-if="canCancel" :size="size ?? 'sm'" variant="ghost" :disabled="form.processing" @click="cancel">{{
                t('checkouts.cancel')
            }}</Button>
            <template v-if="printable">
                <Button :size="size ?? 'sm'" variant="outline" as-child>
                    <a :href="route(checkoutRoute(checkout, 'print'), checkout.ulid)" target="_blank" rel="noopener">
                        <Printer class="h-4 w-4" />
                        {{ t('checkouts.print') }}
                    </a>
                </Button>
                <Button :size="size ?? 'sm'" variant="ghost" as-child>
                    <a :href="route(checkoutRoute(checkout, 'pdf'), checkout.ulid)" target="_blank" rel="noopener">{{ t('checkouts.pdf') }}</a>
                </Button>
            </template>
            <Button v-if="canReturn" :size="size ?? 'sm'" variant="outline" @click="toggle('return')">{{ t('checkouts.give_back') }}</Button>
        </div>

        <form v-if="open" class="space-y-2" @submit.prevent="send(open)">
            <label :for="`note-${checkout.ulid}`" class="text-sm font-medium">
                {{ open === 'reject' ? t('checkouts.reject_reason') : t('checkouts.return_note') }}
            </label>
            <textarea
                :id="`note-${checkout.ulid}`"
                v-model="form.note"
                rows="2"
                :required="open === 'reject'"
                class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
            />
            <InputError :message="form.errors.note ?? (form.errors as Record<string, string>).checkout" />
            <div class="flex gap-2">
                <Button size="sm" :variant="open === 'reject' ? 'destructive' : 'default'" :disabled="form.processing">
                    {{ open === 'reject' ? t('checkouts.reject') : t('checkouts.give_back') }}
                </Button>
                <Button size="sm" type="button" variant="ghost" @click="open = null">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </div>
</template>
