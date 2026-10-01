<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

// The company's subscription: a strip on every page while it needs attention, a popup once a day,
// and the message of a change the server refused (read-only period).
const page = usePage<SharedData>();
const subscription = computed(() => page.props.subscription);

const notice = computed(() => {
    const sub = subscription.value;
    if (!sub) return null;
    switch (sub.state) {
        case 'expiring':
            return { tone: 'warning', text: t('subscription.expiring', { days: sub.days_left ?? 0, date: sub.ends_on ?? '' }) };
        case 'grace':
        case 'locked':
            return { tone: 'danger', text: t('subscription.grace', { date: sub.ends_on ?? '', until: sub.read_only_until ?? '' }) };
        case 'not_started':
            return { tone: 'danger', text: t('subscription.not_started', { date: sub.starts_on ?? '' }) };
        default:
            return null;
    }
});

// Once a day per browser; storage may be unavailable (private mode), then it shows every visit.
const open = ref(false);
const storageKey = () => `subscription-notice:${subscription.value?.state}:${new Date().toISOString().slice(0, 10)}`;
onMounted(() => {
    if (!notice.value) return;
    try {
        open.value = localStorage.getItem(storageKey()) === null;
    } catch {
        open.value = true;
    }
});
const dismiss = () => {
    open.value = false;
    try {
        localStorage.setItem(storageKey(), '1');
    } catch {
        // nothing to remember
    }
};
</script>

<template>
    <div
        v-if="notice"
        role="status"
        class="flex items-center gap-2 px-4 py-2 text-sm font-medium"
        :class="notice.tone === 'danger' ? 'bg-red-600 text-white' : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100'"
    >
        <AlertTriangle class="h-4 w-4 shrink-0" />
        {{ notice.text }}
    </div>

    <p
        v-if="page.props.flash.error"
        role="alert"
        class="mx-4 mt-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
    >
        {{ page.props.flash.error }}
    </p>

    <Dialog v-if="notice" :open="open" @update:open="(value: boolean) => !value && dismiss()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <AlertTriangle class="h-5 w-5" :class="notice.tone === 'danger' ? 'text-red-600' : 'text-amber-500'" />
                    {{ t('subscription.popup_title') }}
                </DialogTitle>
                <DialogDescription>{{ notice.text }}</DialogDescription>
            </DialogHeader>
            <p class="text-sm text-muted-foreground">{{ t('subscription.contact') }}</p>
            <DialogFooter>
                <Button @click="dismiss">{{ t('subscription.acknowledge') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
