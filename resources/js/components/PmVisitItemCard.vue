<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PmResultBadge from '@/components/PmResultBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Camera, X } from 'lucide-vue-next';

export interface PmItem {
    id: number;
    result: string;
    answers: Record<string, boolean | string | number | null>;
    note: string | null;
    checklist: { key: string; label: string; type: string }[];
    asset: { ulid: string; asset_code: string; name: string; can_view: boolean } | null;
    ticket: { ulid: string; ticket_no: string; status: string } | null;
    checked_by: string | null;
    checked_at: string | null;
    photos: { id: number; name: string; url: string }[];
}

const props = defineProps<{
    item: PmItem;
    visitUlid: string;
    editable: boolean;
    canOpenTicket: boolean;
    priorities: string[];
}>();

const results = ['ok', 'issue', 'skipped'];

const form = useForm({
    result: props.item.result === 'pending' ? 'ok' : props.item.result,
    answers: Object.fromEntries(
        props.item.checklist.map((field) => [field.key, props.item.answers[field.key] ?? (field.type === 'check' ? false : '')]),
    ) as Record<string, boolean | string | number | null>,
    note: props.item.note ?? '',
});

const save = () => form.put(route('maintenance.visits.items.update', [props.visitUlid, props.item.id]), { preserveScroll: true });

const ticket = useForm({ priority: 'medium' });
const openTicket = () => ticket.post(route('maintenance.visits.items.ticket', [props.visitUlid, props.item.id]), { preserveScroll: true });

// Photos upload one by one as soon as they are picked.
const photo = useForm<{ photo: File | null }>({ photo: null });
const uploadPhotos = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        photo.photo = file;
        await new Promise<void>((resolve) =>
            photo.post(route('maintenance.visits.items.photos.store', [props.visitUlid, props.item.id]), {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => resolve(),
            }),
        );
    }
    input.value = '';
};
const deletePhoto = (id: number) => {
    if (confirm(t('pm_visits.confirm_delete_photo'))) {
        router.delete(route('maintenance.visits.items.photos.destroy', [props.visitUlid, props.item.id, id]), { preserveScroll: true });
    }
};

const answerText = (field: { key: string; type: string }) => {
    const value = props.item.answers[field.key];
    if (field.type === 'check') return value ? t('pm_visits.yes') : t('pm_visits.no');
    return value === null || value === undefined || value === '' ? t('common.none') : String(value);
};

