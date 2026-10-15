import { serverDraftStore, type DraftStore, type PmItemDraft } from '@/lib/pmDraftStore';
import { failures as failuresOf, formAnswer, PASS_FAIL, type Answer, type TemplateField } from '@/lib/pmTemplate';
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

export type PmItemForForm = {
    id: number;
    result: string;
    answers: Record<string, Answer>;
    remarks: Record<string, string> | null;
    note: string | null;
    checklist: TemplateField[];
    draft?: (PmItemDraft & { saved_at?: string }) | null;
    instrument_id?: number | null;
    not_inspected_reason?: string | null;
};

/**
 * The form of one piece of equipment of a PM work order: starts from the unsaved draft when there
 * is one (else what was recorded), keeps a draft while it is edited (debounced autosave through a
 * DraftStore), and saves the result for good (PUT visits.items.update, which clears the draft).
 */
export function usePmItemForm(item: PmItemForForm, visitUlid: string, options: { autosave: boolean; store?: DraftStore; delay?: number }) {
    const store = options.store ?? serverDraftStore;
    const from = item.draft ?? null;

    const form = useForm({
        result: from?.result ?? (item.result === 'pending' ? 'passed' : item.result),
        answers: Object.fromEntries(item.checklist.map((f) => [f.key, formAnswer(f, from ? from.answers?.[f.key] : item.answers[f.key])])) as Record<
            string,
            Answer
        >,
        remarks: Object.fromEntries(item.checklist.map((f) => [f.key, (from?.remarks ?? item.remarks ?? {})[f.key] ?? ''])) as Record<string, string>,
        note: (from ? from.note : item.note) ?? '',
        not_inspected_reason: (from ? from.not_inspected_reason : item.not_inspected_reason) ?? null,
        instrument_id: (from ? from.instrument_id : item.instrument_id) ?? null,
    });

    const failures = computed(() => failuresOf(item.checklist, form.answers));

    /** "ปกติทั้งหมด": every Pass/Fail field still empty becomes Pass (a table's Pass/Fail cells too). */
    const passAll = () => {
        for (const field of item.checklist) {
            if (field.type === 'pass_fail' && !PASS_FAIL.includes(form.answers[field.key] as never)) form.answers[field.key] = 'pass';
        }
        if (failures.value.length === 0 && form.result !== 'not_inspected') form.result = 'passed';
    };

    // --- autosave ---
    const draftState = ref<'idle' | 'saving' | 'saved' | 'failed'>(from?.saved_at ? 'saved' : 'idle');
    const draftSavedAt = ref<string | null>(from?.saved_at ?? null);
    const restoredDraft = from?.saved_at ?? null;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const saveDraft = async () => {
        timer = null;
        draftState.value = 'saving';
        try {
            draftSavedAt.value = await store.save({ visitUlid, itemId: item.id }, form.data() as PmItemDraft);
            draftState.value = 'saved';
        } catch {
            draftState.value = 'failed';
        }
    };

    if (options.autosave) {
        watch(
            () => form.data(),
            () => {
                if (form.processing) return;
                if (timer) clearTimeout(timer);
                timer = setTimeout(saveDraft, options.delay ?? 1500);
            },
            { deep: true },
        );
    }
    const cancelDraft = () => {
        if (timer) clearTimeout(timer);
        timer = null;
    };
    onBeforeUnmount(cancelDraft);

    /** Records the result for good; the server clears the draft. */
    const save = (onSuccess?: () => void) => {
        cancelDraft();
        form.put(route('maintenance.visits.items.update', [visitUlid, item.id]), {
            preserveScroll: true,
            onSuccess: () => {
                draftState.value = 'idle';
                draftSavedAt.value = null;
                onSuccess?.();
            },
        });
    };

    return { form, failures, passAll, save, draftState, draftSavedAt, restoredDraft };
}
