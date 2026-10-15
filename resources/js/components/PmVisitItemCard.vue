<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PmField from '@/components/pm/PmField.vue';
import PmResultBadge from '@/components/PmResultBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { failures as failuresOf, formAnswer, sectionsOf, type Answer, type TemplateField } from '@/lib/pmTemplate';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Camera, MessageSquarePlus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/** A field of the item's copy of its template (see resources/js/lib/pmTemplate.ts). */
export type ChecklistQuestion = TemplateField;

export interface PmItem {
    id: number;
    result: string;
    answers: Record<string, Answer>;
    remarks: Record<string, string>;
    note: string | null;
    checklist: ChecklistQuestion[];
    // with the register values its readonly_from_asset fields show
    asset: ({ ulid: string; asset_code: string; name: string; can_view: boolean } & Record<string, string | boolean | null>) | null;
    ticket: { ulid: string; ticket_no: string; status: string } | null;
    checked_by: string | null;
    checked_at: string | null;
    photos: { id: number; name: string; url: string; question: string | null }[];
    removable: boolean;
}

const props = defineProps<{
    item: PmItem;
    visitUlid: string;
    editable: boolean;
    canOpenTicket: boolean;
    priorities: string[];
}>();

const results = ['passed', 'failed', 'not_inspected'];

const form = useForm({
    result: props.item.result === 'pending' ? 'passed' : props.item.result,
    answers: Object.fromEntries(props.item.checklist.map((q) => [q.key, formAnswer(q, props.item.answers[q.key])])) as Record<string, Answer>,
    remarks: Object.fromEntries(props.item.checklist.map((q) => [q.key, props.item.remarks?.[q.key] ?? ''])) as Record<string, string>,
    note: props.item.note ?? '',
});
const save = () => form.put(route('maintenance.visits.items.update', [props.visitUlid, props.item.id]), { preserveScroll: true });

// A question's remark box opens when asked for, or when it already has one.
const remarkOpen = ref<Record<string, boolean>>(Object.fromEntries(props.item.checklist.map((q) => [q.key, !!props.item.remarks?.[q.key]])));

// What failed so far (as ChecklistItems::failures on the server), and the fields under their headings.
const failures = computed(() => failuresOf(props.item.checklist, form.answers));
const sections = computed(() => sectionsOf(props.item.checklist));
const assetValues = computed(() => (props.item.asset ?? {}) as Record<string, string | null>);

const ticket = useForm({ priority: 'medium' });
const openTicket = () => ticket.post(route('maintenance.visits.items.ticket', [props.visitUlid, props.item.id]), { preserveScroll: true });

const remove = () =>
    confirm(t('pm_visits.confirm_remove_item')) &&
    router.delete(route('maintenance.visits.items.destroy', [props.visitUlid, props.item.id]), { preserveScroll: true });

