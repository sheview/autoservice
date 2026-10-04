<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { CalendarClock } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * When the technician is due on site: shown on the ticket, set or changed by who may update it.
 * It puts the job on that day of the assignee's "my work" calendar.
 */
const props = defineProps<{ ticketUlid: string; appointmentAt: string | null; canChange: boolean }>();

const editing = ref(false);
const form = useForm({ appointment_at: props.appointmentAt ?? '' });
const save = (value: string | null) => {
    form.appointment_at = value ?? '';
    form.post(route('service.tickets.appointment', props.ticketUlid), { preserveScroll: true, onSuccess: () => (editing.value = false) });
};
const label = (value: string) =>
    new Date(value).toLocaleString('th-TH-u-ca-gregory', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm">
        <CalendarClock class="h-4 w-4 text-muted-foreground" />
        <span class="text-muted-foreground">{{ t('tickets.appointment') }}:</span>
        <template v-if="!editing">
            <span :class="appointmentAt ? 'font-semibold' : 'text-muted-foreground'">{{
                appointmentAt ? label(appointmentAt) : t('tickets.appointment_none')
            }}</span>
            <template v-if="canChange">
                <button type="button" class="text-primary hover:underline" @click="editing = true">
                    {{ appointmentAt ? t('tickets.appointment_change') : t('tickets.appointment_set') }}
                </button>
                <button v-if="appointmentAt" type="button" class="text-muted-foreground hover:underline" @click="save(null)">
                    {{ t('tickets.appointment_clear') }}
                </button>
            </template>
        </template>
        <form v-else class="flex flex-wrap items-center gap-2" @submit.prevent="save(form.appointment_at)">
            <Input v-model="form.appointment_at" type="datetime-local" class="w-56" required />
            <Button size="sm" :disabled="form.processing">{{ t('common.save') }}</Button>
            <Button type="button" size="sm" variant="ghost" @click="editing = false">{{ t('common.cancel') }}</Button>
            <span class="w-full text-xs text-muted-foreground">{{ t('tickets.appointment_hint') }}</span>
        </form>
        <InputError class="w-full" :message="form.errors.appointment_at" />
    </div>
</template>