const errorOf = (key: string) => (form.errors as Record<string, string>)[key];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <div class="space-y-4 rounded-md border p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <template v-if="item.asset">
                    <Link
                        v-if="item.asset.can_view"
                        :href="route('asset.assets.show', item.asset.ulid)"
                        class="font-mono text-sm text-primary underline-offset-4 hover:underline"
                    >
                        {{ item.asset.asset_code }}
                    </Link>
                    <span v-else class="font-mono text-sm">{{ item.asset.asset_code }}</span>
                    <span class="ml-2 font-medium">{{ item.asset.name }}</span>
                </template>
                <span v-else class="text-muted-foreground">{{ t('pm_visits.asset_missing') }}</span>
                <p v-if="item.checked_at" class="text-xs text-muted-foreground">
                    {{ t('pm_visits.checked_by', { name: item.checked_by ?? t('common.system'), at: dateTime(item.checked_at) }) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    v-if="item.ticket"
                    :href="route('service.tickets.show', item.ticket.ulid)"
                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                >
                    {{ item.ticket.ticket_no }}
                </Link>
                <TicketStatusBadge v-if="item.ticket" :status="item.ticket.status" />
                <PmResultBadge :result="item.result" />
            </div>
        </div>

        <!-- Recording -->
        <form v-if="editable" class="space-y-4" @submit.prevent="save">
            <p v-if="item.checklist.length === 0" class="text-xs text-muted-foreground">{{ t('pm_visits.no_checklist') }}</p>
            <div v-else class="grid gap-3 sm:grid-cols-2">
                <div v-for="field in item.checklist" :key="field.key" class="grid gap-1">
                    <label v-if="field.type === 'check'" class="flex items-center gap-2 text-sm">
                        <input v-model="form.answers[field.key]" type="checkbox" class="size-4 rounded border-input" />
                        {{ field.label }}
                    </label>
                    <template v-else>
                        <label :for="`answer_${item.id}_${field.key}`" class="text-sm">{{ field.label }}</label>
                        <Input
                            :id="`answer_${item.id}_${field.key}`"
                            v-model="form.answers[field.key] as string"
                            :type="field.type === 'number' ? 'number' : 'text'"
                            step="any"
                        />
                    </template>
                    <InputError :message="errorOf(`answers.${field.key}`)" />
                </div>
            </div>

            <div class="flex flex-wrap gap-4">
                <label v-for="result in results" :key="result" class="flex items-center gap-2 text-sm">
                    <input v-model="form.result" type="radio" :value="result" class="size-4" />
                    {{ t(`pm_visits.results.${result}`) }}
                </label>
            </div>
            <InputError :message="form.errors.result" />

            <div class="grid gap-1">
                <label :for="`note_${item.id}`" class="text-sm">{{
                    form.result === 'issue' ? t('pm_visits.note_issue') : t('pm_visits.note')
                }}</label>
                <textarea
                    :id="`note_${item.id}`"
                    v-model="form.note"
                    rows="2"
                    class="shadow-xs rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    :required="form.result === 'issue'"
                />
                <InputError :message="form.errors.note" />
            </div>

            <Button size="sm" :disabled="form.processing">{{ t('pm_visits.save_item') }}</Button>
        </form>

        <!-- Read only -->
        <template v-else-if="item.result !== 'pending'">
            <dl v-if="item.checklist.length" class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div v-for="field in item.checklist" :key="field.key" class="flex justify-between gap-2 border-b py-1">
                    <dt class="text-muted-foreground">{{ field.label }}</dt>
                    <dd>{{ answerText(field) }}</dd>
                </div>
            </dl>
            <p v-if="item.note" class="whitespace-pre-line text-sm">{{ item.note }}</p>
        </template>

        <!-- Site photos -->
        <div v-if="item.photos.length || editable" class="space-y-2">
            <div v-if="item.photos.length" class="flex flex-wrap gap-2">
                <div v-for="p in item.photos" :key="p.id" class="relative">
                    <a :href="p.url" target="_blank" rel="noopener">
                        <img :src="p.url" :alt="p.name" loading="lazy" class="h-24 w-24 rounded-md border object-cover" />
                    </a>
                    <button
                        v-if="editable"
                        type="button"
                        class="absolute right-1 top-1 rounded-full bg-background/90 p-1 text-destructive shadow"
                        :aria-label="t('pm_visits.delete_photo')"
                        @click="deletePhoto(p.id)"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </div>
            </div>
            <label v-if="editable" class="inline-flex cursor-pointer items-center gap-2 text-sm text-primary">
                <Camera class="h-4 w-4" />
                {{ photo.processing ? t('pm_visits.uploading') : t('pm_visits.add_photo') }}
                <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" multiple class="hidden" @change="uploadPhotos" />
            </label>
            <InputError :message="(photo.errors as Record<string, string>).photo" />
        </div>

        <!-- A found issue can become a repair ticket -->
        <div v-if="canOpenTicket && item.result === 'issue' && !item.ticket" class="flex flex-wrap items-center gap-2 border-t pt-3">
            <select v-model="ticket.priority" :class="selectClass" :aria-label="t('tickets.priority')">
                <option v-for="priority in priorities" :key="priority" :value="priority">{{ t(`tickets.priorities.${priority}`) }}</option>
            </select>
            <Button size="sm" variant="outline" :disabled="ticket.processing" @click="openTicket">{{ t('pm_visits.open_ticket') }}</Button>
            <InputError :message="(ticket.errors as Record<string, string>).item ?? ticket.errors.priority" />
        </div>
    </div>
</template>
