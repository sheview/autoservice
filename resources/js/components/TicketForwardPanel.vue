<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface TicketForwards {
    tickets: {
        id: number;
        company: string | null;
        ticket_no: string | null;
        status: string | null;
        assignee: string | null;
        by: string | null;
        at: string;
    }[];
    companies: { id: number; name: string }[];
    from: { company: string | null; ticket_no: string | null; by: string | null } | null;
}

/**
 * Sending the job on to another company (through a share) and following it there; or, for a job
 * another company sent us, where it came from.
 */
const props = defineProps<{ ticketUlid: string; forwards: TicketForwards }>();

const open = ref(false);
const form = useForm({ company: props.forwards.companies[0]?.id ?? null, note: '' });
const submit = () => {
    const company = props.forwards.companies.find((c) => c.id === form.company);
    if (company && confirm(t('shares.forward_confirm', { company: company.name }))) {
        form.post(route('service.tickets.forward', props.ticketUlid), {
            preserveScroll: true,
            onSuccess: () => ((open.value = false), form.reset()),
        });
    }
};
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <p v-if="forwards.from" class="rounded-md bg-sky-50 px-4 py-2 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-100">
        {{ t('shares.forwarded_from', { company: forwards.from.company ?? '', no: forwards.from.ticket_no ?? '', by: forwards.from.by ?? '' }) }}
    </p>

    <section v-if="forwards.tickets.length || forwards.companies.length" class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">{{ t('shares.forward_title') }}</h3>
            <button v-if="forwards.companies.length && !open" type="button" class="text-sm text-primary hover:underline" @click="open = true">
                + {{ t('shares.forward_submit') }}
            </button>
        </div>

        <ul v-if="forwards.tickets.length" class="divide-y rounded-md border text-sm">
            <li v-for="f in forwards.tickets" :key="f.id" class="flex flex-wrap items-center justify-between gap-2 p-3">
                <span>
                    <span class="font-semibold">{{ f.company }}</span>
                    <span class="ml-2 font-mono text-xs">{{ f.ticket_no }}</span>
                    <span v-if="f.assignee" class="block text-xs text-muted-foreground">{{
                        t('shares.forward_assignee', { name: f.assignee })
                    }}</span>
                </span>
                <TicketStatusBadge v-if="f.status" :status="f.status" />
            </li>
        </ul>

        <form v-if="open" class="space-y-3 rounded-md border p-3" @submit.prevent="submit">
            <p class="text-xs text-muted-foreground">{{ t('shares.forward_hint') }}</p>
            <select v-model="form.company" :class="selectClass" :aria-label="t('shares.forward_company')">
                <option v-for="c in forwards.companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <Input v-model="form.note" :placeholder="t('shares.forward_note')" />
            <InputError :message="form.errors.company" />
            <div class="flex gap-2">
                <Button size="sm" :disabled="form.processing">{{ t('shares.forward_submit') }}</Button>
                <Button type="button" size="sm" variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </section>
</template>