// Photos upload one by one as soon as they are picked, of the equipment or of one question.
const photo = useForm<{ photo: File | null; question: string | null }>({ photo: null, question: null });
const uploadPhotos = async (event: Event, question: string | null = null) => {
    const input = event.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        photo.photo = file;
        photo.question = question;
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
const photosOf = (question: string | null) => props.item.photos.filter((p) => (p.question ?? null) === question);

const errorOf = (key: string) => (form.errors as Record<string, string>)[key];
// A table's errors come per cell (answers.key.row.column).
const firstErrorUnder = (prefix: string) => Object.entries(form.errors as Record<string, string>).find(([key]) => key.startsWith(prefix))?.[1];
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
                <a
                    v-if="item.result !== 'pending'"
                    :href="route('maintenance.visits.equipment.pdf', [visitUlid, item.id])"
                    target="_blank"
                    rel="noopener"
                    class="text-xs text-primary underline-offset-4 hover:underline"
                    >{{ t('pm_visits.equipment_pdf') }}</a
                >
                <button
                    v-if="item.removable"
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:text-destructive"
                    :title="t('pm_visits.remove_item')"
                    :aria-label="t('pm_visits.remove_item')"
                    @click="remove"
                >
                    <Trash2 class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Recording -->
        <form v-if="editable" class="space-y-4" @submit.prevent="save">
            <p v-if="item.checklist.length === 0" class="text-xs text-muted-foreground">{{ t('pm_visits.no_checklist') }}</p>
            <div v-for="(section, s) in sections" :key="s" class="space-y-2">
                <h4 v-if="section.title" class="border-b pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ section.title }}
                </h4>
                <div
                    v-for="q in section.fields"
                    :key="q.key"
                    class="grid gap-1 rounded-md px-2 py-1.5"
                    :class="{ 'bg-red-50 dark:bg-red-950/30': failures.some((f) => f === q.label || f.startsWith(q.label + ' \u2013')) }"
                >
                    <PmField
                        v-model="form.answers[q.key]"
                        :field="q"
                        mode="edit"
                        :answers="form.answers"
                        :id-prefix="`answer_${item.id}`"
                        :asset="assetValues"
                    />

                    <div class="flex flex-wrap items-center gap-3 text-xs">
                        <button
                            v-if="!remarkOpen[q.key] && q.type !== 'photo'"
                            type="button"
                            class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
                            @click="remarkOpen[q.key] = true"
                        >
                            <MessageSquarePlus class="h-3.5 w-3.5" />{{ t('pm_visits.add_remark') }}
                        </button>
                        <label class="inline-flex cursor-pointer items-center gap-1 text-muted-foreground hover:text-foreground">
                            <Camera class="h-3.5 w-3.5" />{{ t('pm_visits.question_photo') }}
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                multiple
                                class="hidden"
                                @change="uploadPhotos($event, q.key)"
                            />
                        </label>
                    </div>
                    <Input
                        v-if="remarkOpen[q.key]"
                        v-model="form.remarks[q.key]"
                        :placeholder="t('pm_visits.remark_placeholder')"
                        class="h-8 text-sm"
                    />
                    <div v-if="photosOf(q.key).length" class="flex flex-wrap gap-2">
                        <div v-for="p in photosOf(q.key)" :key="p.id" class="relative">
                            <a :href="p.url" target="_blank" rel="noopener"
                                ><img :src="p.url" :alt="p.name" loading="lazy" class="h-16 w-16 rounded border object-cover"
                            /></a>
                            <button
                                type="button"
                                class="absolute right-0.5 top-0.5 rounded-full bg-background/90 p-0.5 text-destructive shadow"
                                :aria-label="t('pm_visits.delete_photo')"
                                @click="deletePhoto(p.id)"
                            >
                                <X class="h-3 w-3" />
                            </button>
                        </div>
                    </div>
                    <InputError :message="errorOf(`answers.${q.key}`) ?? firstErrorUnder(`answers.${q.key}.`) ?? errorOf(`remarks.${q.key}`)" />
                </div>
            </div>
            <InputError :message="errorOf('answers')" />

            <p v-if="failures.length" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                {{ t('pm_visits.failures_hint', { items: failures.join(', ') }) }}
            </p>

            <div class="flex flex-wrap gap-4">
                <label v-for="result in results" :key="result" class="flex items-center gap-2 text-sm">
                    <input v-model="form.result" type="radio" :value="result" class="size-4" />
                    {{ t(`pm_visits.results.${result}`) }}
                </label>
            </div>
            <InputError :message="form.errors.result" />

            <div class="grid gap-1">
                <label :for="`note_${item.id}`" class="text-sm">{{
                    form.result === 'failed' ? t('pm_visits.note_issue') : t('pm_visits.note')
                }}</label>
                <textarea
                    :id="`note_${item.id}`"
                    v-model="form.note"
                    rows="2"
                    class="shadow-xs rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    :required="form.result === 'failed'"
                />
                <InputError :message="form.errors.note" />
            </div>

            <Button size="sm" :disabled="form.processing">{{ t('pm_visits.save_item') }}</Button>
        </form>

        <!-- Read only -->
        <template v-else-if="item.result !== 'pending'">
            <div v-for="(section, s) in sections" :key="s" class="space-y-1">
                <h4 v-if="section.title" class="border-b pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ section.title }}
                </h4>
                <div v-for="q in section.fields" :key="q.key" class="border-b py-1">
                    <PmField
                        :model-value="item.answers[q.key] ?? null"
                        :field="q"
                        mode="view"
                        :answers="item.answers"
                        :id-prefix="`view_${item.id}`"
                    />
                    <p v-if="item.remarks?.[q.key]" class="text-xs text-muted-foreground">{{ item.remarks[q.key] }}</p>
                </div>
            </div>
            <p v-if="item.note" class="whitespace-pre-line text-sm">{{ item.note }}</p>
        </template>

        <!-- Photos of the equipment (those of a question are shown with it while recording) -->
        <div v-if="item.photos.length || editable" class="space-y-2">
            <div v-if="(editable ? photosOf(null) : item.photos).length" class="flex flex-wrap gap-2">
                <div v-for="p in editable ? photosOf(null) : item.photos" :key="p.id" class="relative">
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
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    capture="environment"
                    multiple
                    class="hidden"
                    @change="uploadPhotos($event)"
                />
            </label>
            <InputError :message="(photo.errors as Record<string, string>).photo" />
        </div>

        <!-- A found issue can become a repair ticket -->
        <div v-if="canOpenTicket && item.result === 'failed' && !item.ticket" class="flex flex-wrap items-center gap-2 border-t pt-3">
            <select v-model="ticket.priority" :class="selectClass" :aria-label="t('tickets.priority')">
                <option v-for="priority in priorities" :key="priority" :value="priority">{{ t(`tickets.priorities.${priority}`) }}</option>
            </select>
            <Button size="sm" variant="outline" :disabled="ticket.processing" @click="openTicket">{{ t('pm_visits.open_ticket') }}</Button>
            <InputError :message="(ticket.errors as Record<string, string>).item ?? ticket.errors.priority" />
        </div>
    </div>
</template>
